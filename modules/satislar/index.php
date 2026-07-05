<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Satışlar';
$pdo = db();

$arama  = trim($_GET['ara'] ?? '');
$bas    = gecerliTarih($_GET['bas'] ?? '', date('Y-m-01'));
$bit    = gecerliTarih($_GET['bit'] ?? '', date('Y-m-d'));
$durum  = in_array($_GET['durum'] ?? '', ['tamamlandi','bekliyor','iptal'], true) ? $_GET['durum'] : '';
$odemeTipiF = in_array($_GET['odeme'] ?? '', ['nakit','kredi_karti','havale','taksitli','bolunmus'], true) ? $_GET['odeme'] : '';
$kasiyerF   = (int)($_GET['kasiyer'] ?? 0);
$urunF      = (int)($_GET['urun'] ?? 0);
$tutarMin   = trim($_GET['tmin'] ?? '');
$tutarMax   = trim($_GET['tmax'] ?? '');
$tipF       = in_array($_GET['tip'] ?? '', ['satis','on_siparis'], true) ? $_GET['tip'] : '';

// Sıralama (whitelist)
$siralamalar = [
    'id'     => 's.id',
    'tarih'  => 's.tarih',
    'tutar'  => 's.genel_toplam',
    'kalan'  => 's.kalan_tutar',
];
$srl = isset($siralamalar[$_GET['srl'] ?? '']) ? $_GET['srl'] : 'id';
$yon = ($_GET['yon'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$orderBy = $siralamalar[$srl] . ' ' . strtoupper($yon);

$where = "WHERE s.tarih BETWEEN ? AND ?";
$params = [$bas, $bit];
if ($arama) { $where .= " AND (s.fatura_no LIKE ? OR m.ad LIKE ? OR m.soyad LIKE ? OR m.firma_adi LIKE ?)"; $params = array_merge($params, array_fill(0,4,likeParam($arama))); }
if ($durum) { $where .= " AND s.durum=?"; $params[] = $durum; }
if ($odemeTipiF) { $where .= " AND s.odeme_tipi=?"; $params[] = $odemeTipiF; }
if ($kasiyerF) { $where .= " AND s.kullanici_id=?"; $params[] = $kasiyerF; }
if ($tipF) { $where .= " AND s.tip=?"; $params[] = $tipF; }
if ($urunF) { $where .= " AND EXISTS (SELECT 1 FROM satis_kalemleri sk WHERE sk.satis_id=s.id AND sk.urun_id=?)"; $params[] = $urunF; }
if ($tutarMin !== '' && is_numeric($tutarMin)) { $where .= " AND s.genel_toplam >= ?"; $params[] = (float)$tutarMin; }
if ($tutarMax !== '' && is_numeric($tutarMax)) { $where .= " AND s.genel_toplam <= ?"; $params[] = (float)$tutarMax; }

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id $where ORDER BY $orderBy");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="satislar_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['Fatura No','Tip','Müşteri','Tarih','Toplam','Ödenen','İade','Kalan','Ödeme Tipi','Durum'],';');
    foreach ($rows as $r) {
        fputcsv($out,[csvHucre($r['fatura_no']),csvHucre($r['tip']==='on_siparis'?'Ön Sipariş':'Satış'),csvHucre($r['musteri_adi']?:'Perakende'),$r['tarih'],
            number_format($r['genel_toplam'],2,',','.'),
            number_format($r['odenen_tutar'],2,',','.'),
            number_format($r['iade_toplam'],2,',','.'),
            number_format($r['kalan_tutar'],2,',','.'),
            csvHucre($r['odeme_tipi']),csvHucre($r['durum'])],';');
    }
    fclose($out); exit;
}
$sayfa  = max(1,(int)($_GET['s']??1));
$limit  = 25; $offset = ($sayfa-1)*$limit;

$toplam = $pdo->prepare("SELECT COUNT(*) FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id $where");
$toplam->execute($params); $toplam = (int)$toplam->fetchColumn();

$stmt = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id $where ORDER BY $orderBy LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$satislar = $stmt->fetchAll();

$ozet = $pdo->prepare("SELECT SUM(genel_toplam) AS toplam, SUM(kalan_tutar) AS kalan, SUM(iade_toplam) AS iade, COUNT(*) AS adet FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id $where AND s.durum!='iptal'");
$ozet->execute($params); $ozet = $ozet->fetch();
$sayfaSayisi = ceil($toplam/$limit);

$kasiyerler = $pdo->query("SELECT id, ad_soyad FROM kullanicilar WHERE rol IN ('yonetici','kasiyer') ORDER BY ad_soyad")->fetchAll();
$bekleyenUyariGun = (int)ayar('bekleyen_satis_uyari_gun', '3');

// Mevcut filtreleri koruyarak query string üret
function qs(array $degisiklik = []): string {
    $q = array_merge([
        'ara' => $_GET['ara'] ?? '', 'bas' => $_GET['bas'] ?? '', 'bit' => $_GET['bit'] ?? '',
        'durum' => $_GET['durum'] ?? '', 'odeme' => $_GET['odeme'] ?? '', 'kasiyer' => $_GET['kasiyer'] ?? '',
        'urun' => $_GET['urun'] ?? '', 'tmin' => $_GET['tmin'] ?? '', 'tmax' => $_GET['tmax'] ?? '',
        'tip' => $_GET['tip'] ?? '', 'srl' => $_GET['srl'] ?? '', 'yon' => $_GET['yon'] ?? '', 's' => $_GET['s'] ?? '',
    ], $degisiklik);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
function srlBaslik(string $anahtar, string $etiket): string {
    global $srl, $yon;
    $siralamalar = ['id' => 's.id', 'tarih' => 's.tarih', 'tutar' => 's.genel_toplam', 'kalan' => 's.kalan_tutar'];
    $srlAnahtar = array_search($srl, $siralamalar) ?: 'id';
    $yeniYon = ($srlAnahtar === $anahtar && $yon === 'asc') ? 'desc' : 'asc';
    $ok = $srlAnahtar === $anahtar ? ($yon === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '';
    return '<a class="text-decoration-none text-dark" href="?' . qs(['srl' => $anahtar, 'yon' => $yeniYon, 's' => 1]) . '">' . $etiket . $ok . '</a>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-receipt text-primary"></i> Satışlar</h4>
    <div class="d-flex gap-2">
        <a href="?<?= qs(['export'=>'csv']) ?>"
           class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-csv"></i> CSV İndir</a>
        <a href="yeni.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Satış</a>
    </div>
</div>

<!-- Özet -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2">
                <div class="small">Filtrelenmiş Toplam</div>
                <div class="fw-bold fs-5"><?= para($ozet['toplam']??0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body py-2">
                <div class="small">Tahsil Edilemeyen</div>
                <div class="fw-bold fs-5"><?= para($ozet['kalan']??0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-danger text-white">
            <div class="card-body py-2">
                <div class="small">İade Edilen</div>
                <div class="fw-bold fs-5"><?= para($ozet['iade']??0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
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
        <form class="row g-2 align-items-end" method="get" id="filtreForm">
            <div class="col-md-3">
                <label class="form-label small mb-1">Ara</label>
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Fatura no / Müşteri..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Başlangıç</label>
                <input type="date" name="bas" id="filtreBas" class="form-control form-control-sm" value="<?= $bas ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Bitiş</label>
                <input type="date" name="bit" id="filtreBit" class="form-control form-control-sm" value="<?= $bit ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Durum</label>
                <select name="durum" class="form-select form-select-sm">
                    <option value="">Tüm Durumlar</option>
                    <option value="tamamlandi" <?= $durum==='tamamlandi'?'selected':'' ?>>Tamamlandı</option>
                    <option value="bekliyor" <?= $durum==='bekliyor'?'selected':'' ?>>Bekliyor</option>
                    <option value="iptal" <?= $durum==='iptal'?'selected':'' ?>>İptal</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Ödeme Tipi</label>
                <select name="odeme" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <option value="nakit" <?= $odemeTipiF==='nakit'?'selected':'' ?>>Nakit</option>
                    <option value="kredi_karti" <?= $odemeTipiF==='kredi_karti'?'selected':'' ?>>Kredi Kartı</option>
                    <option value="havale" <?= $odemeTipiF==='havale'?'selected':'' ?>>Havale/EFT</option>
                    <option value="bolunmus" <?= $odemeTipiF==='bolunmus'?'selected':'' ?>>Bölünmüş</option>
                    <option value="taksitli" <?= $odemeTipiF==='taksitli'?'selected':'' ?>>Taksitli</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Kasiyer</label>
                <select name="kasiyer" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($kasiyerler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $kasiyerF===(int)$k['id']?'selected':'' ?>><?= escH($k['ad_soyad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Tip</label>
                <select name="tip" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <option value="satis" <?= $tipF==='satis'?'selected':'' ?>>Satış</option>
                    <option value="on_siparis" <?= $tipF==='on_siparis'?'selected':'' ?>>Ön Sipariş</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Tutar Min</label>
                <input type="number" name="tmin" step="0.01" class="form-control form-control-sm" value="<?= escH($tutarMin) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Tutar Max</label>
                <input type="number" name="tmax" step="0.01" class="form-control form-control-sm" value="<?= escH($tutarMax) ?>">
            </div>
            <?php if ($urunF): ?>
            <input type="hidden" name="urun" value="<?= $urunF ?>">
            <div class="col-md-3">
                <span class="badge bg-info text-dark py-2">Ürün filtresi aktif <a href="?<?= qs(['urun'=>'']) ?>" class="text-dark ms-1">✕</a></span>
            </div>
            <?php endif; ?>
            <div class="col-md-3 d-flex gap-1">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Temizle</a>
            </div>
        </form>
        <div class="d-flex flex-wrap gap-1 mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="tarihKisayol(0,0)">Bugün</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="tarihKisayol(-1,-1)">Dün</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="tarihHafta()">Bu Hafta</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="tarihAy(0)">Bu Ay</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="tarihAy(-1)">Geçen Ay</button>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th><?= srlBaslik('id','Fatura No') ?></th><th>Müşteri</th><th><?= srlBaslik('tarih','Tarih') ?></th>
                <th><?= srlBaslik('tutar','Tutar') ?></th><th>Ödenen</th><th><?= srlBaslik('kalan','Kalan') ?></th>
                <th>Ödeme Tipi</th><th>Durum</th><th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($satislar)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Kayıt bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($satislar as $s): ?>
            <?php
                $renk = $s['durum']==='tamamlandi'?'success':($s['durum']==='iptal'?'danger':'warning');
                $bekleyenGun = $s['durum']==='bekliyor' ? floor((time() - strtotime($s['tarih'])) / 86400) : 0;
            ?>
            <tr>
                <td>
                    <strong><?= escH($s['fatura_no']) ?></strong>
                    <?php if ($s['tip']==='on_siparis'): ?><span class="badge bg-info text-dark ms-1" title="Ön Sipariş"><i class="bi bi-bag-plus"></i></span><?php endif; ?>
                    <?php if ($s['iade_toplam']>0): ?><span class="badge bg-danger ms-1" title="İade var"><i class="bi bi-arrow-return-left"></i></span><?php endif; ?>
                </td>
                <td><?= escH($s['musteri_adi'] ?: 'Perakende') ?></td>
                <td><?= tarih($s['tarih']) ?></td>
                <td><?= para($s['genel_toplam']) ?></td>
                <td><?= para($s['odenen_tutar']) ?></td>
                <td class="<?= $s['kalan_tutar']>0?'text-danger fw-bold':'' ?>"><?= $s['kalan_tutar']>0?para($s['kalan_tutar']):'-' ?></td>
                <td><?= $s['odeme_tipi']==='bolunmus' ? 'Bölünmüş' : ucfirst(str_replace('_',' ',$s['odeme_tipi'])) ?></td>
                <td>
                    <span class="badge bg-<?= $renk ?>"><?= $s['durum']==='tamamlandi'?'Tamamlandı':($s['durum']==='iptal'?'İptal':'Bekliyor') ?></span>
                    <?php if ($bekleyenGun >= $bekleyenUyariGun): ?>
                    <span class="badge bg-danger" title="<?= $bekleyenGun ?> gündür bekliyor"><?= $bekleyenGun ?>g</span>
                    <?php endif; ?>
                </td>
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
                <a class="page-link" href="?<?= qs(['s'=>$i]) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<script>
function ymd(d) { return d.toISOString().slice(0,10); }
function tarihKisayol(basGun, bitGun) {
    const bas = new Date(); bas.setDate(bas.getDate() + basGun);
    const bit = new Date(); bit.setDate(bit.getDate() + bitGun);
    document.getElementById('filtreBas').value = ymd(bas);
    document.getElementById('filtreBit').value = ymd(bit);
    document.getElementById('filtreForm').submit();
}
function tarihHafta() {
    const bugun = new Date();
    const gun = (bugun.getDay() + 6) % 7; // Pazartesi=0
    const bas = new Date(bugun); bas.setDate(bugun.getDate() - gun);
    document.getElementById('filtreBas').value = ymd(bas);
    document.getElementById('filtreBit').value = ymd(bugun);
    document.getElementById('filtreForm').submit();
}
function tarihAy(kayGun) {
    const d = new Date();
    d.setMonth(d.getMonth() + kayGun);
    const bas = new Date(d.getFullYear(), d.getMonth(), 1);
    const bit = kayGun === 0 ? new Date() : new Date(d.getFullYear(), d.getMonth()+1, 0);
    document.getElementById('filtreBas').value = ymd(bas);
    document.getElementById('filtreBit').value = ymd(bit);
    document.getElementById('filtreForm').submit();
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
