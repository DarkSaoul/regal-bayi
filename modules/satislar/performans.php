<?php
// Kasiyer performans raporu — yalnızca yönetici
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Kasiyer Performansı';
$pdo = db();

$bas = gecerliTarih($_GET['bas'] ?? '', date('Y-m-01'));
$bit = gecerliTarih($_GET['bit'] ?? '', date('Y-m-d'));

$perKasiyer = $pdo->prepare("SELECT k.id, k.ad_soyad, k.rol,
        COUNT(s.id) AS adet, COALESCE(SUM(s.genel_toplam),0) AS ciro,
        COALESCE(AVG(s.genel_toplam),0) AS ort_sepet,
        COALESCE(SUM(s.indirim_toplam),0) AS indirim
    FROM kullanicilar k
    LEFT JOIN satislar s ON s.kullanici_id=k.id AND s.tarih BETWEEN ? AND ? AND s.durum!='iptal'
    WHERE k.rol IN ('yonetici','kasiyer')
    GROUP BY k.id ORDER BY ciro DESC");
$perKasiyer->execute([$bas, $bit]); $perKasiyer = $perKasiyer->fetchAll();

// Saat bazlı yoğunluk (created_at üzerinden)
$saatlik = $pdo->prepare("SELECT HOUR(created_at) AS saat, COUNT(*) AS adet
    FROM satislar WHERE tarih BETWEEN ? AND ? AND durum!='iptal' GROUP BY saat ORDER BY saat");
$saatlik->execute([$bas, $bit]); $saatlik = $saatlik->fetchAll(PDO::FETCH_KEY_PAIR);
$saatVerisi = [];
for ($h = 8; $h <= 21; $h++) $saatVerisi[$h] = (int)($saatlik[$h] ?? 0);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-person-badge text-primary"></i> Kasiyer Performansı</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Satışlar</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label small mb-1">Başlangıç</label>
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= $bas ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Bitiş</label>
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= $bit ?>">
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-people text-primary"></i> Kasiyer Bazında Özet</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>Kasiyer</th><th class="text-center">İşlem</th><th class="text-end">Ciro</th><th class="text-end">Ort. Sepet</th><th class="text-end">İndirim</th></tr></thead>
                    <tbody>
                    <?php if (empty($perKasiyer)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Veri yok</td></tr>
                    <?php endif; ?>
                    <?php foreach ($perKasiyer as $p): ?>
                    <tr>
                        <td><?= escH($p['ad_soyad']) ?> <span class="badge bg-secondary"><?= $p['rol'] ?></span></td>
                        <td class="text-center"><?= (int)$p['adet'] ?></td>
                        <td class="text-end fw-bold"><?= para($p['ciro']) ?></td>
                        <td class="text-end"><?= para($p['ort_sepet']) ?></td>
                        <td class="text-end text-warning"><?= para($p['indirim']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-clock text-primary"></i> Saat Bazlı Satış Yoğunluğu</div>
            <div class="card-body">
                <canvas id="saatGrafik" height="180"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
const _saatGrafikEl = document.getElementById('saatGrafik');
if (_saatGrafikEl) document.addEventListener('DOMContentLoaded', () => new Chart(_saatGrafikEl, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($h) => $h . ':00', array_keys($saatVerisi))) ?>,
        datasets: [{
            label: 'Satış Adedi',
            data: <?= json_encode(array_values($saatVerisi)) ?>,
            backgroundColor: 'rgba(13,110,253,.7)',
            borderRadius: 4
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
}));
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
