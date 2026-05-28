<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Satışlar';
$pdo = db();

$arama  = trim($_GET['ara'] ?? '');
$durum  = $_GET['durum'] ?? '';
$bas    = $_GET['bas'] ?? date('Y-m-01');
$bit    = $_GET['bit'] ?? date('Y-m-d');
$sayfa  = max(1,(int)($_GET['s']??1));
$limit  = 25; $offset = ($sayfa-1)*$limit;

$where = "WHERE s.tarih BETWEEN ? AND ?";
$params = [$bas, $bit];
if ($arama) { $where .= " AND (s.fatura_no LIKE ? OR m.ad LIKE ? OR m.soyad LIKE ? OR m.firma_adi LIKE ?)"; $params = array_merge($params, array_fill(0,4,likeParam($arama))); }
if ($durum) { $where .= " AND s.durum=?"; $params[] = $durum; }

$toplam = $pdo->prepare("SELECT COUNT(*) FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id $where");
$toplam->execute($params); $toplam = (int)$toplam->fetchColumn();

$stmt = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id $where ORDER BY s.id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$satislar = $stmt->fetchAll();

$ozet = $pdo->prepare("SELECT SUM(genel_toplam) AS toplam, SUM(kalan_tutar) AS kalan, COUNT(*) AS adet FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id $where AND s.durum!='iptal'");
$ozet->execute($params); $ozet = $ozet->fetch();
$sayfaSayisi = ceil($toplam/$limit);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-receipt text-primary"></i> Satışlar</h4>
    <a href="yeni.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Satış</a>
</div>

<!-- Özet -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2">
                <div class="small">Filtrelenmiş Toplam</div>
                <div class="fw-bold fs-5"><?= para($ozet['toplam']??0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body py-2">
                <div class="small">Tahsil Edilemeyen</div>
                <div class="fw-bold fs-5"><?= para($ozet['kalan']??0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body py-2">
                <div class="small">İşlem Sayısı</div>
                <div class="fw-bold fs-5"><?= $ozet['adet'] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filtre -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Fatura no / Müşteri..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= $bas ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= $bit ?>">
            </div>
            <div class="col-md-2">
                <select name="durum" class="form-select form-select-sm">
                    <option value="">Tüm Durumlar</option>
                    <option value="tamamlandi" <?= $durum==='tamamlandi'?'selected':'' ?>>Tamamlandı</option>
                    <option value="bekliyor" <?= $durum==='bekliyor'?'selected':'' ?>>Bekliyor</option>
                    <option value="iptal" <?= $durum==='iptal'?'selected':'' ?>>İptal</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Temizle</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Fatura No</th><th>Müşteri</th><th>Tarih</th>
                <th>Tutar</th><th>Ödenen</th><th>Kalan</th>
                <th>Ödeme Tipi</th><th>Durum</th><th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($satislar)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Kayıt bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($satislar as $s): ?>
            <?php $renk = $s['durum']==='tamamlandi'?'success':($s['durum']==='iptal'?'danger':'warning'); ?>
            <tr>
                <td><strong><?= escH($s['fatura_no']) ?></strong></td>
                <td><?= escH($s['musteri_adi'] ?: 'Perakende') ?></td>
                <td><?= tarih($s['tarih']) ?></td>
                <td><?= para($s['genel_toplam']) ?></td>
                <td><?= para($s['odenen_tutar']) ?></td>
                <td class="<?= $s['kalan_tutar']>0?'text-danger fw-bold':'' ?>"><?= $s['kalan_tutar']>0?para($s['kalan_tutar']):'-' ?></td>
                <td><?= ucfirst(str_replace('_',' ',$s['odeme_tipi'])) ?></td>
                <td><span class="badge bg-<?= $renk ?>"><?= $s['durum']==='tamamlandi'?'Tamamlandı':($s['durum']==='iptal'?'İptal':'Bekliyor') ?></span></td>
                <td>
                    <a href="detay.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detay"><i class="bi bi-eye"></i></a>
                    <?php if ($s['kalan_tutar']>0): ?>
                    <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-success" title="Tahsilat"><i class="bi bi-cash-coin"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($sayfaSayisi>1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end">
        <?php for ($i=1;$i<=$sayfaSayisi;$i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>">
                <a class="page-link" href="?ara=<?= urlencode($arama) ?>&bas=<?= $bas ?>&bit=<?= $bit ?>&durum=<?= $durum ?>&s=<?= $i ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
