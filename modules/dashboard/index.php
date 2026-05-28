<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Dashboard';
$pdo = db();

// İstatistikler
$bugun = date('Y-m-d');
$buAy = date('Y-m');

$s = $pdo->prepare("SELECT COALESCE(SUM(genel_toplam),0) FROM satislar WHERE tarih=? AND durum!='iptal'");
$s->execute([$bugun]); $gunlukSatis = $s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(genel_toplam),0) FROM satislar WHERE DATE_FORMAT(tarih,'%Y-%m')=? AND durum!='iptal'");
$s->execute([$buAy]); $aylikSatis = $s->fetchColumn();
$toplamMusteri = $pdo->query("SELECT COUNT(*) FROM musteriler")->fetchColumn();
$toplamUrun    = $pdo->query("SELECT COUNT(*) FROM urunler WHERE aktif=1")->fetchColumn();
$dusukStok     = $pdo->query("SELECT COUNT(*) FROM urunler WHERE stok_adedi <= min_stok AND aktif=1")->fetchColumn();
$bekleyenOdeme = $pdo->query("SELECT COALESCE(SUM(kalan_tutar),0) FROM satislar WHERE kalan_tutar>0 AND durum='bekliyor'")->fetchColumn();

// Son satışlar
$sonSatislar = $pdo->query("
    SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi
    FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id
    ORDER BY s.created_at DESC LIMIT 8
")->fetchAll();

// Aylık satış grafiği (son 6 ay)
$grafik = $pdo->query("
    SELECT DATE_FORMAT(tarih,'%Y-%m') AS ay, SUM(genel_toplam) AS toplam
    FROM satislar WHERE durum!='iptal' AND tarih >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY ay ORDER BY ay
")->fetchAll();
$grafik_etiket = array_column($grafik, 'ay');
$grafik_deger  = array_column($grafik, 'toplam');

// En çok satan ürünler
$enCokSatan = $pdo->query("
    SELECT u.ad, SUM(sk.miktar) AS adet, SUM(sk.toplam) AS tutar
    FROM satis_kalemleri sk
    JOIN urunler u ON sk.urun_id = u.id
    JOIN satislar s ON sk.satis_id = s.id
    WHERE s.durum != 'iptal' AND s.tarih >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY u.id ORDER BY adet DESC LIMIT 5
")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4><i class="bi bi-speedometer2 text-primary"></i> Dashboard</h4>
        <small class="text-muted"><?= date('d.m.Y, l') ?></small>
    </div>
    <a href="<?= BASE_URL ?>/modules/satislar/yeni.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Yeni Satış
    </a>
</div>

<!-- İstatistik Kartları -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-cart-check"></i></div>
                <div>
                    <div class="text-muted small">Bugün Satış</div>
                    <div class="fw-bold fs-5"><?= para($gunlukSatis) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-graph-up-arrow"></i></div>
                <div>
                    <div class="text-muted small">Aylık Satış</div>
                    <div class="fw-bold fs-5"><?= para($aylikSatis) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="text-muted small">Bekleyen Tahsilat</div>
                    <div class="fw-bold fs-5 text-warning"><?= para($bekleyenOdeme) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-people"></i></div>
                <div>
                    <div class="text-muted small">Toplam Müşteri</div>
                    <div class="fw-bold fs-5"><?= number_format($toplamMusteri) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php if ($dusukStok > 0): ?>
    <div class="col-md-4">
        <div class="card border-danger shadow-sm">
            <div class="card-body">
                <h6 class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Düşük Stok Uyarısı</h6>
                <p class="mb-2 fs-4 fw-bold text-danger"><?= $dusukStok ?> Ürün</p>
                <a href="<?= BASE_URL ?>/modules/stok/dusuk.php" class="btn btn-sm btn-outline-danger">Görüntüle</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <!-- Grafik -->
    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart text-primary"></i> Aylık Satış (Son 6 Ay)
            </div>
            <div class="card-body">
                <canvas id="satisGrafik" height="110"></canvas>
            </div>
        </div>
    </div>
    <!-- En çok satan -->
    <div class="col-xl-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-trophy text-warning"></i> En Çok Satan (30 Gün)
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                <?php if (empty($enCokSatan)): ?>
                    <li class="list-group-item text-muted text-center py-3">Henüz satış yok</li>
                <?php else: ?>
                    <?php foreach ($enCokSatan as $u): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-truncate me-2" style="max-width:150px"><?= escH($u['ad']) ?></span>
                        <div class="text-end">
                            <span class="badge bg-primary"><?= $u['adet'] ?> adet</span>
                            <div class="small text-muted"><?= para($u['tutar']) ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Son Satışlar -->
<div class="card shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-clock-history text-primary"></i> Son Satışlar</span>
        <a href="<?= BASE_URL ?>/modules/satislar/" class="btn btn-sm btn-outline-primary">Tümü</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Fatura No</th><th>Müşteri</th><th>Tarih</th>
                <th>Tutar</th><th>Ödeme</th><th>Durum</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($sonSatislar)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">Henüz satış kaydı yok</td></tr>
            <?php else: ?>
                <?php foreach ($sonSatislar as $s): ?>
                <tr>
                    <td><strong><?= escH($s['fatura_no']) ?></strong></td>
                    <td><?= escH($s['musteri_adi'] ?: 'Perakende') ?></td>
                    <td><?= tarih($s['tarih']) ?></td>
                    <td><?= para($s['genel_toplam']) ?></td>
                    <td><?= ucfirst(str_replace('_',' ',$s['odeme_tipi'])) ?></td>
                    <td>
                        <?php
                        $renk = $s['durum']==='tamamlandi' ? 'success' : ($s['durum']==='iptal' ? 'danger' : 'warning');
                        $label = $s['durum']==='tamamlandi' ? 'Tamamlandı' : ($s['durum']==='iptal' ? 'İptal' : 'Bekliyor');
                        ?>
                        <span class="badge bg-<?= $renk ?>"><?= $label ?></span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('satisGrafik');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($grafik_etiket) ?>,
        datasets: [{
            label: 'Satış (₺)',
            data: <?= json_encode(array_map('floatval', $grafik_deger)) ?>,
            backgroundColor: 'rgba(13,110,253,.7)',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
