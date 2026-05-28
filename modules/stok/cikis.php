<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Stok Çıkış (Fire/İade)';
$pdo = db();
$urunler = $pdo->query("SELECT id, kod, ad, stok_adedi FROM urunler WHERE aktif=1 ORDER BY ad")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $uid    = (int)$_POST['urun_id'];
    $miktar = (int)$_POST['miktar'];
    $tip    = in_array($_POST['tip'], ['fire','iade_giris']) ? $_POST['tip'] : 'fire';
    $aciklama = trim($_POST['aciklama'] ?? '');

    $stmtM = $pdo->prepare("SELECT stok_adedi FROM urunler WHERE id=?");
    $stmtM->execute([$uid]);
    $mevcut = (int)$stmtM->fetchColumn();
    if ($uid > 0 && $miktar > 0 && $miktar <= $mevcut) {
        stokGuncelle($uid, -$miktar, $tip, '', $aciklama);
        flash('basari', "$miktar adet stok çıkışı yapıldı.");
        header('Location: index.php'); exit;
    }
    flash('hata', 'Geçersiz miktar veya stok yetersiz.');
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-box-arrow-up text-danger"></i> Stok Çıkış / Fire</h4>
</div>
<div class="alert alert-warning"><i class="bi bi-info-circle"></i> Bu form satış dışı stok azaltımları içindir (fire, hasar, iade vb.).</div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label fw-semibold">Ürün <span class="text-danger">*</span></label>
            <select name="urun_id" class="form-select" required>
                <option value="">Seçin...</option>
                <?php foreach ($urunler as $u): ?>
                <option value="<?= $u['id'] ?>">[<?= escH($u['kod']) ?>] <?= escH($u['ad'] ) ?> — Stok: <?= $u['stok_adedi'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Çıkış Tipi</label>
            <select name="tip" class="form-select">
                <option value="fire">Fire / Hasar</option>
                <option value="iade_giris">İade (Stoka Dön)</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Miktar <span class="text-danger">*</span></label>
            <input type="number" name="miktar" class="form-control" min="1" required value="1">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Açıklama / Neden</label>
            <input type="text" name="aciklama" class="form-control">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-danger"><i class="bi bi-dash-circle"></i> Çıkış Yap</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
