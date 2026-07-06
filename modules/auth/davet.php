<?php
// Davet linkiyle ilk şifre belirleme — kimlik doğrulaması GEREKTİRMEZ, yalnızca token doğrular
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
startSecureSession();
$pdo = db();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$kullanici = null; $hata = '';
if ($token) {
    $stmt = $pdo->prepare("SELECT * FROM kullanicilar WHERE davet_token=? AND aktif=1");
    $stmt->execute([$token]);
    $kullanici = $stmt->fetch();
}
if (!$kullanici) {
    $hata = 'Geçersiz davet linki.';
} elseif (strtotime($kullanici['davet_son_tarih']) < time()) {
    $hata = 'Bu davet linkinin süresi dolmuş. Yöneticinizden yeni bir davet linki isteyin.';
}

$basarili = false;
if (!$hata && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrfToken(), $_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
    } else {
        $yeni = $_POST['sifre'] ?? ''; $tekrar = $_POST['sifre_tekrar'] ?? '';
        $sifreHata = sifreDogrula($yeni);
        if ($sifreHata) {
            $hata = $sifreHata;
        } elseif ($yeni !== $tekrar) {
            $hata = 'Şifreler eşleşmiyor.';
        } else {
            $pdo->prepare("UPDATE kullanicilar SET sifre=?, sifre_degistirilme_tarihi=NOW(), davet_token=NULL, davet_son_tarih=NULL WHERE id=?")
                ->execute([password_hash($yeni, PASSWORD_DEFAULT), $kullanici['id']]);
            logla('davet_sifre_belirlendi', 'kullanicilar', (int)$kullanici['id'], 'Davet linkiyle ilk şifre belirlendi');
            $basarili = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hesap Kurulumu | <?= escH(ayar('firma_adi','Regal Bayi')) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>body { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); min-height: 100vh; display: flex; align-items: center; }
.login-card { border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }</style>
</head>
<body>
<div class="container"><div class="row justify-content-center"><div class="col-md-4 col-sm-8">
    <div class="login-card bg-white overflow-hidden p-4">
        <h4 class="fw-bold text-center mb-3"><i class="bi bi-shield-lock text-primary"></i> Hesap Kurulumu</h4>
        <?php if ($basarili): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle"></i> Şifreniz belirlendi. Artık giriş yapabilirsiniz.</div>
        <a href="login.php" class="btn btn-primary w-100">Giriş Sayfasına Git</a>
        <?php elseif ($hata && !$kullanici): ?>
        <div class="alert alert-danger"><?= escH($hata) ?></div>
        <a href="login.php" class="btn btn-outline-secondary w-100">Giriş Sayfasına Dön</a>
        <?php else: ?>
        <p class="text-muted small text-center">Merhaba <strong><?= escH($kullanici['ad_soyad']) ?></strong>, devam etmek için bir şifre belirleyin.</p>
        <?php if ($hata): ?><div class="alert alert-danger py-2"><?= escH($hata) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="token" value="<?= escH($token) ?>">
            <div class="mb-3">
                <label class="form-label fw-semibold">Yeni Şifre <small class="text-muted">(min. 8 kar., büyük+küçük+rakam)</small></label>
                <input type="password" name="sifre" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Şifre Tekrar</label>
                <input type="password" name="sifre_tekrar" class="form-control" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary w-100 fw-semibold"><i class="bi bi-check-circle"></i> Şifreyi Belirle</button>
        </form>
        <?php endif; ?>
    </div>
</div></div></div>
</body>
</html>
