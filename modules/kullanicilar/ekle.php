<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Yeni Kullanıcı';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $email = trim($d['email'] ?? '');
    $rol   = in_array($d['rol'] ?? '', ['yonetici','kasiyer','depo']) ? $d['rol'] : 'kasiyer';
    $sifreHata = sifreDogrula($d['sifre'] ?? '');
    if ($sifreHata) {
        $hata = $sifreHata;
    } elseif (!trim($d['kullanici_adi'] ?? '') || !trim($d['ad_soyad'] ?? '')) {
        $hata = 'Kullanıcı adı ve ad soyad zorunludur.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $hata = 'Geçerli bir e-posta adresi girin.';
    } else {
        try {
            $pdo->prepare("INSERT INTO kullanicilar (kullanici_adi,sifre,ad_soyad,email,rol) VALUES (?,?,?,?,?)")
                ->execute([trim($d['kullanici_adi']), password_hash($d['sifre'], PASSWORD_DEFAULT), trim($d['ad_soyad']), $email, $rol]);
            flash('basari','Kullanıcı eklendi.');
            header('Location: index.php'); exit;
        } catch (Exception $e) {
            error_log($e->getMessage());
            $hata = 'Bu kullanıcı adı zaten kullanımda.';
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><h4><i class="bi bi-person-plus text-primary"></i> Yeni Kullanıcı</h4></div>
<?php if (!empty($hata)): ?><div class="alert alert-danger"><?= escH($hata) ?></div><?php endif; ?>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="mb-3"><label class="form-label fw-semibold">Kullanıcı Adı *</label><input type="text" name="kullanici_adi" class="form-control" required value="<?= escH($_POST['kullanici_adi']??'') ?>" maxlength="50"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Ad Soyad *</label><input type="text" name="ad_soyad" class="form-control" required value="<?= escH($_POST['ad_soyad']??'') ?>" maxlength="100"></div>
        <div class="mb-3"><label class="form-label fw-semibold">E-posta</label><input type="email" name="email" class="form-control" value="<?= escH($_POST['email']??'') ?>" maxlength="100"></div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Şifre * <small class="text-muted">(min. 8 kar., büyük+küçük harf+rakam)</small></label>
            <input type="password" name="sifre" class="form-control" required minlength="8">
        </div>
        <div class="mb-3"><label class="form-label fw-semibold">Rol</label>
            <select name="rol" class="form-select">
                <option value="kasiyer">Kasiyer</option>
                <option value="depo">Depo Görevlisi</option>
                <option value="yonetici">Yönetici</option>
            </select>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
