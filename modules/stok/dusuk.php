<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Düşük Stok';
$pdo = db();
$urunler = $pdo->query("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id WHERE u.stok_adedi <= u.min_stok AND u.aktif=1 ORDER BY u.stok_adedi ASC")->fetchAll();
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-exclamation-triangle text-danger"></i> Düşük Stok Uyarıları</h4>
</div>
<?php if (empty($urunler)): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> Tüm ürünlerin stoku yeterli.</div>
<?php else: ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= count($urunler) ?> ürünün stoku kritik seviyede!</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>Ürün</th><th>Kategori</th><th>Mevcut Stok</th><th>Min. Stok</th><th>Eksik</th><th>İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($urunler as $u): ?>
        <tr class="<?= $u['stok_adedi'] <= 0 ? 'table-danger' : 'table-warning' ?>">
            <td><strong><?= escH($u['ad']) ?></strong><br><small><?= escH($u['kod']) ?></small></td>
            <td><?= escH($u['kategori_adi']??'-') ?></td>
            <td><span class="badge bg-danger fs-6"><?= $u['stok_adedi'] ?></span></td>
            <td><?= $u['min_stok'] ?></td>
            <td><?= max(0, $u['min_stok'] - $u['stok_adedi']) ?> adet</td>
            <td><a href="giris.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-success"><i class="bi bi-plus"></i> Stok Giriş</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
