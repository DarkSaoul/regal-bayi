<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$t = $pdo->prepare("SELECT * FROM tedarikciler WHERE id=?");
$t->execute([$id]); $t = $t->fetch();
if (!$t) { flash('hata', 'Tedarikçi bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = 'Düzenle: ' . $t['ad'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    if (empty(trim($d['ad'] ?? ''))) {
        $hata = 'Firma adı zorunludur.';
    } else {
        $pdo->prepare("UPDATE tedarikciler SET ad=?, yetkili=?, telefon=?, email=?, adres=?, vergi_no=?, notlar=? WHERE id=?")
            ->execute([
                trim($d['ad']), trim($d['yetkili'] ?? ''), trim($d['telefon'] ?? ''),
                trim($d['email'] ?? ''), trim($d['adres'] ?? ''),
                trim($d['vergi_no'] ?? ''), trim($d['notlar'] ?? ''), $id
            ]);
        flash('basari', 'Tedarikçi güncellendi.');
        header('Location: detay.php?id=' . $id); exit;
    }
}
$d = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $t;
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-pencil text-primary"></i> Tedarikçi Düzenle</h4>
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
                <input type="text" name="ad" class="form-control" required value="<?= escH($d['ad']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Vergi No</label>
                <input type="text" name="vergi_no" class="form-control" value="<?= escH($d['vergi_no'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Yetkili Kişi</label>
                <input type="text" name="yetkili" class="form-control" value="<?= escH($d['yetkili'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Telefon</label>
                <input type="text" name="telefon" class="form-control" value="<?= escH($d['telefon'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">E-posta</label>
                <input type="email" name="email" class="form-control" value="<?= escH($d['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Adres</label>
                <input type="text" name="adres" class="form-control" value="<?= escH($d['adres'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Notlar</label>
                <textarea name="notlar" class="form-control" rows="3"><?= escH($d['notlar'] ?? '') ?></textarea>
            </div>
        </div>
        <hr>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Güncelle</button>
            <a href="detay.php?id=<?= $id ?>" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
