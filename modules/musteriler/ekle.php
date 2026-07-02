<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Yeni Müşteri';
$pdo = db();

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $email = trim($d['email'] ?? '');
    if (!trim($d['ad'] ?? '')) {
        $hata = 'Ad alanı zorunludur.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $hata = 'Geçerli bir e-posta adresi girin.';
    } else {
        $pdo->prepare("INSERT INTO musteriler (tip,ad,soyad,firma_adi,tc_no,vergi_no,telefon,telefon2,email,adres,sehir,notlar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$d['tip']??'bireysel', trim($d['ad']), $d['soyad']??'', $d['firma_adi']??'', $d['tc_no']??'', $d['vergi_no']??'', $d['telefon']??'', $d['telefon2']??'', $email, $d['adres']??'', $d['sehir']??'', $d['notlar']??'']);
        $yeni_id = $pdo->lastInsertId();
        flash('basari', 'Müşteri kaydedildi.');
        // Eğer satıştan gelindiyse geri dön
        if (!empty($_GET['geri']) && str_starts_with($_GET['geri'], '/regal/')) {
            header('Location: ' . $_GET['geri'] . '&musteri_id=' . $yeni_id); exit;
        }
        header('Location: index.php'); exit;
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-person-plus text-primary"></i> Yeni Müşteri</h4>
</div>
<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>
<div class="card shadow-sm">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label fw-semibold">Müşteri Tipi</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tip" value="bireysel" id="tip1" <?= ($_POST['tip']??'bireysel')==='bireysel'?'checked':'' ?>>
                        <label class="form-check-label" for="tip1">Bireysel</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tip" value="kurumsal" id="tip2" <?= ($_POST['tip']??'')==='kurumsal'?'checked':'' ?>>
                        <label class="form-check-label" for="tip2">Kurumsal</label>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Ad <span class="text-danger">*</span></label>
                <input type="text" name="ad" class="form-control" required value="<?= escH($_POST['ad']??'') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Soyad</label>
                <input type="text" name="soyad" class="form-control" value="<?= escH($_POST['soyad']??'') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Firma Adı</label>
                <input type="text" name="firma_adi" class="form-control" value="<?= escH($_POST['firma_adi']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">T.C. No</label>
                <input type="text" name="tc_no" class="form-control" maxlength="11" value="<?= escH($_POST['tc_no']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Vergi No</label>
                <input type="text" name="vergi_no" class="form-control" value="<?= escH($_POST['vergi_no']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Telefon</label>
                <input type="text" name="telefon" class="form-control" value="<?= escH($_POST['telefon']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Telefon 2</label>
                <input type="text" name="telefon2" class="form-control" value="<?= escH($_POST['telefon2']??'') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">E-posta</label>
                <input type="email" name="email" class="form-control" value="<?= escH($_POST['email']??'') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Şehir</label>
                <input type="text" name="sehir" class="form-control" value="<?= escH($_POST['sehir']??'') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Adres</label>
                <textarea name="adres" class="form-control" rows="1"><?= escH($_POST['adres']??'') ?></textarea>
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Notlar</label>
                <textarea name="notlar" class="form-control" rows="2"><?= escH($_POST['notlar']??'') ?></textarea>
            </div>
        </div>
        <hr>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
