<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Raporlar';
$pdo = db();

$bas = $_GET['bas'] ?? date('Y-m-01');
$bit = $_GET['bit'] ?? date('Y-m-d');

// Satış özeti
$satisOzet = $pdo->prepare("SELECT COUNT(*) AS adet, SUM(genel_toplam) AS toplam, SUM(odenen_tutar) AS odenen, SUM(kalan_tutar) AS kalan FROM satislar WHERE tarih BETWEEN ? AND ? AND durum!='iptal'");
$satisOzet->execute([$bas,$bit]); $satisOzet = $satisOzet->fetch();

// Ödeme tipine göre
$odemeGrup = $pdo->prepare("SELECT odeme_tipi, COUNT(*) AS adet, SUM(genel_toplam) AS toplam FROM satislar WHERE tarih BETWEEN ? AND ? AND durum!='iptal' GROUP BY odeme_tipi");
$odemeGrup->execute([$bas,$bit]); $odemeGrup = $odemeGrup->fetchAll();

// Kategoriye göre satış
$kategoriSatis = $pdo->prepare("SELECT k.ad AS kategori, SUM(sk.miktar) AS adet, SUM(sk.toplam) AS tutar FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id LEFT JOIN kategoriler k ON u.kategori_id=k.id JOIN satislar s ON sk.satis_id=s.id WHERE s.tarih BETWEEN ? AND ? AND s.durum!='iptal' GROUP BY k.id ORDER BY tutar DESC");
$kategoriSatis->execute([$bas,$bit]); $kategoriSatis = $kategoriSatis->fetchAll();

// En çok satan ürünler
$enCokSatan = $pdo->prepare("SELECT u.ad, u.kod, SUM(sk.miktar) AS adet, SUM(sk.toplam) AS tutar FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id JOIN satislar s ON sk.satis_id=s.id WHERE s.tarih BETWEEN ? AND ? AND s.durum!='iptal' GROUP BY u.id ORDER BY adet DESC LIMIT 10");
$enCokSatan->execute([$bas,$bit]); $enCokSatan = $enCokSatan->fetchAll();

// Günlük satış (grafik için)
$gunluk = $pdo->prepare("SELECT tarih, SUM(genel_toplam) AS toplam FROM satislar WHERE tarih BETWEEN ? AND ? AND durum!='iptal' GROUP BY tarih ORDER BY tarih");
$gunluk->execute([$bas,$bit]); $gunluk = $gunluk->fetchAll();

// Kasa özeti
$kasaOzet = $pdo->prepare("SELECT SUM(CASE WHEN tip='giris' THEN tutar ELSE 0 END) AS giris, SUM(CASE WHEN tip='cikis' THEN tutar ELSE 0 END) AS cikis FROM kasa_hareketleri WHERE tarih BETWEEN ? AND ?");
$kasaOzet->execute([$bas,$bit]); $kasaOzet = $kasaOzet->fetch();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-bar-chart-line text-primary"></i> Raporlar</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary no-print"><i class="bi bi-printer"></i> Yazdır</button>
</div>

<!-- Tarih filtresi -->
<div class="card shadow-sm mb-4 no-print">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-auto"><label class="form-label mb-0 fw-semibold">Başlangıç</label>
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= $bas ?>"></div>
            <div class="col-auto"><label class="form-label mb-0 fw-semibold">Bitiş</label>
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= $bit ?>"></div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary">Uygula</button>
                <a href="?bas=<?= date('Y-m-01') ?>&bit=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary">Bu Ay</a>
                <a href="?bas=<?= date('Y-01-01') ?>&bit=<?= date('Y-12-31') ?>" class="btn btn-sm btn-outline-secondary">Bu Yıl</a>
            </div>
        </form>
    </div>
</div>

<!-- Özet Kartlar -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <div class="small">Toplam Satış</div>
                <div class="fw-bold fs-4"><?= para($satisOzet['toplam']??0) ?></div>
                <div class="small opacity-75"><?= $satisOzet['adet'] ?> işlem</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body">
                <div class="small">Tahsil Edilen</div>
                <div class="fw-bold fs-4"><?= para($satisOzet['odenen']??0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body">
                <div class="small">Tahsil Edilemeyen</div>
                <div class="fw-bold fs-4"><?= para($satisOzet['kalan']??0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-info text-white">
            <div class="card-body">
                <div class="small">Kasa Net</div>
                <div class="fw-bold fs-4"><?= para(($kasaOzet['giris']??0) - ($kasaOzet['cikis']??0)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Grafik -->
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Günlük Satış Trendi</div>
            <div class="card-body">
                <canvas id="gunlukGrafik" height="150"></canvas>
            </div>
        </div>
    </div>
    <!-- Ödeme tipi -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Ödeme Tipi Dağılımı</div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Tip</th><th>Adet</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($odemeGrup as $o): ?>
                <tr>
                    <td><?= ucfirst(str_replace('_',' ',$o['odeme_tipi'])) ?></td>
                    <td><?= $o['adet'] ?></td>
                    <td><?= para($o['toplam']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Kategoriye göre -->
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Kategoriye Göre Satış</div>
            <div class="card-body p-0">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Kategori</th><th>Adet</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($kategoriSatis as $k): ?>
                <tr>
                    <td><?= escH($k['kategori']??'Kategori Yok') ?></td>
                    <td><?= $k['adet'] ?></td>
                    <td class="fw-bold"><?= para($k['tutar']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <!-- En çok satan -->
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">En Çok Satan Ürünler</div>
            <div class="card-body p-0">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>#</th><th>Ürün</th><th>Adet</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($enCokSatan as $i => $u): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= escH($u['ad']) ?></td>
                    <td><?= $u['adet'] ?></td>
                    <td><?= para($u['tutar']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- Kasa özeti -->
<div class="card shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold">Kasa Özeti (<?= tarih($bas) ?> — <?= tarih($bit) ?>)</div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="p-3 bg-success bg-opacity-10 rounded text-center">
                    <div class="text-muted small">Toplam Giriş</div>
                    <div class="fw-bold fs-4 text-success"><?= para($kasaOzet['giris']??0) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-danger bg-opacity-10 rounded text-center">
                    <div class="text-muted small">Toplam Çıkış</div>
                    <div class="fw-bold fs-4 text-danger"><?= para($kasaOzet['cikis']??0) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-primary bg-opacity-10 rounded text-center">
                    <div class="text-muted small">Net Kasa</div>
                    <div class="fw-bold fs-4 text-primary"><?= para(($kasaOzet['giris']??0) - ($kasaOzet['cikis']??0)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('gunlukGrafik'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn($g) => tarih($g['tarih']), $gunluk)) ?>,
        datasets: [{
            label: 'Günlük Satış',
            data: <?= json_encode(array_map(fn($g) => floatval($g['toplam']), $gunluk)) ?>,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13,110,253,.1)',
            tension: .4, fill: true
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
