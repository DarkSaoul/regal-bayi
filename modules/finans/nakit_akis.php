<?php
// Nakit akış tahmini — önümüzdeki 30 gün: beklenen taksit tahsilatları + planlı sabit giderler
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
moduleKontrol('nakit_akis', 'Nakit Akış Tahmini');
$sayfa_basligi = 'Nakit Akış Tahmini';
$pdo = db();

$gunSayisi = 30;
$bugun = new DateTime();
$bitis = (clone $bugun)->modify("+{$gunSayisi} days");

// Beklenen taksit tahsilatları (gün bazında toplanmış)
$stmt = $pdo->prepare("SELECT tp.vade_tarihi, SUM(tp.tutar) AS toplam
    FROM taksit_plani tp JOIN satislar s ON tp.satis_id=s.id
    WHERE tp.odendi=0 AND s.durum!='iptal' AND tp.vade_tarihi BETWEEN ? AND ?
    GROUP BY tp.vade_tarihi");
$stmt->execute([$bugun->format('Y-m-d'), $bitis->format('Y-m-d')]);
$taksitGelirleri = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Aktif tekrarlayan gider şablonlarının önümüzdeki 30 gündeki tahmini vadeleri
$sablonlar = $pdo->query("SELECT gs.*, kk.ad AS kategori_adi FROM gider_sablonlari gs JOIN kasa_kategoriler kk ON gs.kategori_id=kk.id WHERE gs.aktif=1")->fetchAll();
$giderTahminleri = []; // tarih => [['ad'=>..,'tutar'=>..], ...]
foreach ($sablonlar as $s) {
    $tarama = clone $bugun;
    while ($tarama <= $bitis) {
        $eslesme = false;
        if ($s['periyot'] === 'aylik') {
            $ayinGunSayisi = (int)$tarama->format('t');
            if ((int)$tarama->format('j') === min((int)$s['gun'], $ayinGunSayisi)) $eslesme = true;
        } else {
            if ((int)$tarama->format('N') === (int)$s['gun']) $eslesme = true;
        }
        if ($eslesme) {
            $t = $tarama->format('Y-m-d');
            $giderTahminleri[$t][] = ['ad' => $s['ad'], 'tutar' => (float)$s['tutar']];
        }
        $tarama->modify('+1 day');
    }
}

// Günlük satır listesi oluştur
$satirlar = [];
$kumulatif = kasaBakiyesi('kasa') + kasaBakiyesi('banka');
$tarama = clone $bugun;
$toplamGelir = 0; $toplamGider = 0;
while ($tarama <= $bitis) {
    $t = $tarama->format('Y-m-d');
    $gelir = (float)($taksitGelirleri[$t] ?? 0);
    $giderKalemleri = $giderTahminleri[$t] ?? [];
    $gider = array_sum(array_column($giderKalemleri, 'tutar'));
    if ($gelir > 0 || $gider > 0) {
        $kumulatif += $gelir - $gider;
        $satirlar[] = ['tarih' => $t, 'gelir' => $gelir, 'gider' => $gider, 'gider_detay' => $giderKalemleri, 'bakiye' => $kumulatif];
        $toplamGelir += $gelir; $toplamGider += $gider;
    }
    $tarama->modify('+1 day');
}

$baslangicBakiye = kasaBakiyesi('kasa') + kasaBakiyesi('banka');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-graph-up text-primary"></i> Nakit Akış Tahmini <span class="text-muted small">(Önümüzdeki <?= $gunSayisi ?> Gün)</span></h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Kasa & Finans</a>
</div>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle"></i> Bu tahmin, <strong>vadesi gelecek taksit tahsilatları</strong> (ödenmemiş, iptal olmayan satışlardan) ile
    <strong>aktif tekrarlayan gider şablonlarının</strong> önümüzdeki vadelerine dayanır. Yeni satış, tahsilat veya beklenmedik gider bu tahmini değiştirir.
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-secondary text-white">
            <div class="card-body py-2"><div class="small">Şu Anki Bakiye (Kasa+Banka)</div><div class="fw-bold fs-5"><?= para($baslangicBakiye) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body py-2"><div class="small">Beklenen Tahsilat</div><div class="fw-bold fs-5"><?= para($toplamGelir) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-danger text-white">
            <div class="card-body py-2"><div class="small">Planlı Gider</div><div class="fw-bold fs-5"><?= para($toplamGider) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 <?= ($baslangicBakiye+$toplamGelir-$toplamGider)>=0?'bg-primary':'bg-danger' ?> text-white">
            <div class="card-body py-2"><div class="small"><?= $gunSayisi ?> Gün Sonra Tahmini</div><div class="fw-bold fs-5"><?= para($baslangicBakiye+$toplamGelir-$toplamGider) ?></div></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Bakiye Projeksiyonu</div>
    <div class="card-body">
        <canvas id="nakitAkisGrafik" height="90"></canvas>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">Günlük Detay</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tarih</th><th class="text-end">Beklenen Tahsilat</th><th class="text-end">Planlı Gider</th><th>Gider Detayı</th><th class="text-end">Kümülatif Bakiye</th></tr></thead>
            <tbody>
            <?php if (empty($satirlar)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Önümüzdeki <?= $gunSayisi ?> günde planlı hareket yok</td></tr>
            <?php endif; ?>
            <?php foreach ($satirlar as $s): ?>
            <tr class="<?= $s['bakiye'] < 0 ? 'table-danger' : '' ?>">
                <td><?= tarih($s['tarih']) ?></td>
                <td class="text-end text-success"><?= $s['gelir']>0 ? para($s['gelir']) : '-' ?></td>
                <td class="text-end text-danger"><?= $s['gider']>0 ? para($s['gider']) : '-' ?></td>
                <td class="small text-muted"><?= escH(implode(', ', array_map(fn($g)=>$g['ad'], $s['gider_detay']))) ?></td>
                <td class="text-end fw-bold <?= $s['bakiye']<0?'text-danger':'' ?>"><?= para($s['bakiye']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const _nakitEl = document.getElementById('nakitAkisGrafik');
if (_nakitEl) document.addEventListener('DOMContentLoaded', () => new Chart(_nakitEl, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn($s) => tarih($s['tarih']), $satirlar)) ?>,
        datasets: [{
            label: 'Tahmini Bakiye',
            data: <?= json_encode(array_map(fn($s) => round($s['bakiye'],2), $satirlar)) ?>,
            borderColor: 'rgba(13,110,253,1)',
            backgroundColor: 'rgba(13,110,253,.1)',
            fill: true, tension: .3
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
}));
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
