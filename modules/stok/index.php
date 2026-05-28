<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Stok Takibi';
$pdo = db();

$arama = trim($_GET['ara'] ?? '');
$where = "WHERE u.aktif=1";
$params = [];
if ($arama) { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ?)"; $params = [likeParam($arama),likeParam($arama)]; }

$urunler = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where ORDER BY u.stok_adedi ASC");
$urunler->execute($params);
$urunler = $urunler->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-archive text-primary"></i> Stok Takibi</h4>
    <div class="d-flex gap-2">
        <a href="giris.php" class="btn btn-success"><i class="bi bi-box-arrow-in-down"></i> Stok Giriş</a>
        <a href="cikis.php" class="btn btn-outline-danger"><i class="bi bi-box-arrow-up"></i> Stok Çıkış</a>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2" method="get">
            <div class="col-md-6">
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ürün adı veya kod..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i> Ara</button>
            </div>
            <div class="col-md-4 text-end">
                <a href="hareketler.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list-ul"></i> Tüm Hareketler
                </a>
                <a href="dusuk.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-exclamation-triangle"></i> Düşük Stok
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Kod</th><th>Ürün Adı</th><th>Kategori</th>
                <th class="text-center">Mevcut Stok</th>
                <th class="text-center">Min. Stok</th>
                <th class="text-center">Durum</th>
                <th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php foreach ($urunler as $u): ?>
            <?php $durum = $u['stok_adedi'] <= 0 ? 'Tükendi' : ($u['stok_adedi'] <= $u['min_stok'] ? 'Kritik' : 'Normal'); ?>
            <?php $renk  = $u['stok_adedi'] <= 0 ? 'danger' : ($u['stok_adedi'] <= $u['min_stok'] ? 'warning' : 'success'); ?>
            <tr class="<?= $u['stok_adedi'] <= $u['min_stok'] ? 'table-warning' : '' ?>">
                <td><code><?= escH($u['kod']) ?></code></td>
                <td><?= escH($u['ad']) ?></td>
                <td><?= escH($u['kategori_adi'] ?? '-') ?></td>
                <td class="text-center fw-bold fs-5"><?= $u['stok_adedi'] ?></td>
                <td class="text-center"><?= $u['min_stok'] ?></td>
                <td class="text-center"><span class="badge bg-<?= $renk ?>"><?= $durum ?></span></td>
                <td>
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
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
