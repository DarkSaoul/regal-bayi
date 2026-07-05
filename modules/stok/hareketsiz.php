<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Hareketsiz Stok';
$pdo = db();

$gun = (int)($_GET['gun'] ?? 90);
if (!in_array($gun, [30, 60, 90, 180, 365], true)) $gun = 90;

// Son N gündür hiç stok hareketi VE satışı olmayan, stokta bekleyen ürünler
$stmt = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi,
        (SELECT MAX(h.created_at) FROM stok_hareketleri h WHERE h.urun_id=u.id) AS son_hareket,
        (SELECT MAX(s.tarih) FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id
         WHERE sk.urun_id=u.id AND s.durum!='iptal') AS son_satis
    FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id
    WHERE u.aktif=1 AND u.stok_adedi > 0
      AND NOT EXISTS (SELECT 1 FROM stok_hareketleri h WHERE h.urun_id=u.id
                      AND h.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY))
      AND NOT EXISTS (SELECT 1 FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id
                      WHERE sk.urun_id=u.id AND s.durum!='iptal'
                      AND s.tarih >= DATE_SUB(CURDATE(), INTERVAL ? DAY))
    ORDER BY (u.stok_adedi * u.alis_fiyati) DESC");
$stmt->execute([$gun, $gun]);
$urunler = $stmt->fetchAll();

$baglananPara = 0;
foreach ($urunler as $u) $baglananPara += $u['stok_adedi'] * $u['alis_fiyati'];

// CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="hareketsiz_stok_' . $gun . 'gun_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kod','Ürün','Kategori','Stok','Alış Fiyatı','Bağlanan Para','Son Hareket','Son Satış'], ';');
    foreach ($urunler as $u) {
        fputcsv($out, [csvHucre($u['kod']), csvHucre($u['ad']), csvHucre($u['kategori_adi'] ?? ''),
            $u['stok_adedi'], number_format($u['alis_fiyati'], 2, ',', '.'),
            number_format($u['stok_adedi'] * $u['alis_fiyati'], 2, ',', '.'),
            $u['son_hareket'] ?? '', $u['son_satis'] ?? ''], ';');
    }
    fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-hourglass-split text-primary"></i> Hareketsiz Stok (Ölü Stok)</h4>
    <div class="d-flex gap-2 align-items-center">
        <form method="get" class="d-flex gap-2 align-items-center">
            <label class="small text-muted text-nowrap">Süre:</label>
            <select name="gun" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ([30, 60, 90, 180, 365] as $g): ?>
                <option value="<?= $g ?>" <?= $gun===$g?'selected':'' ?>><?= $g ?> gün</option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="?gun=<?= $gun ?>&export=csv" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Stok</a>
    </div>
</div>

<?php if (!$urunler): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> Son <?= $gun ?> günde tüm stoklu ürünlerde hareket var — ölü stok yok.</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong><?= count($urunler) ?> ürün</strong> son <?= $gun ?> gündür hiç hareket görmedi.
    Bu ürünlerde bağlı duran para: <strong><?= para($baglananPara) ?></strong> (alış maliyeti üzerinden).
    İndirim, teşhir veya tedarikçiye iade düşünülebilir.
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr>
            <th>Kod</th><th>Ürün</th><th>Kategori</th>
            <th class="text-center">Stok</th><th class="text-end">Bağlanan Para</th>
            <th>Son Hareket</th><th>Son Satış</th><th>İşlem</th>
        </tr></thead>
        <tbody>
        <?php foreach ($urunler as $u): ?>
        <tr>
            <td><code><?= escH($u['kod']) ?></code></td>
            <td><a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $u['id'] ?>" class="text-decoration-none fw-semibold"><?= escH($u['ad']) ?></a></td>
            <td><?= escH($u['kategori_adi'] ?? '-') ?></td>
            <td class="text-center"><span class="badge bg-secondary"><?= $u['stok_adedi'] ?></span></td>
            <td class="text-end fw-bold"><?= para($u['stok_adedi'] * $u['alis_fiyati']) ?></td>
            <td class="text-nowrap"><?= $u['son_hareket'] ? tarih($u['son_hareket']) : '<span class="text-muted">Hiç</span>' ?></td>
            <td class="text-nowrap"><?= $u['son_satis'] ? tarih($u['son_satis']) : '<span class="text-muted">Hiç</span>' ?></td>
            <td class="text-nowrap">
                <?php if (($_SESSION['rol'] ?? '') === 'yonetici'): ?>
                <a href="<?= BASE_URL ?>/modules/urunler/toplu_fiyat.php?ids=<?= $u['id'] ?>" class="btn btn-sm btn-outline-warning" title="İndirim yap"><i class="bi bi-percent"></i></a>
                <?php endif; ?>
                <a href="cikis.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger" title="Tedarikçiye iade / fire"><i class="bi bi-box-arrow-up"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
