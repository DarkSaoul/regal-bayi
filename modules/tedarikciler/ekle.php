<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Yeni Tedarikçi';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    if (empty(trim($d['ad'] ?? ''))) {
        $hata = 'Firma adı zorunludur.';
    } else {
        $pdo->prepare("INSERT INTO tedarikciler (ad, yetkili, telefon, email, adres, vergi_no, vergi_dairesi, iban, notlar) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([
                trim($d['ad']), trim($d['yetkili'] ?? ''), trim($d['telefon'] ?? ''),
                trim($d['email'] ?? ''), trim($d['adres'] ?? ''),
                trim($d['vergi_no'] ?? ''), trim($d['vergi_dairesi'] ?? ''),
                strtoupper(str_replace(' ', '', trim($d['iban'] ?? ''))), trim($d['notlar'] ?? '')
            ]);
        flash('basari', '"' . trim($d['ad']) . '" tedarikçi olarak eklendi.');
        header('Location: index.php'); exit;
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-plus-circle text-primary"></i> Yeni Tedarikçi</h4>
</div>

<?php if (!empty($hata)): ?>
<div class="alert alert-danger"><?= escH($hata) ?></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:700px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label fw-semibold">Firma Adı <span class="text-danger">*</span></label>
                <input type="text" name="ad" class="form-control" required
                       value="<?= escH($_POST['ad'] ?? '') ?>" autofocus>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Vergi No</label>
                <input type="text" name="vergi_no" class="form-control"
                       value="<?= escH($_POST['vergi_no'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Yetkili Kişi</label>
                <input type="text" name="yetkili" class="form-control"
                       value="<?= escH($_POST['yetkili'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Telefon</label>
                <input type="text" name="telefon" class="form-control"
                       value="<?= escH($_POST['telefon'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">E-posta</label>
                <input type="email" name="email" class="form-control"
                       value="<?= escH($_POST['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Vergi Dairesi</label>
                <input type="text" name="vergi_dairesi" class="form-control"
                       value="<?= escH($_POST['vergi_dairesi'] ?? '') ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">Adres</label>
                <input type="text" name="adres" class="form-control"
                       value="<?= escH($_POST['adres'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">IBAN <small class="text-muted">(havale için)</small></label>
                <input type="text" name="iban" class="form-control" maxlength="34"
                       placeholder="TR.." value="<?= escH($_POST['iban'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Notlar</label>
                <textarea name="notlar" class="form-control" rows="3"
                          placeholder="Ödeme koşulları, teslimat süresi vb..."><?= escH($_POST['notlar'] ?? '') ?></textarea>
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
