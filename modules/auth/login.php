<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

startSecureSession();

if (!empty($_SESSION['kullanici_id'])) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
    exit;
}

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            bruteForceSifirla($kullanici_adi);
            // Session fixation koruması
            session_regenerate_id(true);
            $_SESSION['kullanici_id']   = $kullanici['id'];
            $_SESSION['ad_soyad']       = $kullanici['ad_soyad'];
            $_SESSION['rol']            = $kullanici['rol'];
            $_SESSION['son_aktivite']   = time();

            // Tek oturum zorunluluğu için oturum token'ı üret ve DB'ye yaz (eskisi geçersiz olur)
            $oturumToken = bin2hex(random_bytes(32));
            $_SESSION['oturum_token'] = $oturumToken;
            // Şifre geçerliliği hiç ayarlanmamışsa bu girişi baz tarih kabul et
            db()->prepare("UPDATE kullanicilar SET son_giris=NOW(), aktif_oturum_token=?, sifre_degistirilme_tarihi=COALESCE(sifre_degistirilme_tarihi,NOW()) WHERE id=?")
                ->execute([$oturumToken, $kullanici['id']]);

            logla('giris', 'auth', (int)$kullanici['id'], $kullanici['ad_soyad'] . ' giriş yaptı');
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

        bruteForceArtir($kullanici_adi);
        // Zamanlama saldırısını önle — kullanıcı bulunamasa da aynı süre bekle
        if (!$kullanici) password_verify('dummy', '$2y$10$S86zye.OhRL3CHWes2d/FuTQKYsPKPMbi76QQq7fJoAaqJO2w8Gqm');
        $hata = 'Kullanıcı adı veya şifre hatalı.';
    }
}
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
        </div>
    </div>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
