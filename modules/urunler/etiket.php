<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();

$ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));
$kopya = min(20, max(1, (int)($_GET['kopya'] ?? 1)));
if (!$ids) { flash('hata', 'Etiket için ürün seçilmedi.'); header('Location: index.php'); exit; }
$ids = array_slice($ids, 0, 100);

$yerTutucu = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, kod, barkod, ad, model, satis_fiyati FROM urunler WHERE id IN ($yerTutucu) ORDER BY ad");
$stmt->execute($ids);
$urunler = $stmt->fetchAll();
if (!$urunler) { flash('hata', 'Ürün bulunamadı.'); header('Location: index.php'); exit; }

$firma = ayar('firma_adi', 'Regal Bayi');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Barkod Etiketleri</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, Helvetica, sans-serif; background:#eee; }
    .arac-cubugu { background:#212529; color:#fff; padding:10px 16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; position:sticky; top:0; }
    .arac-cubugu button, .arac-cubugu a { padding:6px 14px; border:0; border-radius:4px; cursor:pointer; font-size:14px; text-decoration:none; }
    .yazdir-btn { background:#0d6efd; color:#fff; }
    .geri-btn { background:#6c757d; color:#fff; }
    .arac-cubugu label { font-size:13px; }
    .arac-cubugu input { width:60px; padding:4px; border-radius:4px; border:0; }
    .sayfa { display:flex; flex-wrap:wrap; gap:4mm; padding:8mm; background:#fff; max-width:210mm; margin:10px auto; }
    .etiket {
        width:60mm; height:35mm; border:1px dashed #bbb; border-radius:2mm;
        padding:2mm; display:flex; flex-direction:column; align-items:center; justify-content:space-between;
        overflow:hidden; page-break-inside:avoid; background:#fff;
    }
    .etiket .firma { font-size:8pt; font-weight:bold; letter-spacing:0.5px; text-transform:uppercase; }
    .etiket .adx { font-size:8pt; text-align:center; line-height:1.15; max-height:8mm; overflow:hidden; }
    .etiket .fiyat { font-size:13pt; font-weight:bold; }
    .etiket svg { max-width:56mm; height:11mm; }
    .etiket .koddx { font-size:7pt; color:#333; }
    @media print {
        .arac-cubugu { display:none; }
        body { background:#fff; }
        .sayfa { margin:0; padding:4mm; max-width:none; }
        .etiket { border:1px solid #eee; }
    }
</style>
</head>
<body>
<div class="arac-cubugu">
    <strong><i></i>Barkod Etiketleri</strong> — <?= count($urunler) ?> ürün
    <label>Kopya/ürün: <input type="number" min="1" max="20" value="<?= $kopya ?>"
        onchange="location.href='?ids=<?= implode(',', $ids) ?>&kopya='+this.value"></label>
    <button class="yazdir-btn" onclick="window.print()">🖨 Yazdır</button>
    <a class="geri-btn" href="index.php">← Ürünler</a>
    <span id="uyari" style="color:#ffc107;font-size:13px"></span>
</div>

<div class="sayfa">
<?php foreach ($urunler as $u): ?>
<?php $veri = $u['barkod'] ?: $u['kod']; ?>
<?php for ($i = 0; $i < $kopya; $i++): ?>
    <div class="etiket">
        <div class="firma"><?= escH($firma) ?></div>
        <div class="adx"><?= escH(mb_substr($u['ad'] . ($u['model'] ? ' ' . $u['model'] : ''), 0, 60, 'UTF-8')) ?></div>
        <svg class="barkod" data-veri="<?= escH($veri) ?>"></svg>
        <div class="fiyat"><?= para($u['satis_fiyati']) ?></div>
    </div>
<?php endfor; ?>
<?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.querySelectorAll('svg.barkod').forEach(svg => {
    const veri = svg.dataset.veri;
    const secenek = { width: 1.4, height: 34, fontSize: 11, margin: 0, displayValue: true };
    try {
        // 13 haneli sayısal barkod EAN-13 olarak; check digit tutmazsa Code-128'e düşülür
        JsBarcode(svg, veri, { ...secenek, format: /^\d{13}$/.test(veri) ? 'EAN13' : 'CODE128' });
    } catch (e) {
        try { JsBarcode(svg, veri, { ...secenek, format: 'CODE128' }); }
        catch (e2) { svg.outerHTML = '<div class="koddx">' + veri + ' (barkod çizilemedi)</div>'; }
    }
});
if (typeof JsBarcode === 'undefined') {
    document.getElementById('uyari').textContent = 'Barkod kütüphanesi yüklenemedi — internet bağlantısını kontrol edin.';
}
</script>
</body>
</html>
