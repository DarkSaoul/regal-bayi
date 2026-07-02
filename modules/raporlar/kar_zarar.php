<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Kâr-Zarar Raporu';
$pdo = db();

$bas = $_GET['bas'] ?? date('Y-m-01');
$bit = $_GET['bit'] ?? date('Y-m-d');

// Ürün bazlı kar analizi
$urunKar = $pdo->prepare("
    SELECT
        u.id, u.ad, u.kod, u.alis_fiyati,
        SUM(sk.miktar)      AS satis_adedi,
        AVG(sk.birim_fiyat) AS ort_satis_fiyati,
        -- sk.toplam zaten indirim düşülmüş tutardır; yalnızca KDV çıkarılır
        SUM(sk.toplam - sk.kdv_tutar) AS net_satis,
        SUM(sk.miktar * u.alis_fiyati) AS toplam_maliyet,
        SUM(sk.toplam - sk.kdv_tutar)
            - SUM(sk.miktar * u.alis_fiyati) AS brut_kar
    FROM satis_kalemleri sk
    JOIN urunler u ON sk.urun_id = u.id
    JOIN satislar s ON sk.satis_id = s.id
    WHERE s.tarih BETWEEN ? AND ? AND s.durum != 'iptal'
    GROUP BY u.id
    ORDER BY brut_kar DESC
");
$urunKar->execute([$bas, $bit]);
$urunKar = $urunKar->fetchAll();

// Kategori bazlı kar
$kategoriKar = $pdo->prepare("
    SELECT
        COALESCE(k.ad, 'Kategorisiz') AS kategori,
        SUM(sk.miktar)      AS adet,
        SUM(sk.toplam - sk.kdv_tutar) AS net_satis,
        SUM(sk.miktar * u.alis_fiyati) AS maliyet,
        SUM(sk.toplam - sk.kdv_tutar)
            - SUM(sk.miktar * u.alis_fiyati) AS brut_kar
    FROM satis_kalemleri sk
    JOIN urunler u ON sk.urun_id = u.id
    LEFT JOIN kategoriler k ON u.kategori_id = k.id
    JOIN satislar s ON sk.satis_id = s.id
    WHERE s.tarih BETWEEN ? AND ? AND s.durum != 'iptal'
    GROUP BY k.id
    ORDER BY brut_kar DESC
");
$kategoriKar->execute([$bas, $bit]);
$kategoriKar = $kategoriKar->fetchAll();

// Genel toplam
$genelNetSatis   = array_sum(array_column($urunKar, 'net_satis'));
$genelMaliyet    = array_sum(array_column($urunKar, 'toplam_maliyet'));
$genelBrutKar    = array_sum(array_column($urunKar, 'brut_kar'));
$karMarji        = $genelNetSatis > 0 ? round($genelBrutKar / $genelNetSatis * 100, 1) : 0;

// Kasa giderleri
$giderler = $pdo->prepare("
    SELECT kategori, SUM(tutar) AS toplam
    FROM kasa_hareketleri
    WHERE tip='cikis' AND tarih BETWEEN ? AND ?
    AND kategori NOT IN ('Tahsilat')
    GROUP BY kategori ORDER BY toplam DESC
");
$giderler->execute([$bas, $bit]);
$giderler = $giderler->fetchAll();
$toplamGider = array_sum(array_column($giderler, 'toplam'));
$netKar = $genelBrutKar - $toplamGider;

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kar_zarar_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    // Formül injection önlemi: =,+,-,@ ile başlayan hücrelerin önüne ' konur
    $csvGuvenli = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;
    fputcsv($out, ['Ürün', 'Kod', 'Adet', 'Alış Fiyatı', 'Ort. Satış Fiyatı', 'Net Satış', 'Maliyet', 'Brüt Kâr', 'Kâr Marjı %'], ';');
    foreach ($urunKar as $r) {
        $marj = $r['net_satis'] > 0 ? round($r['brut_kar'] / $r['net_satis'] * 100, 1) : 0;
        fputcsv($out, [
            $csvGuvenli($r['ad']), $csvGuvenli($r['kod']), $r['satis_adedi'],
            number_format($r['alis_fiyati'], 2, ',', '.'),
            number_format($r['ort_satis_fiyati'], 2, ',', '.'),
            number_format($r['net_satis'], 2, ',', '.'),
            number_format($r['toplam_maliyet'], 2, ',', '.'),
            number_format($r['brut_kar'], 2, ',', '.'),
            $marj,
        ], ';');
    }
    fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-graph-up-arrow text-success"></i> Kâr-Zarar Raporu</h4>
    <div class="d-flex gap-2 no-print">
        <a href="?bas=<?= $bas ?>&bit=<?= $bit ?>&export=1" class="btn btn-outline-success btn-sm">
            <i class="bi bi-filetype-csv"></i> CSV İndir
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer"></i> Yazdır
        </button>
    </div>
</div>

<!-- Tarih filtresi -->
<div class="card shadow-sm mb-3 no-print">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-auto">
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= $bas ?>">
            </div>
            <div class="col-auto">
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= $bit ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary">Uygula</button>
                <a href="?bas=<?= date('Y-m-01') ?>&bit=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary">Bu Ay</a>
                <a href="?bas=<?= date('Y-01-01') ?>&bit=<?= date('Y-12-31') ?>" class="btn btn-sm btn-outline-secondary">Bu Yıl</a>
            </div>
        </form>
    </div>
</div>

<!-- Özet -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Net Satış (KDV hariç)</div>
                <div class="fw-bold fs-4"><?= para($genelNetSatis) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-secondary text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Toplam Maliyet</div>
                <div class="fw-bold fs-4"><?= para($genelMaliyet) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 <?= $genelBrutKar >= 0 ? 'bg-success' : 'bg-danger' ?> text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Brüt Kâr</div>
                <div class="fw-bold fs-4"><?= para($genelBrutKar) ?></div>
                <div class="small opacity-75">Marj: %<?= $karMarji ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 <?= $netKar >= 0 ? 'bg-success' : 'bg-danger' ?> text-white">
            <div class="card-body text-center">
                <div class="small opacity-75">Net Kâr (gider sonrası)</div>
                <div class="fw-bold fs-4"><?= para($netKar) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Giderler -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-arrow-down-circle text-danger"></i> Giderler
            </div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Kategori</th><th class="text-end">Tutar</th></tr></thead>
                <tbody>
                <?php if (empty($giderler)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">Gider yok</td></tr>
                <?php else: ?>
                <?php foreach ($giderler as $g): ?>
                <tr>
                    <td><?= escH($g['kategori'] ?? 'Diğer') ?></td>
                    <td class="text-end text-danger fw-bold"><?= para($g['toplam']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-bold table-danger">
                    <td>TOPLAM</td>
                    <td class="text-end"><?= para($toplamGider) ?></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Kategoriye göre kar -->
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-tags text-primary"></i> Kategoriye Göre Kâr
            </div>
            <div class="card-body p-0">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Kategori</th><th>Adet</th><th>Net Satış</th><th>Maliyet</th><th>Brüt Kâr</th><th>Marj</th></tr></thead>
                <tbody>
                <?php foreach ($kategoriKar as $k): $marj = $k['net_satis']>0?round($k['brut_kar']/$k['net_satis']*100,1):0; ?>
                <tr>
                    <td><?= escH($k['kategori']) ?></td>
                    <td><?= $k['adet'] ?></td>
                    <td><?= para($k['net_satis']) ?></td>
                    <td><?= para($k['maliyet']) ?></td>
                    <td class="fw-bold <?= $k['brut_kar']>=0?'text-success':'text-danger' ?>"><?= para($k['brut_kar']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <div class="progress flex-grow-1" style="height:6px">
                                <div class="progress-bar bg-<?= $marj>=30?'success':($marj>=15?'warning':'danger') ?>"
                                     style="width:<?= min(100,max(0,$marj)) ?>%"></div>
                            </div>
                            <small>%<?= $marj ?></small>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- Ürün bazlı detay -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-box-seam text-primary"></i> Ürün Bazlı Kâr Analizi
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
            <tr>
                <th>#</th><th>Ürün</th><th>Kod</th>
                <th class="text-center">Adet</th>
                <th class="text-end">Alış Fiy.</th>
                <th class="text-end">Ort. Satış</th>
                <th class="text-end">Net Satış</th>
                <th class="text-end">Maliyet</th>
                <th class="text-end">Brüt Kâr</th>
                <th class="text-end">Marj</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($urunKar)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Veri yok</td></tr>
        <?php else: ?>
        <?php foreach ($urunKar as $i => $u):
            $marj = $u['net_satis']>0 ? round($u['brut_kar']/$u['net_satis']*100,1) : 0;
        ?>
        <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= escH($u['ad']) ?></td>
            <td><small class="text-muted"><?= escH($u['kod']) ?></small></td>
            <td class="text-center"><?= $u['satis_adedi'] ?></td>
            <td class="text-end small text-muted"><?= para($u['alis_fiyati']) ?></td>
            <td class="text-end small"><?= para($u['ort_satis_fiyati']) ?></td>
            <td class="text-end"><?= para($u['net_satis']) ?></td>
            <td class="text-end text-danger"><?= para($u['toplam_maliyet']) ?></td>
            <td class="text-end fw-bold <?= $u['brut_kar']>=0?'text-success':'text-danger' ?>"><?= para($u['brut_kar']) ?></td>
            <td class="text-end">
                <span class="badge bg-<?= $marj>=30?'success':($marj>=15?'warning':'danger') ?>">%<?= $marj ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
        <!-- Toplam -->
        <tr class="fw-bold table-light">
            <td colspan="6" class="text-end">TOPLAM</td>
            <td class="text-end"><?= para($genelNetSatis) ?></td>
            <td class="text-end text-danger"><?= para($genelMaliyet) ?></td>
            <td class="text-end text-success"><?= para($genelBrutKar) ?></td>
            <td class="text-end"><span class="badge bg-<?= $karMarji>=30?'success':($karMarji>=15?'warning':'danger') ?>">%<?= $karMarji ?></span></td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
