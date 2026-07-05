<?php
// 80mm termal fiş çıktısı
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$satis = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.telefon AS musteri_tel FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id WHERE s.id=?");
$satis->execute([$id]); $satis = $satis->fetch();
if (!$satis) { echo 'Satış bulunamadı.'; exit; }

$kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.satis_id=?");
$kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$satisUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/modules/satislar/detay.php?id=' . $id;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Fiş <?= escH($satis['fatura_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Courier New', monospace; font-size: 12px; color:#000; background:#ccc; }
.no-print { text-align:center; padding:10px; background:#fff; }
.no-print button { padding:8px 20px; font-size:14px; cursor:pointer; background:#0d6efd; color:#fff; border:none; border-radius:6px; margin-right:8px; }
.no-print a { padding:8px 16px; font-size:14px; text-decoration:none; background:#f0f0f0; border-radius:6px; color:#333; }
.fis { width: 80mm; margin: 0 auto; background:#fff; padding: 8px; }
.center { text-align:center; }
.bold { font-weight:bold; }
hr { border:none; border-top:1px dashed #000; margin:6px 0; }
.satir { display:flex; justify-content:space-between; }
table { width:100%; border-collapse:collapse; font-size:11px; }
td { padding:2px 0; vertical-align:top; }
.qr { display:flex; justify-content:center; margin:8px 0; }
@media print {
    body { background:#fff; }
    .no-print { display:none !important; }
    .fis { width:100%; padding:0; }
    @page { margin: 2mm; }
}
</style>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()">🖨 Yazdır</button>
    <a href="detay.php?id=<?= $id ?>">← Satış Detayı</a>
</div>
<div class="fis">
    <?php if (ayar('firma_logo')): ?>
    <div class="center"><img src="<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('firma_logo')) ?>" style="max-height:40px;max-width:100%;object-fit:contain"></div>
    <?php endif; ?>
    <div class="center bold" style="font-size:14px"><?= escH(ayar('firma_adi','Regal Bayi')) ?></div>
    <?php if (ayar('firma_telefon')): ?><div class="center"><?= escH(ayar('firma_telefon')) ?></div><?php endif; ?>
    <?php if (ayar('firma_adres')): ?><div class="center" style="font-size:10px"><?= escH(ayar('firma_adres')) ?></div><?php endif; ?>
    <hr>
    <div class="satir"><span>Fiş No:</span><span class="bold"><?= escH($satis['fatura_no']) ?></span></div>
    <div class="satir"><span>Tarih:</span><span><?= tarih($satis['tarih']) ?> <?= date('H:i', strtotime($satis['created_at'] ?? 'now')) ?></span></div>
    <?php if ($satis['musteri_adi']): ?><div class="satir"><span>Müşteri:</span><span><?= escH($satis['musteri_adi']) ?></span></div><?php endif; ?>
    <hr>
    <table>
        <?php foreach ($kalemler as $k): ?>
        <tr>
            <td colspan="3" class="bold"><?= escH($k['urun_adi']) ?></td>
        </tr>
        <tr>
            <td><?= $k['miktar'] ?> x <?= number_format($k['birim_fiyat'],2,',','.') ?></td>
            <td></td>
            <td style="text-align:right"><?= number_format($k['toplam'],2,',','.') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <hr>
    <div class="satir"><span>Ara Toplam</span><span><?= para($satis['ara_toplam']) ?></span></div>
    <div class="satir"><span>KDV</span><span><?= para($satis['kdv_toplam']) ?></span></div>
    <?php if ($satis['indirim_toplam']>0): ?>
    <div class="satir"><span>İndirim</span><span>-<?= para($satis['indirim_toplam']) ?></span></div>
    <?php endif; ?>
    <div class="satir bold" style="font-size:14px"><span>TOPLAM</span><span><?= para($satis['genel_toplam']) ?></span></div>
    <hr>
    <div class="satir"><span>Ödeme</span><span><?= $satis['odeme_tipi']==='bolunmus'?'Bölünmüş':ucfirst(str_replace('_',' ',$satis['odeme_tipi'])) ?></span></div>
    <div class="satir"><span>Ödenen</span><span><?= para($satis['odenen_tutar']) ?></span></div>
    <?php if ($satis['kalan_tutar']>0): ?>
    <div class="satir bold"><span>Kalan</span><span><?= para($satis['kalan_tutar']) ?></span></div>
    <?php endif; ?>
    <?php if ($satis['odeme_tipi']==='taksitli' && $satis['taksit_sayisi']>1): ?>
    <div class="satir"><span>Taksit</span><span><?= $satis['taksit_sayisi'] ?> x <?= para($satis['genel_toplam']/$satis['taksit_sayisi']) ?></span></div>
    <?php endif; ?>
    <hr>
    <div class="qr"><div id="qrKutu"></div></div>
    <div class="center" style="font-size:9px">Faturayı görüntülemek için QR'ı okutun</div>
    <hr>
    <div class="center" style="font-size:10px"><?= escH(ayar('fatura_alt_not', 'Bizi tercih ettiğiniz için teşekkür ederiz.')) ?></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
if (typeof QRCode !== 'undefined') {
    QRCode.toCanvas(<?= json_encode($satisUrl) ?>, {width:120, margin:1}, function (err, canvas) {
        if (!err) document.getElementById('qrKutu').appendChild(canvas);
    });
}
</script>
</body>
</html>
