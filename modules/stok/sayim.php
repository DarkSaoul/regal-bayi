<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo']);
$sayfa_basligi = 'Stok Sayımı';
$pdo = db();

$kategori_id = (int)($_GET['kategori_id'] ?? 0) ?: null;
$arama       = trim($_GET['ara'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aciklama = trim($_POST['sayim_aciklama'] ?? 'Stok sayımı');
    $miktarlar = $_POST['sayim_miktar'] ?? [];
    $guncellenen = 0;

    if (!is_array($miktarlar) || empty($miktarlar)) {
        flash('hata', 'Sayım verisi bulunamadı.');
        header('Location: sayim.php'); exit;
    }

    $pdo->beginTransaction();
    try {
        foreach ($miktarlar as $urun_id => $yeni_miktar) {
            $urun_id    = (int)$urun_id;
            $yeni_miktar = (int)$yeni_miktar;
            if ($yeni_miktar < 0) continue;

            $stmt = $pdo->prepare("SELECT stok_adedi FROM urunler WHERE id=? FOR UPDATE");
            $stmt->execute([$urun_id]);
            $mevcut = $stmt->fetchColumn();
            if ($mevcut === false) continue; // ürün yok
            $mevcut = (int)$mevcut;

            if ($mevcut === $yeni_miktar) continue; // Değişiklik yok

            $fark = $yeni_miktar - $mevcut;
            $pdo->prepare("UPDATE urunler SET stok_adedi=? WHERE id=?")->execute([$yeni_miktar, $urun_id]);
            $pdo->prepare("INSERT INTO stok_hareketleri (urun_id,hareket_tipi,miktar,onceki_stok,sonraki_stok,aciklama,kullanici_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$urun_id, 'sayim_duzeltme', abs($fark), $mevcut, $yeni_miktar, $aciklama, $_SESSION['kullanici_id']]);
            $guncellenen++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('hata', 'Sayım kaydı sırasında hata: ' . $e->getMessage());
        header('Location: sayim.php'); exit;
    }

    logla('stok_sayim', 'stok', 0, "$guncellenen ürün güncellendi | $aciklama");
    flash('basari', "$guncellenen ürünün stok sayımı güncellendi.");
    header('Location: sayim.php'); exit;
}

$where  = "WHERE u.aktif=1";
$params = [];
if ($kategori_id) { $where .= " AND u.kategori_id=?"; $params[] = $kategori_id; }
if ($arama)       { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ?)"; $params = array_merge($params, [likeParam($arama), likeParam($arama)]); }

$urunler = $pdo->prepare("SELECT u.id, u.kod, u.ad, u.stok_adedi, u.min_stok, k.ad AS kategori FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where ORDER BY k.ad, u.ad");
$urunler->execute($params);
$urunler = $urunler->fetchAll();

$kategoriler = $pdo->query("SELECT * FROM kategoriler WHERE ust_id IS NULL ORDER BY ad")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-clipboard-check text-primary"></i> Stok Sayımı</h4>
    <div class="text-muted small"><?= count($urunler) ?> ürün listeleniyor</div>
</div>

<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>Fiziksel sayım sonucunu <strong>Gerçek Miktar</strong> sütununa girin. Değişiklik yapılan ürünler için otomatik <strong>sayım düzeltme</strong> hareketi oluşturulur.</div>
</div>

<!-- Filtre -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-4">
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ürün adı / kodu..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-3">
                <select name="kategori_id" class="form-select form-select-sm">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach ($kategoriler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $kategori_id==$k['id']?'selected':'' ?>><?= escH($k['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                <a href="sayim.php" class="btn btn-sm btn-outline-secondary">Temizle</a>
            </div>
        </form>
    </div>
</div>

<form method="post" id="sayimForm">
    <?= csrfField() ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-check text-primary"></i> Sayım Listesi</span>
            <div class="d-flex gap-2 align-items-center">
                <input type="text" name="sayim_aciklama" class="form-control form-control-sm"
                       style="max-width:220px" placeholder="Sayım notu..." value="Stok sayımı <?= date('d.m.Y') ?>">
            </div>
        </div>
        <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Kod</th>
                    <th>Ürün</th>
                    <th>Kategori</th>
                    <th class="text-center">Sistemdeki Miktar</th>
                    <th class="text-center" style="width:150px">Gerçek Miktar</th>
                    <th class="text-center">Fark</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($urunler)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Ürün bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($urunler as $u): ?>
            <tr class="sayim-satir" data-sistem="<?= $u['stok_adedi'] ?>">
                <td><small class="text-muted"><?= escH($u['kod']) ?></small></td>
                <td class="fw-semibold"><?= escH($u['ad']) ?></td>
                <td><small class="text-muted"><?= escH($u['kategori'] ?? '—') ?></small></td>
                <td class="text-center">
                    <span class="badge bg-<?= $u['stok_adedi'] <= 0 ? 'danger' : ($u['stok_adedi'] <= $u['min_stok'] ? 'warning' : 'success') ?>">
                        <?= $u['stok_adedi'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <input type="number" name="sayim_miktar[<?= $u['id'] ?>]"
                           class="form-control form-control-sm text-center sayim-input"
                           min="0" value="<?= $u['stok_adedi'] ?>"
                           style="width:90px;margin:0 auto"
                           oninput="farkHesapla(this)">
                </td>
                <td class="text-center fark-hucre fw-bold">—</td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <div class="d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-primary btn-lg"
                onclick="return confirm('Değişen stok miktarları güncelllenecek. Emin misiniz?')">
            <i class="bi bi-check-circle"></i> Sayımı Kaydet
        </button>
        <a href="index.php" class="btn btn-outline-secondary btn-lg">İptal</a>
        <span class="text-muted ms-3" id="degisiklikSayisi"></span>
    </div>
</form>

<script>
function farkHesapla(input) {
    const satir   = input.closest('tr');
    const sistem  = parseInt(satir.dataset.sistem) || 0;
    const gercek  = parseInt(input.value) || 0;
    const fark    = gercek - sistem;
    const hucre   = satir.querySelector('.fark-hucre');
    if (fark === 0) {
        hucre.textContent = '—';
        hucre.className = 'text-center fark-hucre fw-bold';
        satir.classList.remove('table-warning','table-danger','table-success');
    } else {
        hucre.textContent = (fark > 0 ? '+' : '') + fark;
        hucre.className = 'text-center fark-hucre fw-bold ' + (fark > 0 ? 'text-success' : 'text-danger');
        satir.classList.remove('table-warning','table-danger','table-success');
        satir.classList.add(fark > 0 ? 'table-success' : 'table-danger');
    }
    // Değişiklik sayısını güncelle
    const degisen = document.querySelectorAll('.sayim-satir.table-success, .sayim-satir.table-danger').length;
    document.getElementById('degisiklikSayisi').textContent = degisen > 0 ? `${degisen} üründe değişiklik var` : '';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
