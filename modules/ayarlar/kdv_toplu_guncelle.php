<?php
// KDV oranı toplu güncelleme sihirbazı (mevzuat değişikliği vb.)
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'KDV Oranı Toplu Güncelleme';
$pdo = db();

$kategoriler = $pdo->query("SELECT id, ad, ust_id FROM kategoriler ORDER BY ust_id, ad")->fetchAll();
$markalar = $pdo->query("SELECT DISTINCT marka FROM urunler WHERE marka IS NOT NULL AND marka != '' ORDER BY marka")->fetchAll(PDO::FETCH_COLUMN);

$hata = ''; $onizleme = []; $etkilenenSayi = 0;
$kapsam = $_REQUEST['kapsam'] ?? 'tumu';
$kategoriId = (int)($_REQUEST['kategori_id'] ?? 0);
$marka = trim($_REQUEST['marka'] ?? '');
$eskiKdv = $_REQUEST['eski_kdv'] ?? '';
$yeniKdv = $_REQUEST['yeni_kdv'] ?? '';

function kdvWhere(string $kapsam, int $kategoriId, string $marka, string $eskiKdv): array {
    $where = "WHERE aktif=1"; $params = [];
    if ($kapsam === 'kategori' && $kategoriId) {
        $where .= " AND (kategori_id=? OR kategori_id IN (SELECT id FROM kategoriler WHERE ust_id=?))";
        $params[] = $kategoriId; $params[] = $kategoriId;
    } elseif ($kapsam === 'marka' && $marka) {
        $where .= " AND marka=?"; $params[] = $marka;
    }
    if ($eskiKdv !== '' && is_numeric($eskiKdv)) { $where .= " AND kdv_orani=?"; $params[] = (float)$eskiKdv; }
    return [$where, $params];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $asama = $_POST['asama'] ?? 'onizle';
    if ($yeniKdv === '' || !is_numeric($yeniKdv) || (float)$yeniKdv < 0 || (float)$yeniKdv > 100) {
        $hata = 'Geçerli bir yeni KDV oranı girin (0-100).';
    } else {
        [$where, $params] = kdvWhere($kapsam, $kategoriId, $marka, $eskiKdv);
        if ($asama === 'onizle') {
            $stmt = $pdo->prepare("SELECT id, kod, ad, kdv_orani FROM urunler $where ORDER BY ad LIMIT 500");
            $stmt->execute($params);
            $onizleme = $stmt->fetchAll();
            $sayimStmt = $pdo->prepare("SELECT COUNT(*) FROM urunler $where");
            $sayimStmt->execute($params);
            $etkilenenSayi = (int)$sayimStmt->fetchColumn();
        } elseif ($asama === 'uygula') {
            $pdo->beginTransaction();
            try {
                $guncelle = $pdo->prepare("UPDATE urunler SET kdv_orani=? $where");
                $guncelle->execute(array_merge([(float)$yeniKdv], $params));
                $adet = $guncelle->rowCount();
                $pdo->commit();
                logla('kdv_toplu_guncelle', 'ayarlar', 0, "$adet ürün → %$yeniKdv KDV" . ($eskiKdv !== '' ? " (eski: %$eskiKdv)" : ''));
                flash('basari', "$adet ürünün KDV oranı %$yeniKdv olarak güncellendi.");
                header('Location: kdv_toplu_guncelle.php'); exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $hata = 'Güncelleme sırasında hata: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-receipt-cutoff text-primary"></i> KDV Oranı Toplu Güncelleme</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Ayarlar</a>
</div>

<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle"></i> Bu işlem seçilen kapsamdaki ürünlerin <strong>satış fiyatını değiştirmez</strong>, yalnızca <code>kdv_orani</code> alanını günceller.
    Fiyat etiketlerinde/faturalarda KDV tutarı buna göre yeniden hesaplanır.
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="asama" value="onizle">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Kapsam</label>
                    <select name="kapsam" id="kapsamSec" class="form-select form-select-sm" onchange="kapsamDegisti()">
                        <option value="tumu" <?= $kapsam==='tumu'?'selected':'' ?>>Tüm Ürünler</option>
                        <option value="kategori" <?= $kapsam==='kategori'?'selected':'' ?>>Kategori (+alt kategoriler)</option>
                        <option value="marka" <?= $kapsam==='marka'?'selected':'' ?>>Marka</option>
                    </select>
                </div>
                <div class="col-md-3" id="kategoriAlani" style="display:<?= $kapsam==='kategori'?'':'none' ?>">
                    <label class="form-label small fw-semibold mb-1">Kategori</label>
                    <select name="kategori_id" class="form-select form-select-sm">
                        <option value="">Seçin...</option>
                        <?php foreach ($kategoriler as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $kategoriId==(int)$k['id']?'selected':'' ?>><?= $k['ust_id']?'— ':'' ?><?= escH($k['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="markaAlani" style="display:<?= $kapsam==='marka'?'':'none' ?>">
                    <label class="form-label small fw-semibold mb-1">Marka</label>
                    <select name="marka" class="form-select form-select-sm">
                        <option value="">Seçin...</option>
                        <?php foreach ($markalar as $m): ?>
                        <option value="<?= escH($m) ?>" <?= $marka===$m?'selected':'' ?>><?= escH($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Yalnızca Eski KDV (%)</label>
                    <input type="number" name="eski_kdv" class="form-control form-control-sm" min="0" max="100" step="0.1" value="<?= escH($eskiKdv) ?>" placeholder="Tümü">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Yeni KDV Oranı (%) *</label>
                    <input type="number" name="yeni_kdv" class="form-control form-control-sm" min="0" max="100" step="0.1" required value="<?= escH($yeniKdv) ?>">
                </div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-eye"></i> Önizle</button></div>
            </div>
        </form>
    </div>
</div>

<?php if ($etkilenenSayi > 0): ?>
<div class="card shadow-sm border-success">
    <div class="card-header bg-white fw-semibold text-success">
        <i class="bi bi-check-circle"></i> Önizleme — <?= $etkilenenSayi ?> ürün etkilenecek <?= $etkilenenSayi > 500 ? '(ilk 500 gösteriliyor)' : '' ?>
    </div>
    <div class="card-body p-0" style="max-height:400px;overflow-y:auto">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Kod</th><th>Ürün</th><th>Mevcut KDV</th><th>Yeni KDV</th></tr></thead>
            <tbody>
            <?php foreach ($onizleme as $u): ?>
            <tr><td><?= escH($u['kod']) ?></td><td><?= escH($u['ad']) ?></td><td>%<?= $u['kdv_orani'] ?></td><td class="fw-bold text-success">%<?= escH($yeniKdv) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">
        <form method="post" onsubmit="return confirm('<?= $etkilenenSayi ?> ürünün KDV oranı %<?= escH($yeniKdv) ?> olarak güncellenecek. Bu işlem geri alınamaz. Onaylıyor musunuz?')">
            <?= csrfField() ?>
            <input type="hidden" name="asama" value="uygula">
            <input type="hidden" name="kapsam" value="<?= escH($kapsam) ?>">
            <input type="hidden" name="kategori_id" value="<?= $kategoriId ?>">
            <input type="hidden" name="marka" value="<?= escH($marka) ?>">
            <input type="hidden" name="eski_kdv" value="<?= escH($eskiKdv) ?>">
            <input type="hidden" name="yeni_kdv" value="<?= escH($yeniKdv) ?>">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Onayla ve Güncelle</button>
        </form>
    </div>
</div>
<?php elseif (isset($_POST['asama']) && $_POST['asama'] === 'onizle' && !$hata): ?>
<div class="alert alert-light border">Bu kriterlere uyan ürün bulunamadı.</div>
<?php endif; ?>

<script>
function kapsamDegisti() {
    const v = document.getElementById('kapsamSec').value;
    document.getElementById('kategoriAlani').style.display = v === 'kategori' ? '' : 'none';
    document.getElementById('markaAlani').style.display = v === 'marka' ? '' : 'none';
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
