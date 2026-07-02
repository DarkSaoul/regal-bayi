<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Ürünler';
$pdo = db();

$arama  = trim($_GET['ara'] ?? '');
$kat    = (int)($_GET['kat'] ?? 0);
$sayfa  = max(1, (int)($_GET['s'] ?? 1));
$limit  = 20;
$offset = ($sayfa - 1) * $limit;

$where = "WHERE u.aktif=1";
$params = [];
if ($arama) { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ?)"; $params = array_merge($params, [likeParam($arama),likeParam($arama),likeParam($arama)]); }
if ($kat)    { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params = array_merge($params, [$kat, $kat]); }

$toplamStmt = $pdo->prepare("SELECT COUNT(*) FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where");
$toplamStmt->execute($params);
$toplam = (int)$toplamStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where ORDER BY u.id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$urunler = $stmt->fetchAll();

$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id, ad")->fetchAll();
$sayfaSayisi = ceil($toplam / $limit);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-box-seam text-primary"></i> Ürünler</h4>
    <a href="ekle.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Ürün</a>
</div>

<!-- Filtre -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-5">
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ürün adı, kod veya barkod ara..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-4">
                <select name="kat" class="form-select form-select-sm">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach ($kategoriler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $kat==$k['id']?'selected':'' ?>>
                        <?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
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
                <th>Kod</th><th>Ürün Adı</th><th>Kategori</th>
                <th>Alış</th><th>Satış</th><th>KDV</th>
                <th>Stok</th><th>Min.Stok</th><th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($urunler)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Ürün bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($urunler as $u): ?>
            <tr>
                <td><code><?= escH($u['kod']) ?></code></td>
                <td>
                    <strong><?= escH($u['ad']) ?></strong>
                    <?php if ($u['model']): ?><br><small class="text-muted"><?= escH($u['model']) ?></small><?php endif; ?>
                </td>
                <td><?= escH($u['kategori_adi'] ?? '-') ?></td>
                <td><?= para($u['alis_fiyati']) ?></td>
                <td class="fw-semibold text-primary"><?= para($u['satis_fiyati']) ?></td>
                <td>%<?= $u['kdv_orani'] ?></td>
                <td>
                    <?php $renk = $u['stok_adedi'] <= $u['min_stok'] ? 'danger' : 'success'; ?>
                    <span class="badge bg-<?= $renk ?>"><?= $u['stok_adedi'] ?></span>
                </td>
                <td><?= $u['min_stok'] ?></td>
                <td>
                    <a href="duzenle.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" title="Düzenle">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <a href="<?= BASE_URL ?>/modules/stok/giris.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-success" title="Stok Giriş">
                        <i class="bi bi-plus"></i>
                    </a>
                    <form method="post" action="sil.php" class="d-inline"
                          onsubmit="return confirm('Bu ürünü silmek istediğinize emin misiniz?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($sayfaSayisi > 1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end">
            <?php for ($i=1; $i<=$sayfaSayisi; $i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>">
                <a class="page-link" href="?ara=<?= urlencode($arama) ?>&kat=<?= $kat ?>&s=<?= $i ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<div class="mt-2 text-muted small">Toplam <?= $toplam ?> ürün listeleniyor</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
