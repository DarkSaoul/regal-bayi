<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Tedarikçiler';
$pdo = db();

$arama    = trim($_GET['ara'] ?? '');
$sadeceBorclu = isset($_GET['borclu']);
$where  = "WHERE 1=1";
$params = [];
if ($arama) {
    $where .= " AND (t.ad LIKE ? OR t.yetkili LIKE ? OR t.telefon LIKE ? OR t.vergi_no LIKE ?)";
    $params = array_fill(0, 4, likeParam($arama));
}
if ($sadeceBorclu) $where .= " AND t.toplam_borc > 0";

$tedarikciler = $pdo->prepare("
    SELECT t.*,
        COUNT(DISTINCT sh.id) AS giris_sayisi,
        COALESCE(SUM(CASE WHEN sh.hareket_tipi='giris' THEN sh.miktar ELSE 0 END), 0) AS toplam_giris
    FROM tedarikciler t
    LEFT JOIN stok_hareketleri sh ON sh.tedarikci_id = t.id
    $where
    GROUP BY t.id
    ORDER BY t.toplam_borc DESC, t.ad
");
$tedarikciler->execute($params);
$tedarikciler = $tedarikciler->fetchAll();

// Özet
$ozet = $pdo->query("SELECT COALESCE(SUM(toplam_borc),0) AS toplam_borc, COUNT(*) AS adet,
                     SUM(CASE WHEN toplam_borc>0 THEN 1 ELSE 0 END) AS borclu_adet
                     FROM tedarikciler")->fetch();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-truck text-primary"></i> Tedarikçiler</h4>
    <div class="d-flex gap-2">
        <a href="siparisler.php" class="btn btn-outline-primary"><i class="bi bi-clipboard-check"></i> Siparişler</a>
        <a href="ekle.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Tedarikçi</a>
    </div>
</div>

<!-- Özet Kartlar -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 <?= $ozet['toplam_borc']>0?'bg-danger':'bg-success' ?> text-white">
            <div class="card-body py-2">
                <div class="small opacity-75">Toplam Tedarikçi Borcu</div>
                <div class="fw-bold fs-5"><?= para($ozet['toplam_borc']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2">
                <div class="small opacity-75">Tedarikçi Sayısı</div>
                <div class="fw-bold fs-5"><?= number_format($ozet['adet']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body py-2">
                <div class="small">Borçlu Tedarikçi</div>
                <div class="fw-bold fs-5"><?= number_format($ozet['borclu_adet']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-center" method="get">
            <div class="col-md-6">
                <input type="text" name="ara" class="form-control form-control-sm"
                       placeholder="Ad, yetkili, telefon veya vergi no..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="borclu" id="borcluChk" value="1" <?= $sadeceBorclu?'checked':'' ?>>
                    <label class="form-check-label" for="borcluChk">Sadece borçlular</label>
                </div>
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
                <th>Firma Adı</th>
                <th>Yetkili Kişi</th>
                <th>Telefon</th>
                <th>Vergi No</th>
                <th class="text-center">Stok Giriş</th>
                <th class="text-end">Borç</th>
                <th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($tedarikciler)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Tedarikçi bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($tedarikciler as $t): ?>
            <tr>
                <td><strong><?= escH($t['ad']) ?></strong></td>
                <td><?= escH($t['yetkili'] ?: '-') ?></td>
                <td><?= escH($t['telefon'] ?: '-') ?></td>
                <td><code><?= escH($t['vergi_no'] ?: '-') ?></code></td>
                <td class="text-center">
                    <span class="badge bg-info"><?= $t['giris_sayisi'] ?> işlem</span>
                    <span class="text-muted small d-block"><?= number_format($t['toplam_giris']) ?> adet</span>
                </td>
                <td class="text-end fw-bold <?= $t['toplam_borc']>0?'text-danger':'text-success' ?>">
                    <?= $t['toplam_borc']>0 ? para($t['toplam_borc']) : '-' ?>
                </td>
                <td>
                    <a href="detay.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detay">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a href="duzenle.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Düzenle">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <?php if (($_SESSION['rol'] ?? '') === 'yonetici'): ?>
                    <form method="post" action="sil.php" class="d-inline"
                          onsubmit="return confirm('Bu tedarikçiyi silmek istediğinize emin misiniz?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<div class="mt-2 text-muted small">Toplam <?= count($tedarikciler) ?> tedarikçi listeleniyor</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
