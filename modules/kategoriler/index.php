<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Kategoriler';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'ekle') {
        $ad = trim($_POST['ad'] ?? '');
        $ust = (int)($_POST['ust_id'] ?? 0) ?: null;
        if ($ad) {
            $pdo->prepare("INSERT INTO kategoriler (ad, ust_id) VALUES (?,?)")->execute([$ad, $ust]);
            flash('basari', '"'.$ad.'" kategorisi eklendi.');
        }
    } elseif ($aksiyon === 'duzenle') {
        $id  = (int)$_POST['id'];
        $ad  = trim($_POST['ad'] ?? '');
        $ust = (int)($_POST['ust_id'] ?? 0) ?: null;
        if ($id && $ad && $ust != $id) { // kendisini üst yapamaz
            $pdo->prepare("UPDATE kategoriler SET ad=?, ust_id=? WHERE id=?")->execute([$ad, $ust, $id]);
            flash('basari', 'Kategori güncellendi.');
        }
    } elseif ($aksiyon === 'sil') {
        $id = (int)$_POST['id'];
        // Alt kategorisi veya ürünü varsa silme
        $alt   = $pdo->prepare("SELECT COUNT(*) FROM kategoriler WHERE ust_id=?"); $alt->execute([$id]);
        $urun  = $pdo->prepare("SELECT COUNT(*) FROM urunler WHERE kategori_id=?"); $urun->execute([$id]);
        if ((int)$alt->fetchColumn() > 0)  { flash('hata', 'Alt kategorileri olan kategori silinemez.'); }
        elseif ((int)$urun->fetchColumn() > 0) { flash('hata', 'Ürünü olan kategori silinemez.'); }
        else { $pdo->prepare("DELETE FROM kategoriler WHERE id=?")->execute([$id]); flash('basari', 'Kategori silindi.'); }
    }
    header('Location: index.php'); exit;
}

// Ana kategoriler
$ustler = $pdo->query("SELECT * FROM kategoriler WHERE ust_id IS NULL ORDER BY ad")->fetchAll();
// Tüm kategoriler (düzenleme için)
$tumKategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id, ad")->fetchAll();
// Ürün sayıları
$urunSayilari = $pdo->query("SELECT kategori_id, COUNT(*) AS sayi FROM urunler WHERE aktif=1 GROUP BY kategori_id")->fetchAll(PDO::FETCH_KEY_PAIR);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-tags text-primary"></i> Kategoriler</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#kategoriEkleModal">
        <i class="bi bi-plus-circle"></i> Yeni Kategori
    </button>
</div>

<div class="row g-3">
<?php foreach ($ustler as $ust): ?>
<?php
$altlar = array_filter($tumKategoriler, fn($k) => $k['ust_id'] == $ust['id']);
?>
<div class="col-md-6 col-xl-4">
    <div class="card shadow-sm h-100">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold">
                <i class="bi bi-folder-fill text-warning me-1"></i>
                <?= escH($ust['ad']) ?>
                <span class="badge bg-secondary ms-1"><?= $urunSayilari[$ust['id']] ?? 0 ?> ürün</span>
            </span>
            <div class="d-flex gap-1">
                <button class="btn btn-xs btn-outline-primary btn-sm py-0 px-2"
                        onclick="kategoriDuzenle(<?= $ust['id'] ?>, '<?= escH(addslashes($ust['ad'])) ?>', '')">
                    <i class="bi bi-pencil"></i>
                </button>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksiyon" value="sil">
                    <input type="hidden" name="id" value="<?= $ust['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger btn-sm py-0 px-2"
                            onclick="return confirm('Kategoriyi silmek istediğinize emin misiniz?')">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
        </div>
        <?php if (!empty($altlar)): ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($altlar as $alt): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                <span>
                    <i class="bi bi-folder text-muted me-1"></i>
                    <?= escH($alt['ad']) ?>
                    <span class="badge bg-light text-secondary ms-1"><?= $urunSayilari[$alt['id']] ?? 0 ?></span>
                </span>
                <div class="d-flex gap-1">
                    <button class="btn btn-xs btn-outline-primary btn-sm py-0 px-2"
                            onclick="kategoriDuzenle(<?= $alt['id'] ?>, '<?= escH(addslashes($alt['ad'])) ?>', <?= $alt['ust_id'] ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="aksiyon" value="sil">
                        <input type="hidden" name="id" value="<?= $alt['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger btn-sm py-0 px-2"
                                onclick="return confirm('Kategoriyi silmek istediğinize emin misiniz?')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="card-body py-2 text-muted small">Alt kategori yok</div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Ekle Modal -->
<div class="modal fade" id="kategoriEkleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="ekle">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary"></i> Yeni Kategori</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Kategori Adı *</label>
                        <input type="text" name="ad" class="form-control" required autofocus maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Üst Kategori <small class="text-muted">(boş = ana kategori)</small></label>
                        <select name="ust_id" class="form-select">
                            <option value="">— Ana Kategori —</option>
                            <?php foreach ($ustler as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= escH($u['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Düzenle Modal -->
<div class="modal fade" id="kategoriDuzenleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="duzenle">
                <input type="hidden" name="id" id="duzenleId">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-pencil text-primary"></i> Kategori Düzenle</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Kategori Adı *</label>
                        <input type="text" name="ad" id="duzenleAd" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Üst Kategori</label>
                        <select name="ust_id" id="duzenleUst" class="form-select">
                            <option value="">— Ana Kategori —</option>
                            <?php foreach ($ustler as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= escH($u['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function kategoriDuzenle(id, ad, ustId) {
    document.getElementById('duzenleId').value  = id;
    document.getElementById('duzenleAd').value  = ad;
    document.getElementById('duzenleUst').value = ustId || '';
    new bootstrap.Modal(document.getElementById('kategoriDuzenleModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
