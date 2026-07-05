<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$h = $pdo->prepare("SELECT k.*, (SELECT sistem FROM kasa_kategoriler WHERE ad=k.kategori) AS kategori_sistem FROM kasa_hareketleri k WHERE k.id=?");
$h->execute([$id]); $h = $h->fetch();
if (!$h) { flash('hata', 'Kasa hareketi bulunamadı.'); header('Location: index.php'); exit; }
if ($h['kategori_sistem']) { flash('hata', 'Satış ve tahsilat kaynaklı hareketler düzenlenemez.'); header('Location: index.php'); exit; }

$kategoriler = $pdo->query("SELECT * FROM kasa_kategoriler WHERE aktif=1 AND sistem=0 ORDER BY sira, ad")->fetchAll();
$sayfa_basligi = 'Kasa Hareketi Düzenle';

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $tarih = $_POST['tarih'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || strtotime($tarih) === false) $tarih = $h['tarih'];
    $tip = in_array($_POST['tip'] ?? '', ['giris','cikis'], true) ? $_POST['tip'] : $h['tip'];
    $hesap = in_array($_POST['hesap'] ?? '', ['kasa','banka'], true) ? $_POST['hesap'] : $h['hesap'];
    $tutar = round((float)($_POST['tutar'] ?? 0), 2);
    $kategoriId = (int)($_POST['kategori_id'] ?? 0);
    $kategoriRow = null;
    foreach ($kategoriler as $k) if ((int)$k['id'] === $kategoriId) { $kategoriRow = $k; break; }

    if ($tutar <= 0) {
        $hata = 'Tutar 0\'dan büyük olmalıdır.';
    } elseif (!$kategoriRow) {
        $hata = 'Geçerli bir kategori seçin.';
    } else {
        $pdo->prepare("UPDATE kasa_hareketleri SET tarih=?, tip=?, hesap=?, tutar=?, kategori=?, aciklama=? WHERE id=?")
            ->execute([$tarih, $tip, $hesap, $tutar, $kategoriRow['ad'], trim($_POST['aciklama'] ?? ''), $id]);
        logla('kasa_duzenle', 'finans', $id, "$tip | $hesap | " . para($tutar) . " | {$kategoriRow['ad']}");
        flash('basari', 'Kasa hareketi güncellendi.');
        header('Location: index.php'); exit;
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><h4><i class="bi bi-pencil text-primary"></i> Kasa Hareketi Düzenle</h4></div>

<?php if ($hata): ?>
<div class="alert alert-danger" style="max-width:500px"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="mb-3">
            <label class="form-label fw-semibold">Hareket Tipi</label>
            <div class="d-flex gap-3">
                <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="giris" id="tG" <?= $h['tip']==='giris'?'checked':'' ?>><label class="form-check-label text-success" for="tG">Giriş</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="cikis" id="tC" <?= $h['tip']==='cikis'?'checked':'' ?>><label class="form-check-label text-danger" for="tC">Çıkış</label></div>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Hesap</label>
            <select name="hesap" class="form-select">
                <option value="kasa" <?= $h['hesap']==='kasa'?'selected':'' ?>>💵 Kasa (Nakit)</option>
                <option value="banka" <?= $h['hesap']==='banka'?'selected':'' ?>>🏦 Banka</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tutar (₺) *</label>
            <input type="number" name="tutar" class="form-control" step="0.01" min="0.01" required value="<?= $h['tutar'] ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= $h['tarih'] ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Kategori</label>
            <select name="kategori_id" class="form-select" required>
                <?php foreach ($kategoriler as $kat): ?>
                <option value="<?= $kat['id'] ?>" <?= $kat['ad']===$h['kategori']?'selected':'' ?>><?= escH($kat['ad']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Açıklama</label>
            <input type="text" name="aciklama" class="form-control" value="<?= escH($h['aciklama'] ?? '') ?>">
        </div>
        <?php if ($h['belge']): ?>
        <div class="mb-3">
            <label class="form-label fw-semibold d-block">Gider Belgesi</label>
            <a href="<?= BASE_URL ?>/uploads/kasa/<?= escH($h['belge']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip"></i> Belgeyi Görüntüle</a>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
