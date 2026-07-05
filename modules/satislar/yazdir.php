<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$satis = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.telefon AS musteri_tel, m.adres AS musteri_adres, m.tc_no, m.vergi_no AS musteri_vno, m.firma_adi FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id WHERE s.id=?");
$satis->execute([$id]); $satis = $satis->fetch();
if (!$satis) { echo 'Satış bulunamadı.'; exit; }

$kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod, u.marka FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.satis_id=?");
$kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();

$taksitPlani = $pdo->prepare("SELECT * FROM taksit_plani WHERE satis_id=? ORDER BY taksit_no");
$taksitPlani->execute([$id]); $taksitPlani = $taksitPlani->fetchAll();

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$satisUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/modules/satislar/detay.php?id=' . $id;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Fatura <?= escH($satis['fatura_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 12px; color: #222; background: #fff; }
.container { max-width: 780px; margin: 0 auto; padding: 20px; }
.no-print { margin-bottom: 16px; }
.no-print button { padding: 8px 20px; font-size: 14px; cursor: pointer; background: #0d6efd; color: #fff; border: none; border-radius: 6px; margin-right: 8px; }
.no-print a { padding: 8px 16px; font-size: 14px; text-decoration: none; background: #f0f0f0; border-radius: 6px; }
.header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0d6efd; padding-bottom: 12px; margin-bottom: 16px; }
.firma-adi { font-size: 20px; font-weight: bold; color: #0d6efd; }
.fatura-no { font-size: 18px; font-weight: bold; text-align: right; }
.fatura-tarih { color: #555; text-align: right; }
.taraflar { display: flex; justify-content: space-between; margin-bottom: 16px; }
.taraf { width: 48%; }
.taraf h4 { font-size: 11px; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 6px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
th { background: #0d6efd; color: #fff; padding: 6px 8px; text-align: left; font-size: 11px; }
td { padding: 5px 8px; border-bottom: 1px solid #eee; }
tr:nth-child(even) td { background: #f9f9f9; }
.totals { width: 260px; margin-left: auto; }
.totals td { padding: 4px 8px; }
.totals .grand { font-size: 14px; font-weight: bold; border-top: 2px solid #0d6efd; }
.odeme-bilgi { display: flex; gap: 20px; margin-top: 12px; padding: 10px; background: #f5f5f5; border-radius: 6px; }
.odeme-bilgi div { flex: 1; }
.odeme-bilgi label { font-size: 10px; color: #888; display: block; }
.odeme-bilgi strong { font-size: 13px; }
.taksit-table th { background: #495057; }
.footer-note { margin-top: 20px; font-size: 10px; color: #888; text-align: center; border-top: 1px solid #eee; padding-top: 10px; }
.badge-durum { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
.badge-tamam { background: #d1fae5; color: #065f46; }
.badge-bekle { background: #fef3c7; color: #92400e; }
.badge-iptal { background: #fee2e2; color: #991b1b; }
@media print {
    .no-print { display: none !important; }
    body { font-size: 11px; }
    .container { padding: 10px; }
    @page { size: <?= ayar('fatura_kagit_boyutu','A4') === '80mm' ? '80mm auto' : ayar('fatura_kagit_boyutu','A4') ?>; }
}
</style>
</head>
<body>
<div class="container">
    <div class="no-print">
        <button onclick="window.print()">🖨 Yazdır</button>
        <a href="detay.php?id=<?= $id ?>">← Satış Detayı</a>
    </div>

    <div class="header">
        <div style="display:flex;align-items:center;gap:10px">
            <?php if (ayar('firma_logo')): ?>
            <img src="<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('firma_logo')) ?>" style="max-height:50px;max-width:110px;object-fit:contain">
            <?php endif; ?>
            <div>
            <div class="firma-adi"><?= escH(ayar('firma_adi','Regal Bayi')) ?></div>
            <?php if (ayar('firma_slogan')): ?><div style="color:#555;font-size:11px"><?= escH(ayar('firma_slogan')) ?></div><?php endif; ?>
            <?php if (ayar('firma_telefon')): ?><div>Tel: <?= escH(ayar('firma_telefon')) ?></div><?php endif; ?>
            <?php if (ayar('firma_email')): ?><div><?= escH(ayar('firma_email')) ?></div><?php endif; ?>
            <?php if (ayar('firma_adres')): ?><div><?= escH(ayar('firma_adres')) ?></div><?php endif; ?>
            <?php if (ayar('firma_vergi_no')): ?><div>V.No: <?= escH(ayar('firma_vergi_no')) ?> / <?= escH(ayar('firma_vergi_daire')) ?></div><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="fatura-no">SATIŞ FATURASI<br><?= escH($satis['fatura_no']) ?></div>
            <div class="fatura-tarih">Tarih: <?= tarih($satis['tarih']) ?></div>
            <div style="margin-top:6px">
                <?php
                $d = $satis['durum'];
                $bc = $d==='tamamlandi'?'badge-tamam':($d==='iptal'?'badge-iptal':'badge-bekle');
                $bl = $d==='tamamlandi'?'Tamamlandı':($d==='iptal'?'İptal':'Bekliyor');
                ?>
                <span class="badge-durum <?= $bc ?>"><?= $bl ?></span>
            </div>
        </div>
    </div>

    <div class="taraflar">
        <div class="taraf">
            <h4>Satıcı</h4>
            <strong><?= escH(ayar('firma_adi','Regal Bayi')) ?></strong><br>
            <?php if (ayar('firma_vergi_no')): ?><?= escH(ayar('firma_vergi_daire')) ?> V.D. – <?= escH(ayar('firma_vergi_no')) ?><br><?php endif; ?>
            <?php if (ayar('firma_iban')): ?>IBAN: <?= escH(ayar('firma_iban')) ?><?php endif; ?>
        </div>
        <div class="taraf" style="text-align:right">
            <h4>Müşteri</h4>
            <strong><?= escH($satis['musteri_adi'] ?: 'Perakende Satış') ?></strong><br>
            <?php if ($satis['firma_adi']): ?><?= escH($satis['firma_adi']) ?><br><?php endif; ?>
            <?php if ($satis['musteri_vno']): ?>V.No: <?= escH($satis['musteri_vno']) ?><br><?php endif; ?>
            <?php if ($satis['tc_no']): ?>TC: <?= escH($satis['tc_no']) ?><br><?php endif; ?>
            <?php if ($satis['musteri_tel']): ?><?= escH($satis['musteri_tel']) ?><br><?php endif; ?>
            <?php if ($satis['musteri_adres']): ?><span style="font-size:10px"><?= escH($satis['musteri_adres']) ?></span><?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th><th>Ürün</th><th>Miktar</th>
                <th>Birim Fiyat</th><th>KDV %</th><th>KDV</th>
                <?php if (array_sum(array_column($kalemler,'indirim'))>0): ?><th>İndirim</th><?php endif; ?>
                <th style="text-align:right">Toplam</th>
            </tr>
        </thead>
        <tbody>
        <?php $indVar = array_sum(array_column($kalemler,'indirim'))>0; ?>
        <?php foreach ($kalemler as $i => $k): ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td><strong><?= escH($k['urun_adi']) ?></strong><br><span style="font-size:10px;color:#888"><?= escH($k['kod']) ?></span></td>
            <td><?= $k['miktar'] ?></td>
            <td><?= para($k['birim_fiyat']) ?></td>
            <td>%<?= (int)$k['kdv_orani'] ?></td>
            <td><?= para($k['kdv_tutar']) ?></td>
            <?php if ($indVar): ?><td><?= $k['indirim']>0?para($k['indirim']):'-' ?></td><?php endif; ?>
            <td style="text-align:right;font-weight:bold"><?= para($k['toplam']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Ara Toplam</td><td style="text-align:right"><?= para($satis['ara_toplam']) ?></td></tr>
        <tr><td>KDV Toplam</td><td style="text-align:right"><?= para($satis['kdv_toplam']) ?></td></tr>
        <?php if ($satis['indirim_toplam']>0): ?>
        <tr><td>İndirim</td><td style="text-align:right;color:red">- <?= para($satis['indirim_toplam']) ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td>GENEL TOPLAM</td><td style="text-align:right"><?= para($satis['genel_toplam']) ?></td></tr>
        <?php if ($satis['odenen_tutar']>0): ?>
        <tr><td style="color:#065f46">Ödenen</td><td style="text-align:right;color:#065f46"><?= para($satis['odenen_tutar']) ?></td></tr>
        <?php endif; ?>
        <?php if ($satis['kalan_tutar']>0): ?>
        <tr><td style="color:#b91c1c;font-weight:bold">Kalan</td><td style="text-align:right;color:#b91c1c;font-weight:bold"><?= para($satis['kalan_tutar']) ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="odeme-bilgi">
        <div>
            <label>Ödeme Tipi</label>
            <strong><?= ucfirst(str_replace('_',' ',$satis['odeme_tipi'])) ?></strong>
        </div>
        <?php if ($satis['odeme_tipi']==='taksitli' && $satis['taksit_sayisi']>1): ?>
        <div>
            <label>Taksit</label>
            <strong><?= $satis['taksit_sayisi'] ?> ay — <?= para($satis['genel_toplam']/$satis['taksit_sayisi']) ?>/ay</strong>
        </div>
        <?php endif; ?>
        <?php if ($satis['notlar']): ?>
        <div>
            <label>Not</label>
            <strong><?= escH($satis['notlar']) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($taksitPlani)): ?>
    <h4 style="margin:16px 0 8px;font-size:12px">TAKSİT ÖDEME TAKVİMİ</h4>
    <table class="taksit-table">
        <thead>
            <tr><th>#</th><th>Vade Tarihi</th><th>Tutar</th><th>Durum</th><th>Ödeme Tarihi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($taksitPlani as $tp): ?>
        <tr>
            <td><?= $tp['taksit_no'] ?>.</td>
            <td><?= tarih($tp['vade_tarihi']) ?></td>
            <td style="font-weight:bold"><?= para($tp['tutar']) ?></td>
            <td>
                <?php if ($tp['odendi']): ?>
                <span class="badge-durum badge-tamam">Ödendi</span>
                <?php elseif (strtotime($tp['vade_tarihi'])<time()): ?>
                <span class="badge-durum badge-iptal">Gecikmiş</span>
                <?php else: ?>
                <span class="badge-durum badge-bekle">Bekliyor</span>
                <?php endif; ?>
            </td>
            <td><?= $tp['odeme_tarihi']?tarih($tp['odeme_tarihi']):'-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (ayar('kase_imza')): ?>
    <div style="text-align:right;margin-top:16px">
        <img src="<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('kase_imza')) ?>" style="max-height:70px;max-width:160px;object-fit:contain">
    </div>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px">
        <div style="font-size:10px;color:#555;max-width:600px"><?= nl2br(escH(ayar('fatura_alt_not', ''))) ?></div>
        <div id="qrKutu"></div>
    </div>

    <div class="footer-note">
        <?= escH(ayar('firma_adi','Regal Bayi')) ?> &bull;
        <?php if(ayar('firma_telefon')): ?><?= escH(ayar('firma_telefon')) ?> &bull; <?php endif; ?>
        <?php if(ayar('firma_email')): ?><?= escH(ayar('firma_email')) ?> &bull; <?php endif; ?>
        <?php if(ayar('sosyal_instagram')): ?><?= escH(ayar('sosyal_instagram')) ?> &bull; <?php endif; ?>
        Belge tarihi: <?= date('d.m.Y H:i') ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
if (typeof QRCode !== 'undefined') {
    QRCode.toCanvas(<?= json_encode($satisUrl) ?>, {width:80, margin:1}, function (err, canvas) {
        if (!err) document.getElementById('qrKutu').appendChild(canvas);
    });
}
</script>
</body>
</html>
