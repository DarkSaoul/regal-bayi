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

    if (!$kullanici_adi || !$sifre) {
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

            logla('giris', 'auth', (int)$kullanici['id'], $kullanici['ad_soyad'] . ' giriş yaptı');
            if ($kullanici['rol'] === 'yonetici') {
                // Haftalık oto-yedek: son 7 günde alınmamışsa otomatik al
                $sonOtoYedek = ayar('son_oto_yedek', '');
                if (!$sonOtoYedek || (time() - strtotime($sonOtoYedek)) >= 7 * 86400) {
                    otomatikYedekAl();
                }
                // Bugün henüz yedek alınmamışsa yedekleme sayfasına zorla
                if (!bugunYedekVarMi()) {
                    $_SESSION['yedek_gerekli'] = true;
                    header('Location: ' . BASE_URL . '/modules/yedekleme/');
                    exit;
                }
            }

            header('Location: ' . BASE_URL . '/modules/dashboard/');
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
<title>Giriş | Regal Bayi</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
body { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); min-height: 100vh; display: flex; align-items: center; }
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
            <i class="bi bi-shop-window" style="font-size:3rem"></i>
            <h4 class="mt-2 mb-0 fw-bold">Regal Bayi</h4>
            <small>Yönetim Sistemi</small>
        </div>
        <div class="p-4">
            <?php if ($hata): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
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
