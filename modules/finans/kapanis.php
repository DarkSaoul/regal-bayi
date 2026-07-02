<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Kasa Kapanışı';
$pdo = db();

$tarih = $_GET['tarih'] ?? date('Y-m-d');

// Giriş — ödeme tipine göre
$stmt = $pdo->prepare("
    SELECT odeme_tipi, SUM(tutar) AS toplam
    FROM odemeler WHERE tarih=?
    GROUP BY odeme_tipi
");
$stmt->execute([$tarih]);
$tahsilatlar = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Nakit kasa girişleri (kasa_hareketleri)
$stmt = $pdo->prepare("SELECT tip, kategori, SUM(tutar) AS toplam FROM kasa_hareketleri WHERE tarih=? GROUP BY tip, kategori");
$stmt->execute([$tarih]);
$kasaHareketler = $stmt->fetchAll();

$kasaGiris = 0; $kasaCikis = 0;
$girisDtl = []; $cikisDtl = [];
foreach ($kasaHareketler as $h) {
    if ($h['tip']==='giris') { $kasaGiris += $h['toplam']; $girisDtl[$h['kategori']??'Diğer'] = ($girisDtl[$h['kategori']??'Diğer']??0) + $h['toplam']; }
    else { $kasaCikis += $h['toplam']; $cikisDtl[$h['kategori']??'Diğer'] = ($cikisDtl[$h['kategori']??'Diğer']??0) + $h['toplam']; }
}

// Satış sayıları
$stmt = $pdo->prepare("SELECT COUNT(*) AS adet, SUM(genel_toplam) AS toplam, odeme_tipi FROM satislar WHERE tarih=? AND durum!='iptal' GROUP BY odeme_tipi");
$stmt->execute([$tarih]);
$satisGruplari = $stmt->fetchAll();

$gunlukSatisToplam = array_sum(array_column($satisGruplari, 'toplam'));
$gunlukSatisAdet   = array_sum(array_column($satisGruplari, 'adet'));

// Yeni müşteri sayısı
$stmt = $pdo->prepare("SELECT COUNT(*) FROM musteriler WHERE DATE(created_at)=?");
$stmt->execute([$tarih]); $yeniMusteri = $stmt->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-door-closed text-primary"></i> Kasa Kapanışı</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
        <i class="bi bi-printer"></i> Yazdır
    </button>
</div>

<!-- Tarih Seç -->
<div class="card shadow-sm mb-3 no-print">
    <div class="card-body py-2">
        <form class="d-flex align-items-center gap-2" method="get">
            <label class="fw-semibold mb-0">Tarih:</label>
            <input type="date" name="tarih" class="form-control form-control-sm" style="max-width:180px" value="<?= $tarih ?>">
            <button class="btn btn-sm btn-primary">Göster</button>
        </form>
    </div>
</div>

<div class="text-center mb-3">
    <h5 class="fw-bold"><?= escH(ayar('firma_adi','Regal Bayi')) ?> — Günlük Kapanış Raporu</h5>
    <div class="text-muted"><?= date('d.m.Y', strtotime($tarih)) ?></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Günlük Satış</div>
                <div class="fw-bold fs-4"><?= para($gunlukSatisToplam) ?></div>
                <div class="small opacity-75"><?= $gunlukSatisAdet ?> işlem</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Kasa Girişi (Nakit)</div>
                <div class="fw-bold fs-4"><?= para($kasaGiris) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-danger text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Kasa Çıkışı</div>
                <div class="fw-bold fs-4"><?= para($kasaCikis) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 <?= ($kasaGiris-$kasaCikis)>=0?'bg-success':'bg-danger' ?> text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Net Kasa (Nakit)</div>
                <div class="fw-bold fs-4"><?= para($kasaGiris - $kasaCikis) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Satışlar ödeme tipine göre -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-receipt text-primary"></i> Satışlar (Ödeme Tipine Göre)</div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Tip</th><th class="text-center">Adet</th><th class="text-end">Tutar</th></tr></thead>
                <tbody>
                <?php if (empty($satisGruplari)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Satış yok</td></tr>
                <?php else: ?>
                <?php foreach ($satisGruplari as $sg): ?>
                <tr>
                    <td><?= ucfirst(str_replace('_',' ',$sg['odeme_tipi'])) ?></td>
                    <td class="text-center"><?= $sg['adet'] ?></td>
                    <td class="text-end fw-bold"><?= para($sg['toplam']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-bold table-primary">
                    <td>TOPLAM</td>
                    <td class="text-center"><?= $gunlukSatisAdet ?></td>
                    <td class="text-end"><?= para($gunlukSatisToplam) ?></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Tahsilatlar -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-cash-coin text-success"></i> Tahsilatlar (Ödeme Tipine Göre)</div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Tip</th><th class="text-end">Tutar</th></tr></thead>
                <tbody>
                <?php if (empty($tahsilatlar)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">Tahsilat yok</td></tr>
                <?php else: ?>
                <?php foreach ($tahsilatlar as $tip => $tutar): ?>
                <tr>
                    <td><?= ucfirst(str_replace('_',' ',$tip)) ?></td>
                    <td class="text-end fw-bold text-success"><?= para($tutar) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-bold table-success">
                    <td>TOPLAM</td>
                    <td class="text-end"><?= para(array_sum($tahsilatlar)) ?></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Kasa hareketleri -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right text-warning"></i> Kasa Hareketleri</div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Kategori</th><th>Tip</th><th class="text-end">Tutar</th></tr></thead>
                <tbody>
                <?php if (empty($kasaHareketler)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Hareket yok</td></tr>
                <?php else: ?>
                <?php foreach ($kasaHareketler as $h): ?>
                <tr>
                    <td><?= escH($h['kategori']??'Diğer') ?></td>
                    <td><span class="badge bg-<?= $h['tip']==='giris'?'success':'danger' ?>"><?= $h['tip']==='giris'?'Giriş':'Çıkış' ?></span></td>
                    <td class="text-end fw-bold <?= $h['tip']==='giris'?'text-success':'text-danger' ?>"><?= para($h['toplam']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-bold">
                    <td colspan="2">Net</td>
                    <td class="text-end <?= ($kasaGiris-$kasaCikis)>=0?'text-success':'text-danger' ?>"><?= para($kasaGiris-$kasaCikis) ?></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<?php if ($yeniMusteri > 0): ?>
<div class="alert alert-info mt-3">
    <i class="bi bi-person-plus"></i> Bugün <strong><?= $yeniMusteri ?></strong> yeni müşteri kaydedildi.
</div>
<?php endif; ?>

<div class="mt-3 p-3 bg-light rounded no-print">
    <h6 class="fw-semibold">Kasa Sayım Notu</h6>
    <div class="row g-2">
        <?php foreach (['Nakit','Kredi Kartı','Havale/EFT'] as $tip): ?>
        <div class="col-md-4">
            <label class="form-label small"><?= $tip ?></label>
            <input type="number" step="0.01" class="form-control form-control-sm" placeholder="0,00 ₺">
        </div>
        <?php endforeach; ?>
    </div>
    <div class="form-text">Bu alan yalnızca ekranda görünür, kaydedilmez. Kasa sayım notları için kullanabilirsiniz.</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
