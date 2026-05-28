<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Müşteriler';
$pdo = db();

$arama = trim($_GET['ara'] ?? '');
$params = [];
$where = "WHERE 1=1";
if ($arama) {
    $where .= " AND (ad LIKE ? OR soyad LIKE ? OR firma_adi LIKE ? OR telefon LIKE ? OR tc_no LIKE ?)";
    $like = likeParam($arama);
    $params = [$like, $like, $like, $like, $like];
}

$musteriler = $pdo->prepare("SELECT * FROM musteriler $where ORDER BY id DESC");
$musteriler->execute($params);
$musteriler = $musteriler->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-people text-primary"></i> Müşteriler</h4>
    <a href="ekle.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Yeni Müşteri</a>
</div>
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2" method="get">
            <div class="col-md-6">
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ad, soyad, firma, telefon veya TC No..." value="<?= escH($arama) ?>">
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
                <th>Tip</th><th>Ad Soyad / Firma</th><th>Telefon</th>
                <th>Şehir</th><th>Borç</th><th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($musteriler)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Müşteri bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($musteriler as $m): ?>
            <tr>
                <td>
                    <span class="badge bg-<?= $m['tip']==='kurumsal'?'info':'secondary' ?>">
                        <?= $m['tip']==='kurumsal'?'Kurumsal':'Bireysel' ?>
                    </span>
                </td>
                <td>
                    <strong><?= escH($m['ad'] . ' ' . ($m['soyad']??'')) ?></strong>
                    <?php if ($m['firma_adi']): ?><br><small class="text-muted"><?= escH($m['firma_adi']) ?></small><?php endif; ?>
                </td>
                <td><?= escH($m['telefon'] ?: '-') ?></td>
                <td><?= escH($m['sehir'] ?: '-') ?></td>
                <td class="<?= $m['toplam_borc']>0?'text-danger fw-bold':'' ?>">
                    <?= $m['toplam_borc']>0 ? para($m['toplam_borc']) : '-' ?>
                </td>
                <td>
                    <a href="detay.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detay"><i class="bi bi-eye"></i></a>
                    <a href="duzenle.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Düzenle"><i class="bi bi-pencil"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
