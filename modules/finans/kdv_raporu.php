<?php
// KDV özet raporu (yalnızca satışlardan hesaplanan/tahsil edilen KDV)
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'KDV Özeti';
$pdo = db();

$bas = gecerliTarih($_GET['bas'] ?? '', date('Y-m-01'));
$bit = gecerliTarih($_GET['bit'] ?? '', date('Y-m-d'));

// Satışlardan hesaplanan KDV (iade edilmemiş kalemler için orantılı düşülür)
$stmt = $pdo->prepare("SELECT sk.kdv_orani,
        SUM((sk.toplam - sk.kdv_tutar) * (sk.miktar - sk.iade_miktar) / sk.miktar) AS matrah,
        SUM(sk.kdv_tutar * (sk.miktar - sk.iade_miktar) / sk.miktar) AS kdv
    FROM satis_kalemleri sk
    JOIN satislar s ON sk.satis_id = s.id
    WHERE s.durum != 'iptal' AND s.tarih BETWEEN ? AND ?
    GROUP BY sk.kdv_orani ORDER BY sk.kdv_orani");
$stmt->execute([$bas, $bit]);
$kdvOranBazinda = $stmt->fetchAll();

$toplamMatrah = array_sum(array_column($kdvOranBazinda, 'matrah'));
$toplamKdv    = array_sum(array_column($kdvOranBazinda, 'kdv'));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-receipt-cutoff text-primary"></i> KDV Özeti</h4>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> Yazdır</button>
        <a href="index.php" class="btn btn-outline-secondary btn-sm no-print">← Kasa & Finans</a>
    </div>
</div>

<div class="alert alert-warning small no-print">
    <i class="bi bi-exclamation-triangle"></i> Bu rapor yalnızca <strong>satışlardan hesaplanan (tahsil edilen) KDV'yi</strong> gösterir.
    Gider/stok girişlerindeki <strong>indirilecek KDV</strong> sistemde ayrıca tutulmadığı için dahil değildir —
    tam KDV beyanı için muhasebecinizle birlikte kontrol edin.
</div>

<div class="card shadow-sm mb-3 no-print">
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
            <div class="col-md-3"><button class="btn btn-sm btn-primary">Göster</button></div>
        </form>
    </div>
</div>

<div class="text-center mb-3">
    <h5 class="fw-bold"><?= escH(ayar('firma_adi','Regal Bayi')) ?> — KDV Özet Raporu</h5>
    <div class="text-muted"><?= tarih($bas) ?> — <?= tarih($bit) ?></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Toplam Matrah (KDV Hariç)</div>
                <div class="fw-bold fs-4"><?= para($toplamMatrah) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Hesaplanan KDV</div>
                <div class="fw-bold fs-4"><?= para($toplamKdv) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">KDV Oranına Göre Dağılım</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>KDV Oranı</th><th class="text-end">Matrah</th><th class="text-end">KDV Tutarı</th></tr></thead>
            <tbody>
            <?php if (empty($kdvOranBazinda)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Bu dönemde satış yok</td></tr>
            <?php endif; ?>
            <?php foreach ($kdvOranBazinda as $k): ?>
            <tr>
                <td>%<?= (int)$k['kdv_orani'] ?></td>
                <td class="text-end"><?= para($k['matrah']) ?></td>
                <td class="text-end fw-bold"><?= para($k['kdv']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!empty($kdvOranBazinda)): ?>
            <tr class="fw-bold table-primary">
                <td>TOPLAM</td>
                <td class="text-end"><?= para($toplamMatrah) ?></td>
                <td class="text-end"><?= para($toplamKdv) ?></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
