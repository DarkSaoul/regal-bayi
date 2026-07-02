<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Taksit Takvimi';
$pdo = db();

$filtre = $_GET['filtre'] ?? 'tumu'; // tumu | gecmis | yaklasan | odendi
$musteri_ara = trim($_GET['musteri'] ?? '');

$where = "WHERE s.durum != 'iptal'";
$params = [];
if ($filtre === 'gecmis')    { $where .= " AND tp.odendi=0 AND tp.vade_tarihi < CURDATE()"; }
elseif ($filtre === 'yaklasan') { $where .= " AND tp.odendi=0 AND tp.vade_tarihi BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }
elseif ($filtre === 'odendi')   { $where .= " AND tp.odendi=1"; }
elseif ($filtre === 'bekleyen') { $where .= " AND tp.odendi=0"; }

if ($musteri_ara) {
    $where .= " AND (m.ad LIKE ? OR m.soyad LIKE ? OR m.firma_adi LIKE ?)";
    $params = array_merge($params, array_fill(0,3, likeParam($musteri_ara)));
}

$taksitler = $pdo->prepare("
    SELECT tp.*, s.fatura_no, s.id AS satis_id,
           CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.id AS musteri_id, m.telefon
    FROM taksit_plani tp
    JOIN satislar s ON tp.satis_id = s.id
    LEFT JOIN musteriler m ON s.musteri_id = m.id
    $where
    ORDER BY tp.vade_tarihi ASC, tp.taksit_no ASC
    LIMIT 300
");
$taksitler->execute($params);
$taksitler = $taksitler->fetchAll();

// Özet istatistikler
$ozet = $pdo->query("
    SELECT
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi < CURDATE() THEN 1 ELSE 0 END) AS gecmis,
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS yaklasan,
        SUM(CASE WHEN tp.odendi=0 THEN tp.tutar ELSE 0 END) AS bekleyen_tutar,
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi < CURDATE() THEN tp.tutar ELSE 0 END) AS gecmis_tutar
    FROM taksit_plani tp
    JOIN satislar s ON tp.satis_id = s.id AND s.durum != 'iptal'
")->fetch();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-calendar-week text-primary"></i> Taksit Takvimi</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer"></i> Yazdır
    </button>
</div>

<!-- Özet kartlar -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <a href="?filtre=gecmis" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='gecmis'?'bg-danger text-white':'bg-danger bg-opacity-10' ?>">
            <div class="card-body py-2">
                <div class="small <?= $filtre==='gecmis'?'':'text-danger' ?>">Gecikmiş Taksit</div>
                <div class="fw-bold fs-5 <?= $filtre==='gecmis'?'':'text-danger' ?>"><?= $ozet['gecmis'] ?></div>
                <div class="small <?= $filtre==='gecmis'?'opacity-75':'text-muted' ?>"><?= para($ozet['gecmis_tutar']) ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filtre=yaklasan" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='yaklasan'?'bg-warning':'bg-warning bg-opacity-10' ?>">
            <div class="card-body py-2">
                <div class="small">30 Gün İçinde</div>
                <div class="fw-bold fs-5"><?= $ozet['yaklasan'] ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filtre=bekleyen" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='bekleyen'?'bg-primary text-white':'bg-primary bg-opacity-10' ?>">
            <div class="card-body py-2">
                <div class="small <?= $filtre==='bekleyen'?'':'text-primary' ?>">Toplam Bekleyen</div>
                <div class="fw-bold fs-5 <?= $filtre==='bekleyen'?'':'text-primary' ?>"><?= para($ozet['bekleyen_tutar']) ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filtre=tumu" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='tumu'?'bg-secondary text-white':'bg-light' ?>">
            <div class="card-body py-2">
                <div class="small">Tüm Taksitler</div>
                <div class="fw-bold fs-5"><?= count($taksitler) ?></div>
            </div>
        </div></a>
    </div>
</div>

<!-- Filtre -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-4">
                <input type="text" name="musteri" class="form-control form-control-sm"
                       placeholder="Müşteri adı..." value="<?= escH($musteri_ara) ?>">
            </div>
            <div class="col-md-4">
                <select name="filtre" class="form-select form-select-sm">
                    <option value="tumu" <?= $filtre==='tumu'?'selected':'' ?>>Tüm Taksitler</option>
                    <option value="gecmis" <?= $filtre==='gecmis'?'selected':'' ?>>Yalnızca Gecikmiş</option>
                    <option value="yaklasan" <?= $filtre==='yaklasan'?'selected':'' ?>>30 Gün İçinde Gelecek</option>
                    <option value="bekleyen" <?= $filtre==='bekleyen'?'selected':'' ?>>Tüm Bekleyenler</option>
                    <option value="odendi" <?= $filtre==='odendi'?'selected':'' ?>>Ödenmiş</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                <a href="taksit_takvimi.php" class="btn btn-sm btn-outline-secondary">Temizle</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
        <thead>
            <tr>
                <th>Vade Tarihi</th>
                <th>Müşteri</th>
                <th>Fatura</th>
                <th class="text-center">Taksit #</th>
                <th class="text-end">Tutar</th>
                <th>Durum</th>
                <th>Ödeme Tarihi</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($taksitler)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Kayıt bulunamadı</td></tr>
        <?php else: ?>
        <?php foreach ($taksitler as $tp):
            $gecmis  = !$tp['odendi'] && strtotime($tp['vade_tarihi']) < time();
            $bugün   = !$tp['odendi'] && $tp['vade_tarihi'] === date('Y-m-d');
            $satir   = $tp['odendi'] ? 'table-success' : ($gecmis ? 'table-danger' : ($bugün ? 'table-warning' : ''));
        ?>
        <tr class="<?= $satir ?>">
            <td class="fw-semibold"><?= tarih($tp['vade_tarihi']) ?></td>
            <td>
                <?= escH($tp['musteri_adi']) ?>
                <?php if ($tp['telefon']): ?>
                <br><small class="text-muted"><?= escH($tp['telefon']) ?></small>
                <?php endif; ?>
            </td>
            <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $tp['satis_id'] ?>"><?= escH($tp['fatura_no']) ?></a></td>
            <td class="text-center"><span class="badge bg-secondary"><?= $tp['taksit_no'] ?>. taksit</span></td>
            <td class="text-end fw-bold"><?= para($tp['tutar']) ?></td>
            <td>
                <?php if ($tp['odendi']): ?>
                    <span class="badge bg-success">Ödendi ✓</span>
                <?php elseif ($gecmis): ?>
                    <span class="badge bg-danger">Gecikmiş</span>
                <?php elseif ($bugün): ?>
                    <span class="badge bg-warning text-dark">Bugün!</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Bekliyor</span>
                <?php endif; ?>
            </td>
            <td><?= $tp['odeme_tarihi'] ? tarih($tp['odeme_tarihi']) : '-' ?></td>
            <td>
                <?php if (!$tp['odendi']): ?>
                <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $tp['satis_id'] ?>"
                   class="btn btn-xs btn-outline-success btn-sm py-0 px-2" title="Tahsilat Al">
                    <i class="bi bi-cash-coin"></i>
                </a>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
