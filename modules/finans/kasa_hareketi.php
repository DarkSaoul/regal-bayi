<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Kasa Hareketi';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?)")
        ->execute([$_POST['tarih']??date('Y-m-d'), $_POST['tip']??'giris', $_POST['tutar']??0, trim($_POST['aciklama']??''), trim($_POST['kategori']??''), $_SESSION['kullanici_id']]);
    flash('basari', 'Kasa hareketi eklendi.');
    header('Location: index.php'); exit;
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><h4><i class="bi bi-plus-circle text-primary"></i> Kasa Hareketi Ekle</h4></div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label fw-semibold">Hareket Tipi</label>
            <div class="d-flex gap-3">
                <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="giris" checked><label class="form-check-label text-success">Giriş (Gelir)</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="cikis"><label class="form-check-label text-danger">Çıkış (Gider)</label></div>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tutar (₺) *</label>
            <input type="number" name="tutar" class="form-control" step="0.01" min="0.01" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Kategori</label>
            <select name="kategori" class="form-select">
                <option value="Satış">Satış</option>
                <option value="Tahsilat">Tahsilat</option>
                <option value="Kira">Kira</option>
                <option value="Elektrik/Su">Elektrik/Su</option>
                <option value="Personel">Personel</option>
                <option value="Tedarikçi Ödemesi">Tedarikçi Ödemesi</option>
                <option value="Diğer">Diğer</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Açıklama</label>
            <input type="text" name="aciklama" class="form-control">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
