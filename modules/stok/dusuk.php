<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Düşük Stok';
$pdo = db();
$rol = $_SESSION['rol'] ?? '';
$siparisYetkisi = in_array($rol, ['yonetici','kasiyer'], true);

// Son tedarikçi: her ürünün en son tedarikçili stok girişi
$sonTedarikciler = $pdo->query("SELECT h.urun_id, t.id AS tid, t.ad AS tedarikci
    FROM stok_hareketleri h
    JOIN (SELECT urun_id, MAX(id) AS mid FROM stok_hareketleri
          WHERE hareket_tipi='giris' AND tedarikci_id IS NOT NULL GROUP BY urun_id) x ON x.mid=h.id
    JOIN tedarikciler t ON h.tedarikci_id=t.id")->fetchAll(PDO::FETCH_UNIQUE);

$urunler = $pdo->query("SELECT u.*, k.ad AS kategori_adi FROM urunler u
    LEFT JOIN kategoriler k ON u.kategori_id=k.id
    WHERE u.stok_adedi <= u.min_stok AND u.aktif=1 ORDER BY u.stok_adedi ASC")->fetchAll();

// Önerilen sipariş: min. stokun 2 katına tamamla (en az 1)
$oner = fn($u) => max(1, ($u['min_stok'] * 2) - $u['stok_adedi']);

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="dusuk_stok_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kod','Ürün','Kategori','Mevcut','Min. Stok','Eksik','Önerilen Sipariş','Son Tedarikçi'], ';');
    foreach ($urunler as $u) {
        fputcsv($out, [csvHucre($u['kod']), csvHucre($u['ad']), csvHucre($u['kategori_adi'] ?? ''),
            $u['stok_adedi'], $u['min_stok'], max(0, $u['min_stok'] - $u['stok_adedi']), $oner($u),
            csvHucre($sonTedarikciler[$u['id']]['tedarikci'] ?? '')], ';');
    }
    fclose($out); exit;
}

$tedarikciler = $pdo->query("SELECT id, ad FROM tedarikciler ORDER BY ad")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-exclamation-triangle text-danger"></i> Düşük Stok Uyarıları</h4>
    <div class="d-flex gap-2">
        <a href="?export=csv" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
        <button class="btn btn-sm btn-outline-dark" onclick="window.print()"><i class="bi bi-printer"></i> Yazdır</button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Stok</a>
    </div>
</div>

<?php if (empty($urunler)): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> Tüm ürünlerin stoku yeterli.</div>
<?php else: ?>
<div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-exclamation-triangle-fill"></i> <?= count($urunler) ?> ürünün stoku kritik seviyede!</span>
    <?php if ($siparisYetkisi): ?>
    <div class="d-flex gap-2 align-items-center d-print-none">
        <select id="siparisTedarikci" class="form-select form-select-sm" style="width:auto">
            <option value="">Tedarikçi (opsiyonel)...</option>
            <?php foreach ($tedarikciler as $t): ?>
            <option value="<?= $t['id'] ?>"><?= escH($t['ad']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary" onclick="siparisOlustur()">
            <i class="bi bi-clipboard-plus"></i> Seçilenlerle Sipariş Oluştur
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr>
            <?php if ($siparisYetkisi): ?>
            <th style="width:28px" class="d-print-none"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.ds-sec').forEach(c=>c.checked=this.checked)"></th>
            <?php endif; ?>
            <th>Ürün</th><th>Kategori</th><th class="text-center">Mevcut</th><th class="text-center">Min.</th>
            <th class="text-center">Eksik</th><th class="text-center">Önerilen Sipariş</th><th>Son Tedarikçi</th><th class="d-print-none">İşlem</th>
        </tr></thead>
        <tbody>
        <?php foreach ($urunler as $u): ?>
        <?php $st = $sonTedarikciler[$u['id']] ?? null; ?>
        <tr class="<?= $u['stok_adedi'] <= 0 ? 'table-danger' : 'table-warning' ?>">
            <?php if ($siparisYetkisi): ?>
            <td class="d-print-none"><input type="checkbox" class="form-check-input ds-sec" value="<?= $u['id'] ?>" data-oner="<?= $oner($u) ?>"></td>
            <?php endif; ?>
            <td><a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $u['id'] ?>" class="text-decoration-none">
                <strong><?= escH($u['ad']) ?></strong></a><br><small><?= escH($u['kod']) ?></small></td>
            <td><?= escH($u['kategori_adi'] ?? '-') ?></td>
            <td class="text-center"><span class="badge bg-danger fs-6"><?= $u['stok_adedi'] ?></span></td>
            <td class="text-center"><?= $u['min_stok'] ?></td>
            <td class="text-center"><?= max(0, $u['min_stok'] - $u['stok_adedi']) ?></td>
            <td class="text-center fw-bold text-primary"><?= $oner($u) ?> adet</td>
            <td><?= $st ? escH($st['tedarikci']) : '<span class="text-muted">—</span>' ?></td>
            <td class="d-print-none">
                <a href="giris.php?urun_id=<?= $u['id'] ?><?= $st ? '&tedarikci_id='.$st['tid'] : '' ?>" class="btn btn-sm btn-success"><i class="bi bi-plus"></i> Giriş</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<script>
function siparisOlustur() {
    const secili = [...document.querySelectorAll('.ds-sec:checked')];
    if (!secili.length) { alert('Sipariş için ürün seçin.'); return; }
    const parcalar = secili.map(c => c.value + ':' + c.dataset.oner);
    const ted = document.getElementById('siparisTedarikci').value;
    window.location = '<?= BASE_URL ?>/modules/tedarikciler/siparis_ekle.php?on_urunler=' + parcalar.join(',')
        + (ted ? '&tedarikci_id=' + ted : '');
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
