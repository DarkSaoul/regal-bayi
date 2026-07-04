<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Satın Alma Siparişleri';
$pdo = db();

$durum = in_array($_GET['durum'] ?? '', ['bekliyor','teslim_alindi','iptal'], true) ? $_GET['durum'] : '';
$where = "WHERE 1=1"; $params = [];
if ($durum) { $where .= " AND s.durum=?"; $params[] = $durum; }

$siparisler = $pdo->prepare("
    SELECT s.*, t.ad AS tedarikci_adi,
           (SELECT COUNT(*) FROM siparis_kalemleri sk WHERE sk.siparis_id=s.id) AS kalem_sayisi
    FROM tedarikci_siparisleri s
    JOIN tedarikciler t ON s.tedarikci_id = t.id
    $where
    ORDER BY s.id DESC
");
$siparisler->execute($params);
$siparisler = $siparisler->fetchAll();

$ozet = $pdo->query("SELECT
    SUM(durum='bekliyor') AS bekleyen,
    COALESCE(SUM(CASE WHEN durum='bekliyor' THEN toplam_tutar ELSE 0 END),0) AS bekleyen_tutar
    FROM tedarikci_siparisleri")->fetch();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-clipboard-check text-primary"></i> Satın Alma Siparişleri</h4>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">← Tedarikçiler</a>
        <a href="siparis_ekle.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Sipariş</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2"><div class="small opacity-75">Bekleyen Sipariş</div>
                <div class="fw-bold fs-5"><?= (int)$ozet['bekleyen'] ?></div></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body py-2"><div class="small">Bekleyen Tutar</div>
                <div class="fw-bold fs-5"><?= para($ozet['bekleyen_tutar']) ?></div></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-center" method="get">
            <div class="col-md-3">
                <select name="durum" class="form-select form-select-sm">
                    <option value="">Tüm Durumlar</option>
                    <option value="bekliyor"      <?= $durum==='bekliyor'?'selected':'' ?>>Bekliyor</option>
                    <option value="teslim_alindi" <?= $durum==='teslim_alindi'?'selected':'' ?>>Teslim Alındı</option>
                    <option value="iptal"         <?= $durum==='iptal'?'selected':'' ?>>İptal</option>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filtrele</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Sipariş No</th><th>Tedarikçi</th><th>Tarih</th><th>Beklenen</th>
                <th class="text-center">Kalem</th><th class="text-end">Tutar</th><th>Durum</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($siparisler)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Sipariş bulunamadı</td></tr>
            <?php else: foreach ($siparisler as $s):
                $renk = $s['durum']==='teslim_alindi'?'success':($s['durum']==='iptal'?'danger':'warning');
                $lbl  = $s['durum']==='teslim_alindi'?'Teslim Alındı':($s['durum']==='iptal'?'İptal':'Bekliyor');
            ?>
            <tr>
                <td><strong><?= escH($s['siparis_no']) ?></strong></td>
                <td><?= escH($s['tedarikci_adi']) ?></td>
                <td><?= tarih($s['tarih']) ?></td>
                <td><?= $s['beklenen_tarih'] ? tarih($s['beklenen_tarih']) : '-' ?></td>
                <td class="text-center"><?= $s['kalem_sayisi'] ?></td>
                <td class="text-end fw-bold"><?= para($s['toplam_tutar']) ?></td>
                <td><span class="badge bg-<?= $renk ?>"><?= $lbl ?></span></td>
                <td><a href="siparis_detay.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<div class="mt-2 text-muted small">Toplam <?= count($siparisler) ?> sipariş</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
