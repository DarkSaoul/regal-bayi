<?php
// Teslimat / montaj takibi
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Teslimatlar';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $id = (int)($_POST['id'] ?? 0);
    $durum = in_array($_POST['teslimat_durum'] ?? '', ['hazirlaniyor','yolda','teslim_edildi'], true) ? $_POST['teslimat_durum'] : 'hazirlaniyor';
    $pdo->prepare("UPDATE satislar SET teslimat_durum=? WHERE id=? AND teslimat_durum!='yok'")->execute([$durum, $id]);
    logla('teslimat_guncelle', 'satislar', $id, 'Durum: ' . $durum);
    flash('basari', 'Teslimat durumu güncellendi.');
    header('Location: teslimatlar.php' . (isset($_GET['durum']) ? '?durum=' . urlencode($_GET['durum']) : '')); exit;
}

$durumF = in_array($_GET['durum'] ?? '', ['hazirlaniyor','yolda','teslim_edildi'], true) ? $_GET['durum'] : '';

$where = "WHERE s.teslimat_durum != 'yok' AND s.durum != 'iptal'";
$params = [];
if ($durumF) { $where .= " AND s.teslimat_durum=?"; $params[] = $durumF; }

$teslimatlar = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.telefon
    FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id
    $where ORDER BY FIELD(s.teslimat_durum,'hazirlaniyor','yolda','teslim_edildi'), s.teslimat_tarihi ASC");
$teslimatlar->execute($params); $teslimatlar = $teslimatlar->fetchAll();

$ozet = $pdo->query("SELECT teslimat_durum, COUNT(*) AS adet FROM satislar WHERE teslimat_durum!='yok' AND durum!='iptal' GROUP BY teslimat_durum")->fetchAll(PDO::FETCH_KEY_PAIR);
$bugunTeslimat = (int)$pdo->query("SELECT COUNT(*) FROM satislar WHERE teslimat_tarihi=CURDATE() AND teslimat_durum NOT IN ('yok','teslim_edildi') AND durum!='iptal'")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
$tdEtiket = ['hazirlaniyor'=>'Hazırlanıyor','yolda'=>'Yolda','teslim_edildi'=>'Teslim Edildi'];
$tdRenk   = ['hazirlaniyor'=>'warning text-dark','yolda'=>'info text-dark','teslim_edildi'=>'success'];
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-truck text-primary"></i> Teslimatlar</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Satışlar</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body py-2"><div class="small">Hazırlanıyor</div><div class="fw-bold fs-5"><?= (int)($ozet['hazirlaniyor'] ?? 0) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-info">
            <div class="card-body py-2"><div class="small">Yolda</div><div class="fw-bold fs-5"><?= (int)($ozet['yolda'] ?? 0) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body py-2"><div class="small">Teslim Edildi</div><div class="fw-bold fs-5"><?= (int)($ozet['teslim_edildi'] ?? 0) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2"><div class="small">Bugün Planlı</div><div class="fw-bold fs-5"><?= $bugunTeslimat ?></div></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="btn-group btn-group-sm">
            <a href="?" class="btn btn-outline-secondary <?= !$durumF?'active':'' ?>">Tümü</a>
            <a href="?durum=hazirlaniyor" class="btn btn-outline-warning <?= $durumF==='hazirlaniyor'?'active':'' ?>">Hazırlanıyor</a>
            <a href="?durum=yolda" class="btn btn-outline-info <?= $durumF==='yolda'?'active':'' ?>">Yolda</a>
            <a href="?durum=teslim_edildi" class="btn btn-outline-success <?= $durumF==='teslim_edildi'?'active':'' ?>">Teslim Edildi</a>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Fatura</th><th>Müşteri</th><th>Teslimat Tarihi</th><th>Adres</th><th>Montaj</th><th>Durum</th><th style="width:220px">İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($teslimatlar)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Kayıt bulunamadı</td></tr>
            <?php endif; ?>
            <?php foreach ($teslimatlar as $t):
                $gecikmis = $t['teslimat_durum'] !== 'teslim_edildi' && $t['teslimat_tarihi'] && strtotime($t['teslimat_tarihi']) < strtotime(date('Y-m-d'));
            ?>
            <tr class="<?= $gecikmis ? 'table-danger' : '' ?>">
                <td><a href="detay.php?id=<?= $t['id'] ?>" class="fw-bold text-decoration-none"><?= escH($t['fatura_no']) ?></a></td>
                <td><?= escH($t['musteri_adi'] ?: 'Perakende') ?><?php if ($t['telefon']): ?><div class="small text-muted"><?= escH($t['telefon']) ?></div><?php endif; ?></td>
                <td><?= tarih($t['teslimat_tarihi']) ?><?php if ($gecikmis): ?><span class="badge bg-danger ms-1">Gecikmiş</span><?php endif; ?></td>
                <td class="small text-truncate" style="max-width:220px"><?= escH($t['teslimat_adresi'] ?: '-') ?></td>
                <td class="small"><?= $t['montaj_tarihi'] ? tarih($t['montaj_tarihi']) : '-' ?></td>
                <td><span class="badge bg-<?= $tdRenk[$t['teslimat_durum']] ?>"><?= $tdEtiket[$t['teslimat_durum']] ?></span></td>
                <td>
                    <?php if ($t['teslimat_durum'] !== 'teslim_edildi'): ?>
                    <form method="post" class="d-flex gap-1">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                        <?php if ($t['teslimat_durum'] === 'hazirlaniyor'): ?>
                        <button type="submit" name="teslimat_durum" value="yolda" class="btn btn-sm btn-info">Yola Çıkar</button>
                        <?php endif; ?>
                        <button type="submit" name="teslimat_durum" value="teslim_edildi" class="btn btn-sm btn-success">Teslim Edildi</button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
