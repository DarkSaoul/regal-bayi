<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Kasa Hareketi';
$pdo = db();
$rol = $_SESSION['rol'] ?? '';

// Satış/Tahsilat sistem tarafından otomatik üretilir; elle seçilemez.
$kategoriler = $pdo->query("SELECT * FROM kasa_kategoriler WHERE aktif=1 AND sistem=0 ORDER BY sira, ad")->fetchAll();
$gonderilenOnayLimiti = (float)ayar('gider_onay_limiti', '0');

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $tarih = $_POST['tarih'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || strtotime($tarih) === false) $tarih = date('Y-m-d');
    $tip      = in_array($_POST['tip'] ?? '', ['giris','cikis'], true) ? $_POST['tip'] : 'giris';
    $hesap    = in_array($_POST['hesap'] ?? '', ['kasa','banka'], true) ? $_POST['hesap'] : 'kasa';
    $tutar    = round((float)($_POST['tutar'] ?? 0), 2);
    $kategoriId = (int)($_POST['kategori_id'] ?? 0);

    $kategoriRow = null;
    foreach ($kategoriler as $k) if ((int)$k['id'] === $kategoriId) { $kategoriRow = $k; break; }

    if ($tutar <= 0) {
        $hata = 'Tutar 0\'dan büyük olmalıdır.';
    } elseif (!$kategoriRow) {
        $hata = 'Geçerli bir kategori seçin.';
    } else {
        try {
            $belge = giderBelgesiYukle($_FILES['belge'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        } catch (Exception $e) {
            $hata = $e->getMessage();
        }
        if (!$hata) {
            // Kasiyer için büyük tutarlı ÇIKIŞLAR onay bekler; yönetici her zaman doğrudan onaylı girer.
            $onayGerekli = $tip === 'cikis' && $rol === 'kasiyer'
                && $gonderilenOnayLimiti > 0 && $tutar > $gonderilenOnayLimiti;
            $onayDurumu = $onayGerekli ? 'bekliyor' : 'onaylandi';
            $onaylayan  = $onayGerekli ? null : $_SESSION['kullanici_id'];

            $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,hesap,tutar,aciklama,kategori,belge,onay_durumu,onaylayan_id,kullanici_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$tarih, $tip, $hesap, $tutar, trim($_POST['aciklama']??''), $kategoriRow['ad'], $belge, $onayDurumu, $onaylayan, $_SESSION['kullanici_id']]);
            $yeniId = (int)$pdo->lastInsertId();
            logla('kasa_hareket', 'finans', $yeniId, "$tip | $hesap | " . para($tutar) . " | {$kategoriRow['ad']}" . ($onayGerekli ? ' | ONAY BEKLİYOR' : ''));
            flash('basari', $onayGerekli
                ? 'Gider kaydedildi — tutar onay limitini aştığı için yönetici onayı bekliyor, onaylanana kadar kasa bakiyesine yansımayacak.'
                : 'Kasa hareketi eklendi.');
            header('Location: index.php'); exit;
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><h4><i class="bi bi-plus-circle text-primary"></i> Kasa Hareketi Ekle</h4></div>

<?php if ($hata): ?>
<div class="alert alert-danger" style="max-width:500px"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label fw-semibold">Hareket Tipi</label>
            <div class="d-flex gap-3">
                <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="giris" id="tipGiris" checked><label class="form-check-label text-success" for="tipGiris">Giriş (Gelir)</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="tip" value="cikis" id="tipCikis"><label class="form-check-label text-danger" for="tipCikis">Çıkış (Gider)</label></div>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Hesap</label>
            <select name="hesap" class="form-select">
                <option value="kasa">💵 Kasa (Nakit)</option>
                <option value="banka">🏦 Banka</option>
            </select>
            <div class="form-text">Kart/havale ile yapılan gider ödemeleri "Banka" hesabından seçilir.</div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tutar (₺) *</label>
            <input type="number" name="tutar" class="form-control" step="0.01" min="0.01" required>
            <?php if ($gonderilenOnayLimiti > 0): ?>
            <div class="form-text">Kasiyer için <?= para($gonderilenOnayLimiti) ?> üzeri çıkışlar yönetici onayı bekler.</div>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Kategori</label>
            <select name="kategori_id" class="form-select" required>
                <?php foreach ($kategoriler as $kat): ?>
                <option value="<?= $kat['id'] ?>"><?= escH($kat['ad']) ?><?= $kat['aylik_limit'] ? ' (aylık limit: '.para($kat['aylik_limit']).')' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Satış ve tahsilat kayıtları sistem tarafından otomatik oluşturulur. Kategori yönetimi: <a href="kategoriler.php">Kategoriler</a></div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Açıklama</label>
            <input type="text" name="aciklama" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Gider Belgesi <span class="text-muted">(fiş/fatura, opsiyonel)</span></label>
            <input type="file" name="belge" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
            <div class="form-text">JPG, PNG, WEBP veya PDF — en fazla 5 MB.</div>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
