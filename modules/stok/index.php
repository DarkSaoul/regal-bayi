<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Stok Takibi';
$pdo = db();

$arama  = trim($_GET['ara'] ?? '');
$kat    = (int)($_GET['kat'] ?? 0);
$marka  = trim($_GET['marka'] ?? '');
$durumF = $_GET['durum'] ?? '';
if (!in_array($durumF, ['','tukendi','kritik','normal','tesirde'], true)) $durumF = '';
$limit  = (int)($_GET['adet'] ?? 50);
if (!in_array($limit, [25,50,100,200], true)) $limit = 50;
$sayfa  = max(1, (int)($_GET['s'] ?? 1));
$offset = ($sayfa - 1) * $limit;

$siralamalar = [
    'stok'  => 'u.stok_adedi',
    'ad'    => 'u.ad',
    'kod'   => 'u.kod',
    'deger' => '(u.stok_adedi * u.alis_fiyati)',
    'min'   => 'u.min_stok',
];
$srl = isset($siralamalar[$_GET['srl'] ?? '']) ? $_GET['srl'] : 'stok';
$yon = ($_GET['yon'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$where = "WHERE u.aktif=1";
$params = [];
if ($arama) { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ?)"; $params = array_fill(0, 3, likeParam($arama)); }
if ($kat)   { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params[] = $kat; $params[] = $kat; }
if ($marka) { $where .= " AND u.marka=?"; $params[] = $marka; }
if ($durumF === 'tukendi') $where .= " AND u.stok_adedi <= 0";
if ($durumF === 'kritik')  $where .= " AND u.stok_adedi > 0 AND u.stok_adedi <= u.min_stok";
if ($durumF === 'normal')  $where .= " AND u.stok_adedi > u.min_stok";
if ($durumF === 'tesirde') $where .= " AND u.tesir_adedi > 0";

// CSV export (filtreli)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where ORDER BY u.ad");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stok_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kod','Barkod','Ürün','Kategori','Marka','Depo','Teşhir','Toplam Stok','Min. Stok','Birim','Alış Fiyatı','Stok Değeri (Alış)','Durum'], ';');
    foreach ($stmt->fetchAll() as $u) {
        $durum = $u['stok_adedi'] <= 0 ? 'Tükendi' : ($u['stok_adedi'] <= $u['min_stok'] ? 'Kritik' : 'Normal');
        fputcsv($out, [csvHucre($u['kod']), csvHucre($u['barkod']), csvHucre($u['ad']), csvHucre($u['kategori_adi'] ?? ''),
            csvHucre($u['marka']), $u['stok_adedi'] - $u['tesir_adedi'], $u['tesir_adedi'], $u['stok_adedi'], $u['min_stok'],
            csvHucre($u['birim']), number_format($u['alis_fiyati'], 2, ',', '.'),
            number_format($u['stok_adedi'] * $u['alis_fiyati'], 2, ',', '.'), $durum], ';');
    }
    fclose($out); exit;
}

// Özet (filtreli küme)
$ozetStmt = $pdo->prepare("SELECT COUNT(*) AS cesit, COALESCE(SUM(u.stok_adedi),0) AS stok,
        COALESCE(SUM(u.tesir_adedi),0) AS tesir,
        COALESCE(SUM(u.stok_adedi*u.alis_fiyati),0) AS deger_alis,
        COALESCE(SUM(u.stok_adedi*u.satis_fiyati),0) AS deger_satis,
        SUM(CASE WHEN u.stok_adedi <= 0 THEN 1 ELSE 0 END) AS tukenen,
        SUM(CASE WHEN u.stok_adedi > 0 AND u.stok_adedi <= u.min_stok THEN 1 ELSE 0 END) AS kritik
    FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where");
$ozetStmt->execute($params);
$ozet = $ozetStmt->fetch();
$toplam = (int)$ozet['cesit'];
$sayfaSayisi = max(1, ceil($toplam / $limit));

$orderBy = $siralamalar[$srl] . ' ' . strtoupper($yon);
$stmt = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id
    $where ORDER BY $orderBy, u.ad LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$urunler = $stmt->fetchAll();

$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id IS NOT NULL, sira, ad")->fetchAll();
$markalar = $pdo->query("SELECT DISTINCT marka FROM urunler WHERE aktif=1 AND marka!='' ORDER BY marka")->fetchAll(PDO::FETCH_COLUMN);

// Teşhir tutarsızlığı: teşhir adedi toplam stoktan büyük olamaz
$tutarsizlar = $pdo->query("SELECT kod, ad, stok_adedi, tesir_adedi FROM urunler WHERE aktif=1 AND tesir_adedi > stok_adedi")->fetchAll();

function qs(array $d = []): string {
    $q = array_merge(['ara'=>$_GET['ara'] ?? '', 'kat'=>$_GET['kat'] ?? '', 'marka'=>$_GET['marka'] ?? '',
        'durum'=>$_GET['durum'] ?? '', 'adet'=>$_GET['adet'] ?? '', 'srl'=>$_GET['srl'] ?? '', 'yon'=>$_GET['yon'] ?? '', 's'=>$_GET['s'] ?? ''], $d);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
function srlBaslik(string $anahtar, string $etiket): string {
    global $srl, $yon;
    $yeniYon = ($srl === $anahtar && $yon === 'asc') ? 'desc' : 'asc';
    $ok = $srl === $anahtar ? ($yon === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '';
    return '<a class="text-decoration-none text-dark" href="?' . qs(['srl'=>$anahtar, 'yon'=>$yeniYon, 's'=>1]) . '">' . $etiket . $ok . '</a>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-archive text-primary"></i> Stok Takibi</h4>
    <div class="d-flex flex-wrap gap-2">
        <a href="hareketler.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-ul"></i> Hareketler</a>
        <a href="sayim.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-clipboard-check"></i> Sayım</a>
        <a href="hareketsiz.php" class="btn btn-sm btn-outline-dark"><i class="bi bi-hourglass-split"></i> Hareketsiz</a>
        <a href="dusuk.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-exclamation-triangle"></i> Düşük Stok</a>
        <a href="tesir.php" class="btn btn-sm btn-outline-warning"><i class="bi bi-shop-window"></i> Teşhir</a>
        <a href="?<?= qs(['export'=>'csv','s'=>'']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="giris.php" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down"></i> Stok Giriş</a>
        <a href="cikis.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-up"></i> Çıkış</a>
    </div>
</div>

<?php if ($tutarsizlar): ?>
<div class="alert alert-danger py-2">
    <i class="bi bi-exclamation-octagon-fill"></i> <strong>Teşhir tutarsızlığı:</strong>
    <?php foreach ($tutarsizlar as $t): ?>
    <code><?= escH($t['kod']) ?></code> (<?= escH($t['ad']) ?>: teşhir <?= $t['tesir_adedi'] ?> &gt; stok <?= $t['stok_adedi'] ?>)
    <?php endforeach; ?>
    — <a href="tesir.php">Teşhir yönetiminden düzeltin</a>.
</div>
<?php endif; ?>

<!-- Özet kartları -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Çeşit / Adet</div>
            <div class="fw-bold"><?= $toplam ?> / <?= (int)$ozet['stok'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Değer (Alış)</div>
            <div class="fw-bold text-danger"><?= para($ozet['deger_alis']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Değer (Satış)</div>
            <div class="fw-bold text-success"><?= para($ozet['deger_satis']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Teşhirde</div>
            <div class="fw-bold text-warning"><?= (int)$ozet['tesir'] ?> adet</div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <a href="?<?= qs(['durum'=>'kritik','s'=>1]) ?>" class="text-decoration-none">
        <div class="card shadow-sm h-100 <?= $ozet['kritik'] ? 'border-warning' : '' ?>"><div class="card-body py-2">
            <div class="small text-muted">Kritik</div>
            <div class="fw-bold text-warning"><?= (int)$ozet['kritik'] ?> ürün</div>
        </div></div></a>
    </div>
    <div class="col-6 col-md-2">
        <a href="?<?= qs(['durum'=>'tukendi','s'=>1]) ?>" class="text-decoration-none">
        <div class="card shadow-sm h-100 <?= $ozet['tukenen'] ? 'border-danger' : '' ?>"><div class="card-body py-2">
            <div class="small text-muted">Tükenen</div>
            <div class="fw-bold text-danger"><?= (int)$ozet['tukenen'] ?> ürün</div>
        </div></div></a>
    </div>
</div>

<!-- Filtreler -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <div class="input-group input-group-sm">
                    <input type="text" name="ara" id="stokAra" class="form-control" placeholder="Ad, kod veya barkod..." value="<?= escH($arama) ?>">
                    <button type="button" class="btn btn-outline-success" title="Kamera ile barkod tara"
                            onclick="BarcodeScanner.start(v => { document.getElementById('stokAra').value = v; document.getElementById('stokAra').form.submit(); })">
                        <i class="bi bi-camera"></i>
                    </button>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="kat" class="form-select form-select-sm">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach ($kategoriler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $kat==$k['id']?'selected':'' ?>><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="marka" class="form-select form-select-sm">
                    <option value="">Tüm Markalar</option>
                    <?php foreach ($markalar as $m): ?>
                    <option value="<?= escH($m) ?>" <?= $marka===$m?'selected':'' ?>><?= escH($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="durum" class="form-select form-select-sm">
                    <option value="">Durum: Tümü</option>
                    <option value="tukendi" <?= $durumF==='tukendi'?'selected':'' ?>>Tükendi</option>
                    <option value="kritik" <?= $durumF==='kritik'?'selected':'' ?>>Kritik</option>
                    <option value="normal" <?= $durumF==='normal'?'selected':'' ?>>Normal</option>
                    <option value="tesirde" <?= $durumF==='tesirde'?'selected':'' ?>>Teşhirde olanlar</option>
                </select>
            </div>
            <div class="col-3 col-md-1">
                <select name="adet" class="form-select form-select-sm">
                    <?php foreach ([25,50,100,200] as $a): ?>
                    <option value="<?= $a ?>" <?= $limit===$a?'selected':'' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-3 col-md-1">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th><?= srlBaslik('kod','Kod') ?></th>
                <th><?= srlBaslik('ad','Ürün Adı') ?></th>
                <th>Kategori</th>
                <th class="text-center">Depo</th>
                <th class="text-center">Teşhir</th>
                <th class="text-center"><?= srlBaslik('stok','Toplam') ?></th>
                <th class="text-center"><?= srlBaslik('min','Min.') ?></th>
                <th class="text-end"><?= srlBaslik('deger','Değer (Alış)') ?></th>
                <th class="text-center">Durum</th>
                <th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (!$urunler): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Ürün bulunamadı</td></tr>
            <?php endif; ?>
            <?php foreach ($urunler as $u):
                $tesir  = (int)$u['tesir_adedi'];
                $depoda = $u['stok_adedi'] - $tesir;
                $durum  = $u['stok_adedi'] <= 0 ? 'Tükendi' : ($u['stok_adedi'] <= $u['min_stok'] ? 'Kritik' : 'Normal');
                $renk   = $u['stok_adedi'] <= 0 ? 'danger' : ($u['stok_adedi'] <= $u['min_stok'] ? 'warning' : 'success');
            ?>
            <tr class="<?= $u['stok_adedi'] <= $u['min_stok'] ? 'table-warning' : '' ?>">
                <td><code><?= escH($u['kod']) ?></code></td>
                <td>
                    <a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $u['id'] ?>" class="text-decoration-none fw-semibold"><?= escH($u['ad']) ?></a>
                    <?php if ($tesir > 0): ?>
                    <span class="badge bg-warning text-dark ms-1" title="Teşhirde"><i class="bi bi-shop-window"></i> <?= $tesir ?></span>
                    <?php endif; ?>
                </td>
                <td><?= escH($u['kategori_adi'] ?? '-') ?></td>
                <td class="text-center fw-bold"><?= $depoda ?></td>
                <td class="text-center"><?= $tesir > 0 ? '<span class="fw-bold text-warning">'.$tesir.'</span>' : '<span class="text-muted">—</span>' ?></td>
                <td class="text-center fw-bold fs-5"><?= $u['stok_adedi'] ?></td>
                <td class="text-center"><?= $u['min_stok'] ?></td>
                <td class="text-end"><?= para($u['stok_adedi'] * $u['alis_fiyati']) ?></td>
                <td class="text-center"><span class="badge bg-<?= $renk ?>"><?= $durum ?></span></td>
                <td class="text-nowrap">
                    <a href="giris.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-success" title="Giriş"><i class="bi bi-plus"></i></a>
                    <a href="cikis.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger" title="Çıkış"><i class="bi bi-dash"></i></a>
                    <a href="hareketler.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Hareketler"><i class="bi bi-clock-history"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($sayfaSayisi > 1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end flex-wrap">
            <?php for ($i=1; $i<=$sayfaSayisi; $i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>"><a class="page-link" href="?<?= qs(['s'=>$i]) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<div class="mt-2 text-muted small">Toplam <?= $toplam ?> ürün listeleniyor</div>

<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
