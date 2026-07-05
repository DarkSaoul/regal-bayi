<?php
// Aylık tahsilat performansı (zamanında/gecikmeli ödeme oranı) + erken ödeme indirim hesaplayıcı
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Taksit Performans Raporu';
$pdo = db();

$ayAdlari = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];

// Son 6 ayın vade dağılımı
$stmt = $pdo->query("
    SELECT DATE_FORMAT(tp.vade_tarihi,'%Y-%m') AS ay,
        SUM(CASE WHEN tp.odendi=1 AND tp.odeme_tarihi <= tp.vade_tarihi THEN 1 ELSE 0 END) AS zamaninda,
        SUM(CASE WHEN tp.odendi=1 AND tp.odeme_tarihi > tp.vade_tarihi THEN 1 ELSE 0 END) AS gecikmeli_odendi,
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi < CURDATE() THEN 1 ELSE 0 END) AS hala_gecikmis,
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi >= CURDATE() THEN 1 ELSE 0 END) AS henuz_beklenen,
        COUNT(*) AS toplam
    FROM taksit_plani tp JOIN satislar s ON tp.satis_id=s.id
    WHERE s.durum != 'iptal' AND tp.vade_tarihi >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ay ORDER BY ay
");
$aylikVeri = $stmt->fetchAll();

// Erken ödeme hesaplayıcı — fatura no ile arama
$hesapSatis = null; $hesapTaksitler = []; $hesapToplam = 0; $hesapIndirimli = 0;
$erkenIndirimOran = (float)ayar('taksit_erken_odeme_indirim', '0');
if (!empty($_GET['fatura'])) {
    $fatura = trim($_GET['fatura']);
    $s = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id WHERE s.fatura_no=? AND s.durum!='iptal'");
    $s->execute([$fatura]); $hesapSatis = $s->fetch();
    if ($hesapSatis) {
        $tk = $pdo->prepare("SELECT * FROM taksit_plani WHERE satis_id=? AND odendi=0 ORDER BY taksit_no");
        $tk->execute([$hesapSatis['id']]); $hesapTaksitler = $tk->fetchAll();
        $hesapToplam = array_sum(array_column($hesapTaksitler, 'tutar'));
        $hesapIndirimli = round($hesapToplam * (1 - $erkenIndirimOran / 100), 2);
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-graph-up text-primary"></i> Taksit Performans Raporu</h4>
    <a href="taksit_takvimi.php" class="btn btn-outline-secondary btn-sm">← Taksit Takvimi</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold py-2">Son 6 Ay — Vade Zamanında / Gecikmeli Ödeme Dağılımı</div>
    <div class="card-body">
        <canvas id="performansGrafik" height="90"></canvas>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Ay</th><th class="text-center">Toplam Vade</th><th class="text-center">Zamanında Ödenen</th><th class="text-center">Gecikmeli Ödenen</th><th class="text-center">Hâlâ Gecikmiş</th><th class="text-center">Henüz Beklenen</th><th class="text-end">Zamanında Ödeme Oranı</th></tr></thead>
            <tbody>
            <?php if (empty($aylikVeri)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">Veri yok</td></tr>
            <?php endif; ?>
            <?php foreach ($aylikVeri as $a):
                $degerlendirilenNet = $a['zamaninda'] + $a['gecikmeli_odendi'] + $a['hala_gecikmis'];
                $oran = $degerlendirilenNet > 0 ? round($a['zamaninda'] / $degerlendirilenNet * 100, 1) : null;
                [$yil, $ayNo] = explode('-', $a['ay']);
            ?>
            <tr>
                <td><?= $ayAdlari[(int)$ayNo] ?> <?= $yil ?></td>
                <td class="text-center"><?= $a['toplam'] ?></td>
                <td class="text-center text-success fw-bold"><?= $a['zamaninda'] ?></td>
                <td class="text-center text-warning"><?= $a['gecikmeli_odendi'] ?></td>
                <td class="text-center text-danger"><?= $a['hala_gecikmis'] ?></td>
                <td class="text-center text-muted"><?= $a['henuz_beklenen'] ?></td>
                <td class="text-end fw-bold <?= $oran !== null && $oran < 70 ? 'text-danger' : 'text-success' ?>"><?= $oran !== null ? '%' . number_format($oran,1,',','.') : '-' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Erken ödeme indirim hesaplayıcı -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-calculator text-primary"></i> Erken Ödeme İndirim Hesaplayıcı</div>
    <div class="card-body">
        <?php if ($erkenIndirimOran <= 0): ?>
        <div class="alert alert-light border small mb-3">
            Erken ödeme indirimi şu anda kapalı (%0). Ayarlar → Finans sekmesinden <code>taksit_erken_odeme_indirim</code> oranını belirleyebilirsiniz.
        </div>
        <?php endif; ?>
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Fatura No</label>
                <input type="text" name="fatura" class="form-control form-control-sm" placeholder="Örn: F202607001" value="<?= escH($_GET['fatura'] ?? '') ?>" required>
            </div>
            <div class="col-md-3"><button class="btn btn-sm btn-primary">Hesapla</button></div>
        </form>
        <?php if (!empty($_GET['fatura']) && !$hesapSatis): ?>
        <div class="alert alert-warning small">Fatura bulunamadı.</div>
        <?php elseif ($hesapSatis && empty($hesapTaksitler)): ?>
        <div class="alert alert-light border small">Bu satışın ödenmemiş taksiti yok.</div>
        <?php elseif ($hesapSatis): ?>
        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr><td class="text-muted">Müşteri</td><td class="fw-semibold"><?= escH($hesapSatis['musteri_adi'] ?: 'Perakende') ?></td></tr>
                    <tr><td class="text-muted">Kalan Taksit Sayısı</td><td class="fw-semibold"><?= count($hesapTaksitler) ?></td></tr>
                    <tr><td class="text-muted">Kalan Taksit Toplamı</td><td class="fw-bold"><?= para($hesapToplam) ?></td></tr>
                    <tr><td class="text-muted">Erken Ödeme İndirimi</td><td>%<?= number_format($erkenIndirimOran,1,',','.') ?></td></tr>
                    <tr class="table-success"><td class="fw-semibold">Bugün Peşin Kapatırsa</td><td class="fw-bold fs-5"><?= para($hesapIndirimli) ?></td></tr>
                </table>
                <?php if ($erkenIndirimOran > 0): ?>
                <a href="tahsilat.php?satis_id=<?= $hesapSatis['id'] ?>&tutar=<?= $hesapIndirimli ?>" class="btn btn-success">
                    <i class="bi bi-cash-coin"></i> <?= para($hesapIndirimli) ?> ile Tahsilata Git
                </a>
                <div class="form-text mt-1">Tahsilat tutarı önerilen indirimli tutarla önceden doldurulur, işlem öncesi düzenleyebilirsiniz.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <table class="table table-sm">
                    <thead class="table-light"><tr><th>#</th><th>Vade</th><th class="text-end">Tutar</th></tr></thead>
                    <tbody>
                    <?php foreach ($hesapTaksitler as $t): ?>
                    <tr><td><?= $t['taksit_no'] ?></td><td><?= tarih($t['vade_tarihi']) ?></td><td class="text-end"><?= para($t['tutar']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const _perfEl = document.getElementById('performansGrafik');
if (_perfEl) document.addEventListener('DOMContentLoaded', () => new Chart(_perfEl, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($a) => $ayAdlari[(int)explode('-',$a['ay'])[1]] . ' ' . explode('-',$a['ay'])[0], $aylikVeri)) ?>,
        datasets: [
            { label: 'Zamanında Ödenen', data: <?= json_encode(array_column($aylikVeri,'zamaninda')) ?>, backgroundColor: 'rgba(25,135,84,.8)' },
            { label: 'Gecikmeli Ödenen', data: <?= json_encode(array_column($aylikVeri,'gecikmeli_odendi')) ?>, backgroundColor: 'rgba(255,193,7,.8)' },
            { label: 'Hâlâ Gecikmiş', data: <?= json_encode(array_column($aylikVeri,'hala_gecikmis')) ?>, backgroundColor: 'rgba(220,53,69,.8)' },
            { label: 'Henüz Beklenen', data: <?= json_encode(array_column($aylikVeri,'henuz_beklenen')) ?>, backgroundColor: 'rgba(108,117,125,.5)' }
        ]
    },
    options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
}));
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
