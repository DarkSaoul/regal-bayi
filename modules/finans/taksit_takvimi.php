<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
moduleKontrol('taksit_takvimi', 'Taksit Takvimi');
$sayfa_basligi = 'Taksit Takvimi';
$pdo = db();
$rol = $_SESSION['rol'] ?? '';

$cezaOrani = (float)ayar('taksit_gecikme_cezasi_oran', '0');
$takipEsikGun = (int)ayar('taksit_takip_esik_gun', '30');

// ── POST aksiyonları ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';
    $taksitId = (int)($_POST['taksit_id'] ?? 0);

    if ($aksiyon === 'hatirlatma_kaydet') {
        $pdo->prepare("INSERT INTO taksit_hatirlatmalari (taksit_id,tarih,kanal,kullanici_id) VALUES (?,?,?,?)")
            ->execute([$taksitId, date('Y-m-d'), $_POST['kanal'] ?? 'whatsapp', $_SESSION['kullanici_id']]);
        $pdo->prepare("UPDATE taksit_plani SET son_hatirlatma_tarihi=? WHERE id=?")->execute([date('Y-m-d'), $taksitId]);
        header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
    }

    if ($aksiyon === 'ertele') {
        yetki(['yonetici','kasiyer']);
        $yeniTarih = gecerliTarih($_POST['yeni_vade_tarihi'] ?? '', date('Y-m-d'));
        $sebep = mb_substr(trim($_POST['sebep'] ?? ''), 0, 255);
        $tp = $pdo->prepare("SELECT * FROM taksit_plani WHERE id=? AND odendi=0");
        $tp->execute([$taksitId]); $tp = $tp->fetch();
        if ($tp) {
            // Sıra önemli: orijinal_vade_tarihi, vade_tarihi henüz güncellenmeden ESKİ değeri kullanmalı
            // (MySQL tek satır UPDATE'te SET ifadelerini soldan sağa değerlendirir).
            $pdo->prepare("UPDATE taksit_plani SET orijinal_vade_tarihi=COALESCE(orijinal_vade_tarihi,vade_tarihi), vade_tarihi=? WHERE id=?")
                ->execute([$yeniTarih, $taksitId]);
            $pdo->prepare("INSERT INTO taksit_erteleme_gecmisi (taksit_id,eski_vade_tarihi,yeni_vade_tarihi,sebep,kullanici_id) VALUES (?,?,?,?,?)")
                ->execute([$taksitId, $tp['vade_tarihi'], $yeniTarih, $sebep, $_SESSION['kullanici_id']]);
            logla('taksit_ertele', 'finans', $taksitId, tarih($tp['vade_tarihi']) . ' → ' . tarih($yeniTarih) . ($sebep ? " ($sebep)" : ''));
            flash('basari', 'Taksit vadesi ' . tarih($yeniTarih) . ' olarak ertelendi.');
        }
        header('Location: taksit_takvimi.php' . ($_SERVER['HTTP_REFERER'] ? '?' . parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY) : '')); exit;
    }

    if ($aksiyon === 'takip_degistir') {
        yetki(['yonetici','kasiyer']);
        $yeniDurum = ($_POST['durum'] ?? '') === 'takipte' ? 'takipte' : 'normal';
        $pdo->prepare("UPDATE taksit_plani SET takip_durumu=? WHERE id=?")->execute([$yeniDurum, $taksitId]);
        logla('taksit_takip', 'finans', $taksitId, 'Durum: ' . $yeniDurum);
        flash('basari', $yeniDurum === 'takipte' ? 'Taksit takibe alındı.' : 'Taksit takipten çıkarıldı.');
        header('Location: taksit_takvimi.php' . ($_SERVER['HTTP_REFERER'] ? '?' . parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY) : '')); exit;
    }
}

// ── Filtreler ─────────────────────────────────────────────────
$gorunum = in_array($_GET['gorunum'] ?? '', ['liste','takvim','hafta','musteri'], true) ? $_GET['gorunum'] : 'liste';
$filtre = $_GET['filtre'] ?? 'tumu';
$musteri_ara = trim($_GET['musteri'] ?? '');
$bas = trim($_GET['bas'] ?? '');
$bit = trim($_GET['bit'] ?? '');
$tmin = trim($_GET['tmin'] ?? '');
$tmax = trim($_GET['tmax'] ?? '');
$kasiyerF = (int)($_GET['kasiyer'] ?? 0);
$ay = preg_match('/^\d{4}-\d{2}$/', $_GET['ay'] ?? '') ? $_GET['ay'] : date('Y-m');

$siralamalar = ['vade' => 'tp.vade_tarihi', 'tutar' => 'tp.tutar', 'musteri' => 'musteri_adi'];
$srl = isset($siralamalar[$_GET['srl'] ?? '']) ? $_GET['srl'] : 'vade';
$yon = ($_GET['yon'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$where = "WHERE s.durum != 'iptal'";
$params = [];
if ($filtre === 'gecmis')       { $where .= " AND tp.odendi=0 AND tp.vade_tarihi < CURDATE()"; }
elseif ($filtre === 'yaklasan') { $where .= " AND tp.odendi=0 AND tp.vade_tarihi BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }
elseif ($filtre === 'odendi')   { $where .= " AND tp.odendi=1"; }
elseif ($filtre === 'bekleyen') { $where .= " AND tp.odendi=0"; }
elseif ($filtre === 'takipte')  { $where .= " AND tp.takip_durumu='takipte'"; }

if ($musteri_ara) { $where .= " AND (m.ad LIKE ? OR m.soyad LIKE ? OR m.firma_adi LIKE ?)"; $params = array_merge($params, array_fill(0,3, likeParam($musteri_ara))); }
if ($bas && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bas)) { $where .= " AND tp.vade_tarihi >= ?"; $params[] = $bas; }
if ($bit && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bit)) { $where .= " AND tp.vade_tarihi <= ?"; $params[] = $bit; }
if ($tmin !== '' && is_numeric($tmin)) { $where .= " AND tp.tutar >= ?"; $params[] = (float)$tmin; }
if ($tmax !== '' && is_numeric($tmax)) { $where .= " AND tp.tutar <= ?"; $params[] = (float)$tmax; }
if ($kasiyerF) { $where .= " AND s.kullanici_id=?"; $params[] = $kasiyerF; }

$baseSql = "FROM taksit_plani tp
    JOIN satislar s ON tp.satis_id = s.id
    LEFT JOIN musteriler m ON s.musteri_id = m.id
    $where";
$selectSql = "SELECT tp.*, s.fatura_no, s.id AS satis_id, s.kalan_tutar AS satis_kalan,
        CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.id AS musteri_id, m.telefon,
        (SELECT COUNT(*) FROM taksit_plani tp2 JOIN satislar s2 ON tp2.satis_id=s2.id
         WHERE s2.musteri_id=m.id AND tp2.odendi=0 AND tp2.vade_tarihi<CURDATE() AND s2.durum!='iptal') AS musteri_gecikmis_sayisi
    $baseSql";

// ── CSV export (mevcut tüm filtrelerle, sayfalama olmadan) ────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("$selectSql ORDER BY $siralamalar[$srl] " . strtoupper($yon));
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="taksit_takvimi_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Vade Tarihi','Müşteri','Fatura','Taksit No','Tutar','Durum','Ödeme Tarihi','Takip'], ';');
    foreach ($stmt->fetchAll() as $tp) {
        $gecmis = !$tp['odendi'] && strtotime($tp['vade_tarihi']) < time();
        $durum = $tp['odendi'] ? 'Ödendi' : ($gecmis ? 'Gecikmiş' : 'Bekliyor');
        fputcsv($out, [$tp['vade_tarihi'], csvHucre($tp['musteri_adi']), csvHucre($tp['fatura_no']), $tp['taksit_no'],
            number_format($tp['tutar'],2,',','.'), $durum, $tp['odeme_tarihi'] ?: '-', $tp['takip_durumu']], ';');
    }
    fclose($out); exit;
}

// Sayfalama (yalnızca liste görünümünde)
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$limit = 50; $offset = ($sayfa-1)*$limit;
$toplamSayi = $pdo->prepare("SELECT COUNT(*) $baseSql");
$toplamSayi->execute($params); $toplamSayi = (int)$toplamSayi->fetchColumn();
$sayfaSayisi = max(1, ceil($toplamSayi / $limit));

$siralamaKolonu = $siralamalar[$srl] . ' ' . strtoupper($yon);
$limitClause = $gorunum === 'liste' ? "LIMIT $limit OFFSET $offset" : "LIMIT 1000";
$stmt = $pdo->prepare("$selectSql ORDER BY $siralamaKolonu $limitClause");
$stmt->execute($params);
$taksitler = $stmt->fetchAll();

// Gecikme cezası hesapla (yalnızca gösterim — otomatik tahsil edilmez)
foreach ($taksitler as &$tp) {
    $tp['gecmis'] = !$tp['odendi'] && strtotime($tp['vade_tarihi']) < time();
    $tp['bugun']  = !$tp['odendi'] && $tp['vade_tarihi'] === date('Y-m-d');
    $tp['gecikme_gun'] = $tp['gecmis'] ? (int)floor((time() - strtotime($tp['vade_tarihi'])) / 86400) : 0;
    $tp['ceza'] = ($tp['gecmis'] && $cezaOrani > 0) ? round($tp['tutar'] * $cezaOrani / 100 * ceil($tp['gecikme_gun'] / 30), 2) : 0;
    $tp['takip_onerilir'] = $tp['gecmis'] && $tp['gecikme_gun'] >= $takipEsikGun && $tp['takip_durumu'] !== 'takipte';
}
unset($tp);

// Özet istatistikler
$ozet = $pdo->query("
    SELECT
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi < CURDATE() THEN 1 ELSE 0 END) AS gecmis,
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS yaklasan,
        SUM(CASE WHEN tp.odendi=0 THEN tp.tutar ELSE 0 END) AS bekleyen_tutar,
        SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi < CURDATE() THEN tp.tutar ELSE 0 END) AS gecmis_tutar,
        SUM(CASE WHEN tp.takip_durumu='takipte' THEN 1 ELSE 0 END) AS takipte_sayisi
    FROM taksit_plani tp
    JOIN satislar s ON tp.satis_id = s.id AND s.durum != 'iptal'
")->fetch();

$kasiyerler = $pdo->query("SELECT id, ad_soyad FROM kullanicilar WHERE aktif=1 ORDER BY ad_soyad")->fetchAll();

// ── Takvim görünümü verisi ─────────────────────────────────────
$takvimGunler = [];
if ($gorunum === 'takvim') {
    $ayBas = $ay . '-01';
    $ayBit = date('Y-m-t', strtotime($ayBas));
    $stmt2 = $pdo->prepare("SELECT tp.vade_tarihi, COUNT(*) AS adet, SUM(tp.tutar) AS toplam,
            SUM(CASE WHEN tp.odendi=0 AND tp.vade_tarihi<CURDATE() THEN 1 ELSE 0 END) AS gecmis_adet
        FROM taksit_plani tp JOIN satislar s ON tp.satis_id=s.id
        WHERE s.durum!='iptal' AND tp.vade_tarihi BETWEEN ? AND ? GROUP BY tp.vade_tarihi");
    $stmt2->execute([$ayBas, $ayBit]);
    foreach ($stmt2->fetchAll() as $g) $takvimGunler[$g['vade_tarihi']] = $g;
}

// ── Müşteri gruplu görünüm ─────────────────────────────────────
$musteriGruplari = [];
if ($gorunum === 'musteri') {
    foreach ($taksitler as $tp) {
        $mid = $tp['musteri_id'] ?: 0;
        if (!isset($musteriGruplari[$mid])) {
            $musteriGruplari[$mid] = ['ad' => $tp['musteri_adi'] ?: 'Perakende', 'telefon' => $tp['telefon'], 'taksitler' => [], 'toplam' => 0, 'gecmis_toplam' => 0];
        }
        $musteriGruplari[$mid]['taksitler'][] = $tp;
        if (!$tp['odendi']) {
            $musteriGruplari[$mid]['toplam'] += $tp['tutar'];
            if ($tp['gecmis']) $musteriGruplari[$mid]['gecmis_toplam'] += $tp['tutar'];
        }
    }
    uasort($musteriGruplari, fn($a,$b) => $b['gecmis_toplam'] <=> $a['gecmis_toplam']);
}

// ── Haftalık görünüm ────────────────────────────────────────────
$haftaGunleri = [];
if ($gorunum === 'hafta') {
    for ($i = 0; $i < 7; $i++) {
        $g = date('Y-m-d', strtotime("+$i days"));
        $haftaGunleri[$g] = array_filter($taksitler, fn($tp) => $tp['vade_tarihi'] === $g && !$tp['odendi']);
    }
}

function qs(array $degisiklik = []): string {
    $q = array_merge([
        'gorunum' => $_GET['gorunum'] ?? '', 'filtre' => $_GET['filtre'] ?? '', 'musteri' => $_GET['musteri'] ?? '',
        'bas' => $_GET['bas'] ?? '', 'bit' => $_GET['bit'] ?? '', 'tmin' => $_GET['tmin'] ?? '', 'tmax' => $_GET['tmax'] ?? '',
        'kasiyer' => $_GET['kasiyer'] ?? '', 'srl' => $_GET['srl'] ?? '', 'yon' => $_GET['yon'] ?? '', 's' => $_GET['s'] ?? '', 'ay' => $_GET['ay'] ?? '',
    ], $degisiklik);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
function srlBaslik(string $anahtar, string $etiket): string {
    global $srl, $yon;
    $siralamalar = ['vade' => 'tp.vade_tarihi', 'tutar' => 'tp.tutar', 'musteri' => 'musteri_adi'];
    $srlAnahtar = array_search($srl, $siralamalar) ?: 'vade';
    $yeniYon = ($srlAnahtar === $anahtar && $yon === 'asc') ? 'desc' : 'asc';
    $ok = $srlAnahtar === $anahtar ? ($yon === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '';
    return '<a class="text-decoration-none text-dark" href="?' . qs(['srl' => $anahtar, 'yon' => $yeniYon, 's' => 1]) . '">' . $etiket . $ok . '</a>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4><i class="bi bi-calendar-week text-primary"></i> Taksit Takvimi</h4>
    <div class="d-flex gap-2">
        <a href="taksit_performans.php" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-graph-up"></i> Performans Raporu</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> Yazdır</button>
    </div>
</div>

<!-- Görünüm sekmeleri -->
<div class="btn-group btn-group-sm mb-3 no-print">
    <a href="?<?= qs(['gorunum'=>'liste']) ?>" class="btn btn-outline-primary <?= $gorunum==='liste'?'active':'' ?>"><i class="bi bi-list-ul"></i> Liste</a>
    <a href="?<?= qs(['gorunum'=>'takvim']) ?>" class="btn btn-outline-primary <?= $gorunum==='takvim'?'active':'' ?>"><i class="bi bi-calendar3"></i> Aylık Takvim</a>
    <a href="?<?= qs(['gorunum'=>'hafta']) ?>" class="btn btn-outline-primary <?= $gorunum==='hafta'?'active':'' ?>"><i class="bi bi-calendar-week"></i> Bu Hafta</a>
    <a href="?<?= qs(['gorunum'=>'musteri']) ?>" class="btn btn-outline-primary <?= $gorunum==='musteri'?'active':'' ?>"><i class="bi bi-people"></i> Müşteriye Göre</a>
</div>

<!-- Özet kartlar -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <a href="?<?= qs(['filtre'=>'gecmis']) ?>" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='gecmis'?'bg-danger text-white':'bg-danger bg-opacity-10' ?>">
            <div class="card-body py-2">
                <div class="small <?= $filtre==='gecmis'?'':'text-danger' ?>">Gecikmiş Taksit</div>
                <div class="fw-bold fs-5 <?= $filtre==='gecmis'?'':'text-danger' ?>"><?= $ozet['gecmis'] ?></div>
                <div class="small <?= $filtre==='gecmis'?'opacity-75':'text-muted' ?>"><?= para($ozet['gecmis_tutar']) ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?<?= qs(['filtre'=>'yaklasan']) ?>" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='yaklasan'?'bg-warning':'bg-warning bg-opacity-10' ?>">
            <div class="card-body py-2">
                <div class="small">30 Gün İçinde</div>
                <div class="fw-bold fs-5"><?= $ozet['yaklasan'] ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?<?= qs(['filtre'=>'bekleyen']) ?>" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='bekleyen'?'bg-primary text-white':'bg-primary bg-opacity-10' ?>">
            <div class="card-body py-2">
                <div class="small <?= $filtre==='bekleyen'?'':'text-primary' ?>">Toplam Bekleyen</div>
                <div class="fw-bold fs-5 <?= $filtre==='bekleyen'?'':'text-primary' ?>"><?= para($ozet['bekleyen_tutar']) ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?<?= qs(['filtre'=>'takipte']) ?>" class="text-decoration-none">
        <div class="card shadow-sm border-0 <?= $filtre==='takipte'?'bg-dark text-white':'bg-secondary bg-opacity-10' ?>">
            <div class="card-body py-2">
                <div class="small <?= $filtre==='takipte'?'':'text-secondary' ?>">Takipte</div>
                <div class="fw-bold fs-5 <?= $filtre==='takipte'?'':'text-secondary' ?>"><?= (int)$ozet['takipte_sayisi'] ?></div>
            </div>
        </div></a>
    </div>
</div>

<!-- Filtre -->
<div class="card shadow-sm mb-3 no-print">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="gorunum" value="<?= escH($gorunum) ?>">
            <div class="col-md-3">
                <label class="form-label small mb-1">Müşteri</label>
                <input type="text" name="musteri" class="form-control form-control-sm" placeholder="Ad ara..." value="<?= escH($musteri_ara) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Durum</label>
                <select name="filtre" class="form-select form-select-sm">
                    <option value="tumu" <?= $filtre==='tumu'?'selected':'' ?>>Tümü</option>
                    <option value="gecmis" <?= $filtre==='gecmis'?'selected':'' ?>>Gecikmiş</option>
                    <option value="yaklasan" <?= $filtre==='yaklasan'?'selected':'' ?>>30 Gün İçinde</option>
                    <option value="bekleyen" <?= $filtre==='bekleyen'?'selected':'' ?>>Tüm Bekleyenler</option>
                    <option value="odendi" <?= $filtre==='odendi'?'selected':'' ?>>Ödenmiş</option>
                    <option value="takipte" <?= $filtre==='takipte'?'selected':'' ?>>Takipte</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Vade Başlangıç</label>
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= escH($bas) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Vade Bitiş</label>
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= escH($bit) ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-1">Min ₺</label>
                <input type="number" name="tmin" class="form-control form-control-sm" step="0.01" value="<?= escH($tmin) ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-1">Max ₺</label>
                <input type="number" name="tmax" class="form-control form-control-sm" step="0.01" value="<?= escH($tmax) ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-1">Kasiyer</label>
                <select name="kasiyer" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($kasiyerler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $kasiyerF===(int)$k['id']?'selected':'' ?>><?= escH($k['ad_soyad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                <a href="?gorunum=<?= $gorunum ?>" class="btn btn-sm btn-outline-secondary">Temizle</a>
                <a href="?<?= qs(['export'=>'csv']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
            </div>
        </form>
    </div>
</div>

<!-- Toplu hatırlatma çubuğu -->
<div class="d-flex gap-2 mb-2 no-print" id="topluBar" style="display:none!important">
    <button type="button" class="btn btn-sm btn-outline-success" onclick="topluHatirlatmaAc()">
        <i class="bi bi-whatsapp"></i> Seçilenler İçin Hatırlatma Hazırla (<span id="secilenSayi">0</span>)
    </button>
</div>

<?php if ($gorunum === 'liste'): ?>
<div class="card shadow-sm">
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
        <thead>
            <tr>
                <th class="no-print"><input type="checkbox" id="tumunuSec"></th>
                <th><?= srlBaslik('vade','Vade Tarihi') ?></th>
                <th><?= srlBaslik('musteri','Müşteri') ?></th>
                <th>Fatura</th>
                <th class="text-center">Taksit #</th>
                <th class="text-end"><?= srlBaslik('tutar','Tutar') ?></th>
                <th>Ceza</th>
                <th>Durum</th>
                <th>Ödeme Tarihi</th>
                <th class="no-print"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($taksitler)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Kayıt bulunamadı</td></tr>
        <?php else: ?>
        <?php foreach ($taksitler as $tp):
            $satir = $tp['odendi'] ? 'table-success' : ($tp['gecmis'] ? 'table-danger' : ($tp['bugun'] ? 'table-warning' : ''));
            $wa = $tp['telefon'] ? whatsappLink($tp['telefon'], ayar('firma_adi','Regal Bayi') . " hatırlatma: {$tp['fatura_no']} faturasının {$tp['taksit_no']}. taksiti (" . para($tp['tutar']) . ") " . ($tp['gecmis'] ? 'gecikmiş durumda' : 'için vade ' . tarih($tp['vade_tarihi'])) . ". Bilginize.") : null;
        ?>
        <tr class="<?= $satir ?>">
            <td class="no-print">
                <?php if (!$tp['odendi'] && $tp['telefon']): ?>
                <input type="checkbox" class="taksit-sec" value="<?= $tp['id'] ?>" data-wa="<?= escH($wa ?? '') ?>" data-ad="<?= escH($tp['musteri_adi']) ?>">
                <?php endif; ?>
            </td>
            <td class="fw-semibold">
                <?= tarih($tp['vade_tarihi']) ?>
                <?php if ($tp['orijinal_vade_tarihi']): ?><span class="badge bg-info text-dark" title="Orijinal vade: <?= tarih($tp['orijinal_vade_tarihi']) ?>">Ertelendi</span><?php endif; ?>
            </td>
            <td>
                <?= escH($tp['musteri_adi']) ?>
                <?php if ($tp['musteri_gecikmis_sayisi'] > 1): ?><span class="badge bg-danger ms-1" title="Bu müşterinin <?= $tp['musteri_gecikmis_sayisi'] ?> gecikmiş taksiti var">⚠ Riskli</span><?php endif; ?>
                <?php if ($tp['telefon']): ?><br><small class="text-muted"><?= escH($tp['telefon']) ?></small><?php endif; ?>
            </td>
            <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $tp['satis_id'] ?>"><?= escH($tp['fatura_no']) ?></a></td>
            <td class="text-center"><span class="badge bg-secondary"><?= $tp['taksit_no'] ?>. taksit</span></td>
            <td class="text-end fw-bold"><?= para($tp['tutar']) ?></td>
            <td class="text-danger small"><?= $tp['ceza'] > 0 ? para($tp['ceza']) : '-' ?></td>
            <td>
                <?php if ($tp['odendi']): ?><span class="badge bg-success">Ödendi ✓</span>
                <?php elseif ($tp['takip_durumu']==='takipte'): ?><span class="badge bg-dark">Takipte</span>
                <?php elseif ($tp['gecmis']): ?><span class="badge bg-danger">Gecikmiş (<?= $tp['gecikme_gun'] ?>g)</span>
                <?php elseif ($tp['bugun']): ?><span class="badge bg-warning text-dark">Bugün!</span>
                <?php else: ?><span class="badge bg-warning text-dark">Bekliyor</span>
                <?php endif; ?>
                <?php if ($tp['takip_onerilir']): ?><div class="small text-danger">Takip önerilir</div><?php endif; ?>
            </td>
            <td><?= $tp['odeme_tarihi'] ? tarih($tp['odeme_tarihi']) : '-' ?></td>
            <td class="no-print text-nowrap">
                <?php if (!$tp['odendi']): ?>
                <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $tp['satis_id'] ?>" class="btn btn-xs btn-outline-success btn-sm py-0 px-1" title="Tahsilat Al (kalan: <?= para($tp['satis_kalan']) ?>)"><i class="bi bi-cash-coin"></i></a>
                <?php if ($wa): ?>
                <a href="<?= escH($wa) ?>" target="_blank" class="btn btn-xs btn-outline-success btn-sm py-0 px-1" title="WhatsApp Hatırlatma" onclick="hatirlatmaKaydet(<?= $tp['id'] ?>)"><i class="bi bi-whatsapp"></i></a>
                <?php endif; ?>
                <?php if (in_array($rol, ['yonetici','kasiyer'], true)): ?>
                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-1" title="Ertele" data-bs-toggle="modal" data-bs-target="#erteleModal<?= $tp['id'] ?>"><i class="bi bi-calendar-plus"></i></button>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksiyon" value="takip_degistir">
                    <input type="hidden" name="taksit_id" value="<?= $tp['id'] ?>">
                    <input type="hidden" name="durum" value="<?= $tp['takip_durumu']==='takipte' ? 'normal' : 'takipte' ?>">
                    <button type="submit" class="btn btn-xs btn-sm py-0 px-1 <?= $tp['takip_durumu']==='takipte' ? 'btn-dark' : 'btn-outline-dark' ?>" title="<?= $tp['takip_durumu']==='takipte' ? 'Takipten Çıkar' : 'Takibe Al' ?>"><i class="bi bi-flag"></i></button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
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
        <?php for ($i=1;$i<=$sayfaSayisi;$i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>"><a class="page-link" href="?<?= qs(['s'=>$i]) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- Erteleme modalları -->
<?php foreach ($taksitler as $tp): if ($tp['odendi']) continue; ?>
<div class="modal fade" id="erteleModal<?= $tp['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="ertele">
                <input type="hidden" name="taksit_id" value="<?= $tp['id'] ?>">
                <div class="modal-header py-2">
                    <h6 class="modal-title">Taksit Ertele — <?= escH($tp['fatura_no']) ?> (<?= $tp['taksit_no'] ?>. taksit)</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small fw-semibold mb-1">Mevcut Vade</label>
                    <div class="mb-2"><?= tarih($tp['vade_tarihi']) ?></div>
                    <label class="form-label small fw-semibold mb-1">Yeni Vade Tarihi</label>
                    <input type="date" name="yeni_vade_tarihi" class="form-control mb-2" required value="<?= $tp['vade_tarihi'] ?>">
                    <label class="form-label small fw-semibold mb-1">Sebep <span class="text-muted">(opsiyonel)</span></label>
                    <input type="text" name="sebep" class="form-control" maxlength="255" placeholder="Örn: Müşteri talebi">
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-primary btn-sm">Ertele</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php elseif ($gorunum === 'takvim'): ?>
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <a href="?<?= qs(['ay' => date('Y-m', strtotime($ay.'-01 -1 month'))]) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <span class="fw-semibold"><?= ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'][(int)date('n', strtotime($ay.'-01'))] ?> <?= date('Y', strtotime($ay.'-01')) ?></span>
        <a href="?<?= qs(['ay' => date('Y-m', strtotime($ay.'-01 +1 month'))]) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
    </div>
    <div class="card-body">
        <div class="row row-cols-7 g-1 text-center small fw-semibold mb-1">
            <?php foreach (['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'] as $g): ?><div class="col"><?= $g ?></div><?php endforeach; ?>
        </div>
        <div class="row row-cols-7 g-1">
            <?php
            $ayBasTs = strtotime($ay . '-01');
            $ilkGunN = (int)date('N', $ayBasTs); // 1=Pzt
            $ayGunSayisi = (int)date('t', $ayBasTs);
            for ($bosluk = 1; $bosluk < $ilkGunN; $bosluk++): ?>
            <div class="col"><div class="border rounded p-1" style="min-height:70px;background:#f8f9fa"></div></div>
            <?php endfor;
            for ($gun = 1; $gun <= $ayGunSayisi; $gun++):
                $tarihStr = $ay . '-' . str_pad($gun, 2, '0', STR_PAD_LEFT);
                $veri = $takvimGunler[$tarihStr] ?? null;
                $bugunMu = $tarihStr === date('Y-m-d');
            ?>
            <div class="col">
                <a href="?gorunum=liste&bas=<?= $tarihStr ?>&bit=<?= $tarihStr ?>" class="text-decoration-none">
                <div class="border rounded p-1 <?= $bugunMu ? 'border-primary border-2' : '' ?> <?= $veri && $veri['gecmis_adet']>0 ? 'bg-danger bg-opacity-10' : ($veri ? 'bg-warning bg-opacity-10' : '') ?>" style="min-height:70px">
                    <div class="small text-muted"><?= $gun ?></div>
                    <?php if ($veri): ?>
                    <div class="small fw-bold text-dark"><?= (int)$veri['adet'] ?> taksit</div>
                    <div class="small text-primary"><?= para($veri['toplam']) ?></div>
                    <?php endif; ?>
                </div>
                </a>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<?php elseif ($gorunum === 'hafta'): ?>
<div class="row g-3">
    <?php foreach ($haftaGunleri as $gun => $liste):
        $trGunler = ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'];
        $gunAdi = $trGunler[(int)date('N', strtotime($gun)) - 1];
        $toplam = array_sum(array_column($liste, 'tutar'));
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100 <?= $gun === date('Y-m-d') ? 'border-primary' : '' ?>">
            <div class="card-header bg-white py-2 d-flex justify-content-between">
                <span class="fw-semibold"><?= $gunAdi ?>, <?= tarih($gun) ?></span>
                <span class="text-primary fw-bold"><?= para($toplam) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($liste)): ?>
                <div class="text-center text-muted py-3 small">Taksit yok</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($liste as $tp): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <div>
                        <a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $tp['satis_id'] ?>" class="small"><?= escH($tp['fatura_no']) ?></a>
                        <div class="small text-muted"><?= escH($tp['musteri_adi']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold small"><?= para($tp['tutar']) ?></div>
                        <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $tp['satis_id'] ?>" class="btn btn-xs btn-outline-success btn-sm py-0 px-1"><i class="bi bi-cash"></i></a>
                    </div>
                </li>
                <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php elseif ($gorunum === 'musteri'): ?>
<div class="accordion" id="musteriAccordion">
    <?php if (empty($musteriGruplari)): ?>
    <div class="card shadow-sm"><div class="card-body text-center text-muted py-4">Kayıt bulunamadı</div></div>
    <?php endif; ?>
    <?php foreach ($musteriGruplari as $mid => $grup): ?>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#mg<?= $mid ?>">
                <div class="d-flex justify-content-between w-100 me-3">
                    <span><?= escH($grup['ad']) ?> <span class="text-muted small">(<?= count($grup['taksitler']) ?> taksit)</span></span>
                    <span>
                        <?php if ($grup['gecmis_toplam'] > 0): ?><span class="badge bg-danger me-2">Gecikmiş: <?= para($grup['gecmis_toplam']) ?></span><?php endif; ?>
                        <span class="badge bg-primary">Bekleyen: <?= para($grup['toplam']) ?></span>
                    </span>
                </div>
            </button>
        </h2>
        <div id="mg<?= $mid ?>" class="accordion-collapse collapse" data-bs-parent="#musteriAccordion">
            <div class="accordion-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Vade</th><th>Fatura</th><th class="text-center">#</th><th class="text-end">Tutar</th><th>Durum</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($grup['taksitler'] as $tp): ?>
                    <tr class="<?= $tp['gecmis'] ? 'table-danger' : ($tp['odendi'] ? 'table-success' : '') ?>">
                        <td><?= tarih($tp['vade_tarihi']) ?></td>
                        <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $tp['satis_id'] ?>"><?= escH($tp['fatura_no']) ?></a></td>
                        <td class="text-center"><?= $tp['taksit_no'] ?></td>
                        <td class="text-end fw-bold"><?= para($tp['tutar']) ?></td>
                        <td><?= $tp['odendi'] ? '<span class="badge bg-success">Ödendi</span>' : ($tp['gecmis'] ? '<span class="badge bg-danger">Gecikmiş</span>' : '<span class="badge bg-warning text-dark">Bekliyor</span>') ?></td>
                        <td><?php if (!$tp['odendi']): ?><a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $tp['satis_id'] ?>" class="btn btn-xs btn-outline-success btn-sm py-0 px-1"><i class="bi bi-cash"></i></a><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Toplu hatırlatma modalı -->
<div class="modal fade" id="topluHatirlatmaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-whatsapp text-success"></i> Toplu Hatırlatma</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-2">WhatsApp toplu gönderim API'siz mümkün olmadığı için her müşteri için ayrı açılır — sırayla tıklayın.</div>
                <div id="topluListe" class="list-group"></div>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= csrfToken() ?>';
function hatirlatmaKaydet(taksitId) {
    const fd = new FormData();
    fd.append('aksiyon', 'hatirlatma_kaydet');
    fd.append('taksit_id', taksitId);
    fd.append('kanal', 'whatsapp');
    fd.append('csrf_token', CSRF);
    fetch('taksit_takvimi.php', { method: 'POST', body: fd }).catch(() => {});
}

// Toplu seçim
const tumunuSecEl = document.getElementById('tumunuSec');
if (tumunuSecEl) {
    tumunuSecEl.addEventListener('change', function () {
        document.querySelectorAll('.taksit-sec').forEach(c => c.checked = this.checked);
        topluBarGuncelle();
    });
}
document.addEventListener('change', e => { if (e.target.classList.contains('taksit-sec')) topluBarGuncelle(); });

function topluBarGuncelle() {
    const secili = document.querySelectorAll('.taksit-sec:checked');
    document.getElementById('secilenSayi').textContent = secili.length;
    document.getElementById('topluBar').style.setProperty('display', secili.length ? 'flex' : 'none', 'important');
}

function topluHatirlatmaAc() {
    const secili = document.querySelectorAll('.taksit-sec:checked');
    const liste = document.getElementById('topluListe');
    liste.innerHTML = '';
    secili.forEach(c => {
        const wa = c.dataset.wa;
        const ad = c.dataset.ad;
        const id = c.value;
        if (!wa) return;
        const a = document.createElement('a');
        a.href = wa; a.target = '_blank'; a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        a.innerHTML = `<span>${ad}</span><span class="badge bg-success"><i class="bi bi-whatsapp"></i> Gönder</span>`;
        a.addEventListener('click', () => hatirlatmaKaydet(id));
        liste.appendChild(a);
    });
    new bootstrap.Modal(document.getElementById('topluHatirlatmaModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
