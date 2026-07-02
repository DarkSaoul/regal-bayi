<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Kasa Hareketi';
$pdo = db();

// 'Satış' ve 'Tahsilat' kategorileri sistem tarafından üretilir; elle
// eklenmesine izin verilmez (raporlar ve silme koruması bunlara dayanır).
$izinliKategoriler = ['Kira', 'Elektrik/Su', 'Personel', 'Tedarikçi Ödemesi', 'İade', 'Diğer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $tarih = $_POST['tarih'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || strtotime($tarih) === false) $tarih = date('Y-m-d');
    $tip      = in_array($_POST['tip'] ?? '', ['giris','cikis']) ? $_POST['tip'] : 'giris';
    $tutar    = round((float)($_POST['tutar'] ?? 0), 2);
    $kategori = in_array(trim($_POST['kategori'] ?? ''), $izinliKategoriler) ? trim($_POST['kategori']) : 'Diğer';

    if ($tutar <= 0) {
        flash('hata', 'Tutar 0\'dan büyük olmalıdır.');
        header('Location: kasa_hareketi.php'); exit;
    }

    $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?)")
        ->execute([$tarih, $tip, $tutar, trim($_POST['aciklama']??''), $kategori, $_SESSION['kullanici_id']]);
    logla('kasa_hareket', 'finans', 0, "$tip | " . para($tutar) . " | $kategori");
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
                <?php foreach ($izinliKategoriler as $kat): ?>
                <option value="<?= escH($kat) ?>"><?= escH($kat) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Satış ve tahsilat kayıtları sistem tarafından otomatik oluşturulur.</div>
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
