<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

startSecureSession();

if (!empty($_SESSION['kullanici_id'])) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
    exit;
}

if (isset($_GET['vazgec'])) {
    unset($_SESSION['totp_bekleyen_id'], $_SESSION['totp_deneme']);
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit;
}

// Giriş tamamlama adımları (şifre veya şifre+TOTP doğrulandıktan sonra ortak akış)
function girisiTamamla(array $kullanici): void {
    session_regenerate_id(true);
    $_SESSION['kullanici_id']    = $kullanici['id'];
    $_SESSION['ad_soyad']        = $kullanici['ad_soyad'];
    $_SESSION['rol']             = $kullanici['rol'];
    $_SESSION['son_aktivite']    = time();
    $_SESSION['giris_zamani']    = time();
    $_SESSION['bildirim_tercihi'] = $kullanici['bildirim_tercihi'] ?? 'varsayilan';

    // Tek oturum zorunluluğu için oturum token'ı üret ve DB'ye yaz (eskisi geçersiz olur)
    $oturumToken = bin2hex(random_bytes(32));
    $_SESSION['oturum_token'] = $oturumToken;
    // Şifre geçerliliği hiç ayarlanmamışsa bu girişi baz tarih kabul et
    db()->prepare("UPDATE kullanicilar SET son_giris=NOW(), aktif_oturum_token=?, sifre_degistirilme_tarihi=COALESCE(sifre_degistirilme_tarihi,NOW()) WHERE id=?")
        ->execute([$oturumToken, $kullanici['id']]);

    logla('giris', 'auth', (int)$kullanici['id'], $kullanici['ad_soyad'] . ' giriş yaptı' . (mesaiDisindaMi() ? ' (mesai dışı)' : ''));

    if ($kullanici['rol'] === 'yonetici') {
        // Ayarlardan gelen sıklığa göre oto-yedek: süresi geçmişse otomatik al
        $sonOtoYedek = ayar('son_oto_yedek', '');
        if (!$sonOtoYedek || (time() - strtotime($sonOtoYedek)) >= yedekSiklikGun() * 86400) {
            otomatikYedekAl();
            yedekTemizle();
        }
        // Disk kritik seviyedeyse programa bağlı kalmadan hemen eski yedekleri temizle
        $diskEsikGb = (float)ayar('disk_uyari_esik_gb', '1');
        $diskBosGb = @disk_free_space(__DIR__ . '/../..');
        if ($diskEsikGb > 0 && $diskBosGb !== false && ($diskBosGb / 1073741824) < $diskEsikGb) {
            yedekTemizle();
        }
        // Bugün henüz yedek alınmamışsa yedekleme sayfasına zorla
        if (!bugunYedekVarMi()) {
            $_SESSION['yedek_gerekli'] = true;
            header('Location: ' . BASE_URL . '/modules/yedekleme/');
            exit;
        }
    }

    // Rol bazlı giriş sonrası yönlendirme (Ayarlar → Sistem Geneli)
    $yonlendirmeAnahtari = ['yonetici' => 'giris_sonrasi_yonetici', 'kasiyer' => 'giris_sonrasi_kasiyer', 'depo' => 'giris_sonrasi_depo'][$kullanici['rol']] ?? '';
    $ozelHedef = $yonlendirmeAnahtari ? ayar($yonlendirmeAnahtari, '') : '';
    header('Location: ' . BASE_URL . ($ozelHedef ?: '/modules/dashboard/'));
    exit;
}

$hata = '';

// ── TOTP ikinci adım ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['totp_bekleyen_id']) && isset($_POST['totp_kod'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        $hata = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
    } else {
        $_SESSION['totp_deneme'] = ($_SESSION['totp_deneme'] ?? 0) + 1;
        if ($_SESSION['totp_deneme'] > 5) {
            unset($_SESSION['totp_bekleyen_id'], $_SESSION['totp_deneme']);
            $hata = 'Çok fazla hatalı kod denemesi. Lütfen tekrar giriş yapın.';
        } else {
            $stmt = db()->prepare("SELECT * FROM kullanicilar WHERE id=? AND aktif=1");
            $stmt->execute([$_SESSION['totp_bekleyen_id']]);
            $kullanici = $stmt->fetch();
            $secret = $kullanici ? aesSifreCoz((string)$kullanici['totp_gizli_anahtar']) : null;
            if ($kullanici && $secret && totpDogrula($secret, $_POST['totp_kod'])) {
                unset($_SESSION['totp_bekleyen_id'], $_SESSION['totp_deneme']);
                girisiTamamla($kullanici);
            } else {
                $hata = 'Girilen kod hatalı.';
            }
        }
    }
}
// ── Kullanıcı adı / şifre adımı ──────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kullanici_adi = trim($_POST['kullanici_adi'] ?? '');
    $sifre         = $_POST['sifre'] ?? '';
    $token         = $_POST['csrf_token'] ?? '';

    if (!hash_equals(csrfToken(), $token)) {
        $hata = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
    } elseif (!$kullanici_adi || !$sifre) {
        $hata = 'Kullanıcı adı ve şifre zorunludur.';
    } elseif (!bruteForceKontrol($kullanici_adi)) {
        $kalan = ceil(bruteForceKalanSure($kullanici_adi) / 60);
        $hata  = "Çok fazla başarısız deneme. {$kalan} dakika sonra tekrar deneyin.";
    } else {
        $stmt = db()->prepare("SELECT * FROM kullanicilar WHERE kullanici_adi=? AND aktif=1");
        $stmt->execute([$kullanici_adi]);
        $kullanici = $stmt->fetch();

        if ($kullanici && password_verify($sifre, $kullanici['sifre'])) {
            // Hesap geçerlilik tarihi dolmuş mu
            if (!empty($kullanici['hesap_gecerlilik_tarihi']) && $kullanici['hesap_gecerlilik_tarihi'] < date('Y-m-d')) {
                db()->prepare("UPDATE kullanicilar SET aktif=0 WHERE id=?")->execute([$kullanici['id']]);
                $hata = 'Hesabınızın geçerlilik süresi doldu. Yöneticinizle iletişime geçin.';
            } else {
                bruteForceSifirla($kullanici_adi);
                if ((int)$kullanici['totp_aktif'] === 1) {
                    // İkinci adıma geç — oturum henüz açılmadı
                    $_SESSION['totp_bekleyen_id'] = $kullanici['id'];
                    $_SESSION['totp_deneme'] = 0;
                    header('Location: ' . BASE_URL . '/modules/auth/login.php'); exit;
                }
                girisiTamamla($kullanici);
            }
        } else {
            bruteForceArtir($kullanici_adi);
            // Zamanlama saldırısını önle — kullanıcı bulunamasa da aynı süre bekle
            if (!$kullanici) password_verify('dummy', '$2y$10$S86zye.OhRL3CHWes2d/FuTQKYsPKPMbi76QQq7fJoAaqJO2w8Gqm');
            $hata = 'Kullanıcı adı veya şifre hatalı.';
        }
    }
}

$totpAdimindaMi = !empty($_SESSION['totp_bekleyen_id']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Giriş | <?= escH(ayar('firma_adi','Regal Bayi')) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<?php if (ayar('favicon')): ?><link rel="icon" href="<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('favicon')) ?>"><?php endif; ?>
<style>
body {
    <?php if (ayar('login_arkaplan')): ?>
    background: url('<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('login_arkaplan')) ?>') center/cover no-repeat fixed;
    <?php else: ?>
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    <?php endif; ?>
    min-height: 100vh; display: flex; align-items: center;
}
.login-card { border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.logo-area { background: #0d6efd; border-radius: 16px 16px 0 0; }
</style>
</head>
<body>
<div class="container">
<div class="row justify-content-center">
<div class="col-md-4 col-sm-8">
    <div class="login-card bg-white overflow-hidden">
        <div class="logo-area text-center text-white p-4">
            <?php if (ayar('firma_logo')): ?>
            <img src="<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('firma_logo')) ?>" style="max-height:60px;max-width:80%;object-fit:contain">
            <?php else: ?>
            <i class="bi bi-shop-window" style="font-size:3rem"></i>
            <?php endif; ?>
            <h4 class="mt-2 mb-0 fw-bold"><?= escH(ayar('firma_adi','Regal Bayi')) ?></h4>
            <small>Yönetim Sistemi</small>
        </div>
        <div class="p-4">
            <?php if ($hata): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
            <?php endif; ?>

            <?php if ($totpAdimindaMi): ?>
            <form method="post" autocomplete="off">
                <?= csrfField() ?>
                <p class="text-muted small">Authenticator uygulamanızdaki 6 haneli kodu girin.</p>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Doğrulama Kodu</label>
                    <input type="text" name="totp_kod" class="form-control text-center fs-4" required autofocus
                           maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="bi bi-shield-check"></i> Doğrula
                </button>
            </form>
            <a href="login.php?vazgec=1" class="d-block text-center small mt-3 text-muted">← Geri dön</a>
            <?php else: ?>
            <form method="post" autocomplete="off">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Kullanıcı Adı</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="kullanici_adi" class="form-control" required autofocus
                               value="<?= escH($_POST['kullanici_adi'] ?? '') ?>" maxlength="50">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Şifre</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="sifre" class="form-control" required maxlength="100">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
