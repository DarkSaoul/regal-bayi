<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$m = $pdo->prepare("SELECT * FROM musteriler WHERE id=?");
$m->execute([$id]);
$m = $m->fetch();
if (!$m) { flash('hata','Müşteri bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = 'Müşteri Düzenle';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $pdo->prepare("UPDATE musteriler SET tip=?,ad=?,soyad=?,firma_adi=?,tc_no=?,vergi_no=?,telefon=?,telefon2=?,email=?,adres=?,sehir=?,notlar=? WHERE id=?")
        ->execute([$d['tip']??'bireysel', $d['ad'], $d['soyad']??'', $d['firma_adi']??'', $d['tc_no']??'', $d['vergi_no']??'', $d['telefon']??'', $d['telefon2']??'', $d['email']??'', $d['adres']??'', $d['sehir']??'', $d['notlar']??'', $id]);
    flash('basari', 'Müşteri güncellendi.');
    header('Location: detay.php?id=' . $id); exit;
}
$d = $m;
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-pencil text-primary"></i> Müşteri Düzenle</h4>
</div>
<div class="card shadow-sm">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-12">
                <div class="d-flex gap-3">
                    <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="bireysel" <?= $d['tip']==='bireysel'?'checked':'' ?>><label class="form-check-label">Bireysel</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="kurumsal" <?= $d['tip']==='kurumsal'?'checked':'' ?>><label class="form-check-label">Kurumsal</label></div>
                </div>
            </div>
            <div class="col-md-4"><label class="form-label fw-semibold">Ad *</label><input type="text" name="ad" class="form-control" required value="<?= escH($d['ad']) ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Soyad</label><input type="text" name="soyad" class="form-control" value="<?= escH($d['soyad']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Firma Adı</label><input type="text" name="firma_adi" class="form-control" value="<?= escH($d['firma_adi']??'') ?>"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">T.C. No</label><input type="text" name="tc_no" class="form-control" value="<?= escH($d['tc_no']??'') ?>"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Vergi No</label><input type="text" name="vergi_no" class="form-control" value="<?= escH($d['vergi_no']??'') ?>"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Telefon</label><input type="text" name="telefon" class="form-control" value="<?= escH($d['telefon']??'') ?>"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Telefon 2</label><input type="text" name="telefon2" class="form-control" value="<?= escH($d['telefon2']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">E-posta</label><input type="email" name="email" class="form-control" value="<?= escH($d['email']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Şehir</label><input type="text" name="sehir" class="form-control" value="<?= escH($d['sehir']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Adres</label><textarea name="adres" class="form-control" rows="1"><?= escH($d['adres']??'') ?></textarea></div>
            <div class="col-12"><label class="form-label fw-semibold">Notlar</label><textarea name="notlar" class="form-control" rows="2"><?= escH($d['notlar']??'') ?></textarea></div>
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
