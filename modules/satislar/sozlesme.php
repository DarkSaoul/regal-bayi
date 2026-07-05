<?php
// Taksitli satış sözleşmesi — imzalı, yazdırılabilir
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$satis = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.telefon AS musteri_tel,
    m.adres AS musteri_adres, m.tc_no, m.vergi_no AS musteri_vno, m.firma_adi
    FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id WHERE s.id=?");
$satis->execute([$id]); $satis = $satis->fetch();
if (!$satis) { echo 'Satış bulunamadı.'; exit; }
if ($satis['odeme_tipi'] !== 'taksitli' || (int)$satis['taksit_sayisi'] <= 1) { echo 'Bu satış taksitli değil.'; exit; }

$kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.satis_id=?");
$kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();

$taksitPlani = $pdo->prepare("SELECT * FROM taksit_plani WHERE satis_id=? ORDER BY taksit_no");
$taksitPlani->execute([$id]); $taksitPlani = $taksitPlani->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Taksit Sözleşmesi <?= escH($satis['fatura_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 12px; color: #222; background: #fff; }
.container { max-width: 780px; margin: 0 auto; padding: 20px; }
.no-print { margin-bottom: 16px; }
.no-print button { padding: 8px 20px; font-size: 14px; cursor: pointer; background: #0d6efd; color: #fff; border: none; border-radius: 6px; margin-right: 8px; }
.no-print a { padding: 8px 16px; font-size: 14px; text-decoration: none; background: #f0f0f0; border-radius: 6px; }
h2 { text-align:center; margin-bottom: 4px; }
.alt-baslik { text-align:center; color:#555; margin-bottom:16px; font-size:11px; }
.taraflar { display:flex; justify-content:space-between; margin-bottom:16px; gap:16px; }
.taraf { width:48%; border:1px solid #ddd; border-radius:6px; padding:10px; }
.taraf h4 { font-size:11px; text-transform:uppercase; color:#888; margin-bottom:6px; }
table { width:100%; border-collapse:collapse; margin-bottom:14px; }
th { background:#0d6efd; color:#fff; padding:6px 8px; text-align:left; font-size:11px; }
td { padding:5px 8px; border-bottom:1px solid #eee; font-size:11px; }
.madde { margin-bottom:8px; font-size:11px; text-align:justify; }
.imza-alani { display:flex; justify-content:space-between; margin-top:40px; }
.imza-kutu { width:45%; text-align:center; }
.imza-cizgi { border-top:1px solid #000; margin-top:50px; padding-top:4px; }
.footer-note { margin-top:20px; font-size:10px; color:#888; text-align:center; border-top:1px solid #eee; padding-top:10px; }
@media print {
    .no-print { display:none !important; }
    body { font-size: 11px; }
    .container { padding: 10px; }
}
</style>
</head>
<body>
<div class="container">
    <div class="no-print">
        <button onclick="window.print()">🖨 Yazdır</button>
        <a href="detay.php?id=<?= $id ?>">← Satış Detayı</a>
    </div>

    <?php if (ayar('firma_logo')): ?>
    <div style="text-align:center;margin-bottom:8px"><img src="<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('firma_logo')) ?>" style="max-height:55px;max-width:160px;object-fit:contain"></div>
    <?php endif; ?>
    <h2><?= escH(ayar('firma_adi','Regal Bayi')) ?></h2>
    <div class="alt-baslik">TAKSİTLİ SATIŞ SÖZLEŞMESİ — Fatura No: <?= escH($satis['fatura_no']) ?> — <?= tarih($satis['tarih']) ?></div>

    <div class="taraflar">
        <div class="taraf">
            <h4>Satıcı</h4>
            <strong><?= escH(ayar('firma_adi','Regal Bayi')) ?></strong><br>
            <?php if (ayar('firma_adres')): ?><?= escH(ayar('firma_adres')) ?><br><?php endif; ?>
            <?php if (ayar('firma_telefon')): ?>Tel: <?= escH(ayar('firma_telefon')) ?><br><?php endif; ?>
            <?php if (ayar('firma_vergi_no')): ?><?= escH(ayar('firma_vergi_daire')) ?> V.D. – <?= escH(ayar('firma_vergi_no')) ?><?php endif; ?>
        </div>
        <div class="taraf">
            <h4>Alıcı</h4>
            <strong><?= escH($satis['musteri_adi'] ?: '-') ?></strong><br>
            <?php if ($satis['firma_adi']): ?><?= escH($satis['firma_adi']) ?><br><?php endif; ?>
            <?php if ($satis['tc_no']): ?>TC Kimlik No: <?= escH($satis['tc_no']) ?><br><?php endif; ?>
            <?php if ($satis['musteri_vno']): ?>V.No: <?= escH($satis['musteri_vno']) ?><br><?php endif; ?>
            <?php if ($satis['musteri_tel']): ?>Tel: <?= escH($satis['musteri_tel']) ?><br><?php endif; ?>
            <?php if ($satis['musteri_adres']): ?><span style="font-size:10px"><?= escH($satis['musteri_adres']) ?></span><?php endif; ?>
        </div>
    </div>

    <table>
        <thead><tr><th>#</th><th>Ürün</th><th>Miktar</th><th>Tutar</th></tr></thead>
        <tbody>
        <?php foreach ($kalemler as $i => $k): ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td><?= escH($k['urun_adi']) ?> <span style="color:#888;font-size:10px">(<?= escH($k['kod']) ?>)</span></td>
            <td><?= $k['miktar'] ?></td>
            <td><?= para($k['toplam']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td colspan="3" style="text-align:right;font-weight:bold">GENEL TOPLAM</td><td style="font-weight:bold"><?= para($satis['genel_toplam']) ?></td></tr>
        <?php if ($satis['odenen_tutar']>0): ?>
        <tr><td colspan="3" style="text-align:right">Peşinat</td><td><?= para($satis['odenen_tutar']) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="3" style="text-align:right;font-weight:bold">TAKSİTLENECEK TUTAR</td><td style="font-weight:bold"><?= para($satis['genel_toplam'] - $satis['odenen_tutar']) ?></td></tr>
    </table>

    <h4 style="margin-bottom:8px;font-size:12px">ÖDEME TAKVİMİ (<?= $satis['taksit_sayisi'] ?> Taksit)</h4>
    <table>
        <thead><tr><th>#</th><th>Vade Tarihi</th><th>Tutar</th><th>İmza</th></tr></thead>
        <tbody>
        <?php foreach ($taksitPlani as $tp): ?>
        <tr>
            <td><?= $tp['taksit_no'] ?>.</td>
            <td><?= tarih($tp['vade_tarihi']) ?></td>
            <td style="font-weight:bold"><?= para($tp['tutar']) ?></td>
            <td style="width:120px"></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="madde"><strong>Madde 1 —</strong> Alıcı, yukarıda dökümü verilen ürünleri teslim aldığını, ürünlerin çalışır ve eksiksiz olduğunu kabul eder.</div>
    <div class="madde"><strong>Madde 2 —</strong> Alıcı, yukarıdaki ödeme takvimine uygun olarak taksitlerini zamanında ödemeyi taahhüt eder. Vadesinde ödenmeyen taksitler için satıcı yasal yollara başvurma hakkını saklı tutar.</div>
    <div class="madde"><strong>Madde 3 —</strong> Erken ödeme durumunda kalan taksitler üzerinden herhangi bir ek ücret talep edilmez.</div>
    <div class="madde"><strong>Madde 4 —</strong> İşbu sözleşme iki nüsha olarak düzenlenmiş olup, taraflarca okunarak imza altına alınmıştır.</div>

    <div class="imza-alani">
        <div class="imza-kutu">
            <?php if (ayar('kase_imza')): ?>
            <img src="<?= BASE_URL ?>/uploads/marka/<?= escH(ayar('kase_imza')) ?>" style="max-height:60px;max-width:140px;object-fit:contain">
            <?php endif; ?>
            <div class="imza-cizgi">SATICI<br><?= escH(ayar('firma_adi','Regal Bayi')) ?></div>
        </div>
        <div class="imza-kutu">
            <div class="imza-cizgi">ALICI<br><?= escH($satis['musteri_adi'] ?: '') ?></div>
        </div>
    </div>

    <div class="footer-note">
        <?= escH(ayar('firma_adi','Regal Bayi')) ?> &bull; Belge tarihi: <?= date('d.m.Y H:i') ?>
    </div>
</div>
</body>
</html>
