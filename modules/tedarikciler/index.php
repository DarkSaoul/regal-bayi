<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Tedarikçiler';
$pdo = db();

$arama = trim($_GET['ara'] ?? '');
$where = "WHERE 1=1";
$params = [];
if ($arama) {
    $where .= " AND (t.ad LIKE ? OR t.yetkili LIKE ? OR t.telefon LIKE ? OR t.vergi_no LIKE ?)";
    $params = array_fill(0, 4, likeParam($arama));
}

$tedarikciler = $pdo->prepare("
    SELECT t.*,
        COUNT(DISTINCT sh.id) AS giris_sayisi,
        COALESCE(SUM(CASE WHEN sh.hareket_tipi='giris' THEN sh.miktar ELSE 0 END), 0) AS toplam_giris
    FROM tedarikciler t
    LEFT JOIN stok_hareketleri sh ON sh.tedarikci_id = t.id
    $where
    GROUP BY t.id
    ORDER BY t.ad
");
$tedarikciler->execute($params);
$tedarikciler = $tedarikciler->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-truck text-primary"></i> Tedarikçiler</h4>
    <a href="ekle.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Tedarikçi</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2" method="get">
            <div class="col-md-6">
                <input type="text" name="ara" class="form-control form-control-sm"
                       placeholder="Ad, yetkili, telefon veya vergi no..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i> Ara</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Firma Adı</th>
                <th>Yetkili Kişi</th>
                <th>Telefon</th>
                <th>E-posta</th>
                <th>Vergi No</th>
                <th class="text-center">Stok Giriş</th>
                <th class="text-center">Toplam Adet</th>
                <th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($tedarikciler)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Tedarikçi bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($tedarikciler as $t): ?>
            <tr>
                <td><strong><?= escH($t['ad']) ?></strong></td>
                <td><?= escH($t['yetkili'] ?: '-') ?></td>
                <td><?= escH($t['telefon'] ?: '-') ?></td>
                <td><?= escH($t['email'] ?: '-') ?></td>
                <td><code><?= escH($t['vergi_no'] ?: '-') ?></code></td>
                <td class="text-center">
                    <span class="badge bg-info"><?= $t['giris_sayisi'] ?> işlem</span>
                </td>
                <td class="text-center fw-bold"><?= number_format($t['toplam_giris']) ?> adet</td>
                <td>
                    <a href="detay.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detay">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a href="duzenle.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Düzenle">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <a href="sil.php?id=<?= $t['id'] ?>"
                       class="btn btn-sm btn-outline-danger" title="Sil"
                       onclick="return confirm('Bu tedarikçiyi silmek istediğinize emin misiniz?')">
                        <i class="bi bi-trash"></i>
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
<div class="mt-2 text-muted small">Toplam <?= count($tedarikciler) ?> tedarikçi</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
