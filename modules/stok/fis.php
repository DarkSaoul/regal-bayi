<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo']);
$fis = $_SESSION['stok_giris_fisi'] ?? null;
if (!$fis) { header('Location: index.php'); exit; }
$firma = ayar('firma_adi', 'Regal Bayi');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Mal Kabul Fişi</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, Helvetica, sans-serif; background:#eee; font-size:13px; }
    .arac { background:#212529; color:#fff; padding:10px 16px; display:flex; gap:12px; align-items:center; }
    .arac a, .arac button { padding:6px 14px; border:0; border-radius:4px; cursor:pointer; font-size:14px; text-decoration:none; }
    .yazdir { background:#0d6efd; color:#fff; } .geri { background:#6c757d; color:#fff; }
    .fis { background:#fff; max-width:190mm; margin:12px auto; padding:12mm; }
    h2 { font-size:18px; margin-bottom:2px; }
    .baslik { display:flex; justify-content:space-between; border-bottom:2px solid #333; padding-bottom:8px; margin-bottom:10px; }
    .bilgi { margin-bottom:12px; } .bilgi div { margin-bottom:2px; }
    table { width:100%; border-collapse:collapse; margin-bottom:12px; }
    th, td { border:1px solid #999; padding:5px 7px; text-align:left; }
    th { background:#f0f0f0; }
    .sag { text-align:right; } .orta { text-align:center; }
    tfoot td { font-weight:bold; background:#f8f8f8; }
    .imza { display:flex; justify-content:space-between; margin-top:24mm; }
    .imza div { width:60mm; border-top:1px solid #333; padding-top:4px; text-align:center; font-size:12px; }
    @media print { .arac { display:none; } body { background:#fff; } .fis { margin:0; max-width:none; } }
</style>
</head>
<body>
<div class="arac">
    <strong>Mal Kabul Fişi</strong>
    <button class="yazdir" onclick="window.print()">🖨 Yazdır</button>
    <a class="geri" href="giris.php">+ Yeni Giriş</a>
    <a class="geri" href="index.php">← Stok</a>
</div>
<div class="fis">
    <div class="baslik">
        <div><h2><?= escH($firma) ?></h2><div>MAL KABUL FİŞİ</div></div>
        <div class="sag">
            <div><strong>Tarih:</strong> <?= escH($fis['zaman']) ?></div>
            <?php if ($fis['belge']): ?><div><strong>Belge/İrsaliye:</strong> <?= escH($fis['belge']) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="bilgi">
        <?php if ($fis['tedarikci']): ?><div><strong>Tedarikçi:</strong> <?= escH($fis['tedarikci']) ?></div><?php endif; ?>
        <?php if ($fis['aciklama']): ?><div><strong>Açıklama:</strong> <?= escH($fis['aciklama']) ?></div><?php endif; ?>
        <?php if ($fis['kullanici']): ?><div><strong>Teslim Alan:</strong> <?= escH($fis['kullanici']) ?></div><?php endif; ?>
    </div>
    <table>
        <thead><tr><th style="width:24mm">Kod</th><th>Ürün</th><th class="orta" style="width:18mm">Miktar</th>
            <th class="sag" style="width:28mm">Birim Maliyet</th><th class="sag" style="width:30mm">Tutar</th>
            <th class="orta" style="width:16mm">Teşhir</th><th class="orta" style="width:14mm">Seri</th></tr></thead>
        <tbody>
        <?php foreach ($fis['satirlar'] as $s): ?>
        <tr>
            <td><?= escH($s['kod']) ?></td>
            <td><?= escH($s['ad']) ?></td>
            <td class="orta"><?= $s['miktar'] ?></td>
            <td class="sag"><?= $s['maliyet'] !== null ? para($s['maliyet']) : '—' ?></td>
            <td class="sag"><?= $s['maliyet'] !== null ? para($s['miktar'] * $s['maliyet']) : '—' ?></td>
            <td class="orta"><?= $s['tesir'] ?: '—' ?></td>
            <td class="orta"><?= $s['seri'] ?: '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="2">TOPLAM</td>
            <td class="orta"><?= $fis['toplam_adet'] ?></td>
            <td></td>
            <td class="sag"><?= $fis['toplam_tutar'] > 0 ? para($fis['toplam_tutar']) : '—' ?></td>
            <td colspan="2"></td>
        </tr></tfoot>
    </table>
    <div class="imza">
        <div>Teslim Eden</div>
        <div>Teslim Alan</div>
    </div>
</div>
</body>
</html>
