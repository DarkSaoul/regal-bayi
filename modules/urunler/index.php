<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Ürünler';
$pdo = db();
$yonetici = ($_SESSION['rol'] ?? '') === 'yonetici';

$arama  = trim($_GET['ara'] ?? '');
$kat    = (int)($_GET['kat'] ?? 0);
$marka  = trim($_GET['marka'] ?? '');
$durum  = $_GET['durum'] ?? '1';                 // 1=aktif, 0=pasif, tumu
if (!in_array($durum, ['1','0','tumu'], true)) $durum = '1';
$stokF  = $_GET['stok'] ?? '';                    // yok | kritik | var
if (!in_array($stokF, ['','yok','kritik','var'], true)) $stokF = '';
$ozel   = $_GET['ozel'] ?? '';                    // barkodsuz | olu
if (!in_array($ozel, ['','barkodsuz','olu'], true)) $ozel = '';
$limit  = (int)($_GET['adet'] ?? 20);
if (!in_array($limit, [20,50,100], true)) $limit = 20;
$sayfa  = max(1, (int)($_GET['s'] ?? 1));
$offset = ($sayfa - 1) * $limit;

// Sıralama (whitelist)
$siralamalar = [
    'id'    => 'u.id',
    'ad'    => 'u.ad',
    'kod'   => 'u.kod',
    'alis'  => 'u.alis_fiyati',
    'satis' => 'u.satis_fiyati',
    'stok'  => 'u.stok_adedi',
    'marj'  => '(u.satis_fiyati - u.alis_fiyati)',
    'deger' => '(u.stok_adedi * u.alis_fiyati)',
];
$srl = isset($siralamalar[$_GET['srl'] ?? '']) ? $_GET['srl'] : 'id';
$yon = ($_GET['yon'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$orderBy = $siralamalar[$srl] . ' ' . strtoupper($yon);

$where = "WHERE 1=1";
$params = [];
if ($durum !== 'tumu') { $where .= " AND u.aktif=" . (int)$durum; }
if ($arama) { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ? OR u.model LIKE ?)"; $params = array_merge($params, array_fill(0, 4, likeParam($arama))); }
if ($kat)   { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params = array_merge($params, [$kat, $kat]); }
if ($marka) { $where .= " AND u.marka=?"; $params[] = $marka; }
if ($stokF === 'yok')    $where .= " AND u.stok_adedi <= 0";
if ($stokF === 'kritik') $where .= " AND u.stok_adedi > 0 AND u.stok_adedi <= u.min_stok";
if ($stokF === 'var')    $where .= " AND u.stok_adedi > u.min_stok";
if ($ozel === 'barkodsuz') $where .= " AND (u.barkod IS NULL OR u.barkod='')";
if ($ozel === 'olu') $where .= " AND NOT EXISTS (SELECT 1 FROM satis_kalemleri sk JOIN satislar sx ON sk.satis_id=sx.id
                                 WHERE sk.urun_id=u.id AND sx.durum!='iptal' AND sx.tarih >= DATE_SUB(CURDATE(), INTERVAL 90 DAY))";

// Toplam + envanter özeti (filtrelenmiş küme üzerinden)
$ozetStmt = $pdo->prepare("SELECT COUNT(*) AS adet, COALESCE(SUM(u.stok_adedi),0) AS stok,
        COALESCE(SUM(u.stok_adedi*u.alis_fiyati),0) AS maliyet_degeri,
        COALESCE(SUM(u.stok_adedi*u.satis_fiyati),0) AS satis_degeri
    FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where");
$ozetStmt->execute($params);
$ozet = $ozetStmt->fetch();
$toplam = (int)$ozet['adet'];

$stmt = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi,
        (SELECT COUNT(*) FROM satis_kalemleri sk WHERE sk.urun_id=u.id) AS satis_sayisi
    FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id
    $where ORDER BY $orderBy LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$urunler = $stmt->fetchAll();

$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id, ad")->fetchAll();
$markalar = $pdo->query("SELECT DISTINCT marka FROM urunler WHERE marka IS NOT NULL AND marka!='' ORDER BY marka")->fetchAll(PDO::FETCH_COLUMN);
$sayfaSayisi = ceil($toplam / $limit);

// Mevcut filtreleri koruyarak query string üret
function qs(array $degisiklik = []): string {
    $q = array_merge([
        'ara' => $_GET['ara'] ?? '', 'kat' => $_GET['kat'] ?? '', 'marka' => $_GET['marka'] ?? '',
        'durum' => $_GET['durum'] ?? '1', 'stok' => $_GET['stok'] ?? '', 'ozel' => $_GET['ozel'] ?? '',
        'adet' => $_GET['adet'] ?? '', 'srl' => $_GET['srl'] ?? '', 'yon' => $_GET['yon'] ?? '', 's' => $_GET['s'] ?? '',
    ], $degisiklik);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
// Sıralanabilir başlık linki
function srlBaslik(string $anahtar, string $etiket): string {
    global $srl, $yon;
    $yeniYon = ($srl === $anahtar && $yon === 'asc') ? 'desc' : 'asc';
    $ok = $srl === $anahtar ? ($yon === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '';
    return '<a class="text-decoration-none text-dark" href="?' . qs(['srl' => $anahtar, 'yon' => $yeniYon, 's' => 1]) . '">' . $etiket . $ok . '</a>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-box-seam text-primary"></i> Ürünler</h4>
    <div class="d-flex flex-wrap gap-2">
        <a href="csv.php?<?= qs(['s' => '']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV İndir</a>
        <?php if ($yonetici): ?>
        <a href="ice_aktar.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-upload"></i> İçe Aktar</a>
        <a href="toplu_fiyat.php" class="btn btn-sm btn-outline-warning"><i class="bi bi-percent"></i> Toplu Fiyat</a>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="etiketBas()"><i class="bi bi-upc"></i> Etiket Bas</button>
        <a href="ekle.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> Yeni Ürün</a>
    </div>
</div>

<!-- Envanter özeti -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Ürün / Toplam Stok</div>
            <div class="fw-bold"><?= $toplam ?> çeşit · <?= (int)$ozet['stok'] ?> adet</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Envanter Değeri (Alış)</div>
            <div class="fw-bold text-danger"><?= para($ozet['maliyet_degeri']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Envanter Değeri (Satış)</div>
            <div class="fw-bold text-success"><?= para($ozet['satis_degeri']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Potansiyel Kâr</div>
            <div class="fw-bold text-primary"><?= para($ozet['satis_degeri'] - $ozet['maliyet_degeri']) ?></div>
        </div></div>
    </div>
</div>

<!-- Filtreler -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ad, kod, barkod veya model ara..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="kat" class="form-select form-select-sm">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach ($kategoriler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $kat==$k['id']?'selected':'' ?>><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="marka" class="form-select form-select-sm">
                    <option value="">Tüm Markalar</option>
                    <?php foreach ($markalar as $m): ?>
                    <option value="<?= escH($m) ?>" <?= $marka===$m?'selected':'' ?>><?= escH($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="stok" class="form-select form-select-sm" title="Stok durumu">
                    <option value="">Stok: Tümü</option>
                    <option value="yok" <?= $stokF==='yok'?'selected':'' ?>>Stokta Yok</option>
                    <option value="kritik" <?= $stokF==='kritik'?'selected':'' ?>>Kritik</option>
                    <option value="var" <?= $stokF==='var'?'selected':'' ?>>Yeterli</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="durum" class="form-select form-select-sm" title="Kayıt durumu">
                    <option value="1" <?= $durum==='1'?'selected':'' ?>>Aktif</option>
                    <option value="0" <?= $durum==='0'?'selected':'' ?>>Arşiv</option>
                    <option value="tumu" <?= $durum==='tumu'?'selected':'' ?>>Tümü</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="ozel" class="form-select form-select-sm" title="Özel filtre">
                    <option value="">Özel: Yok</option>
                    <option value="barkodsuz" <?= $ozel==='barkodsuz'?'selected':'' ?>>Barkodsuz</option>
                    <option value="olu" <?= $ozel==='olu'?'selected':'' ?>>90 Gün Satışsız</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="adet" class="form-select form-select-sm" title="Sayfa boyu">
                    <?php foreach ([20,50,100] as $a): ?>
                    <option value="<?= $a ?>" <?= $limit===$a?'selected':'' ?>><?= $a ?>/syf</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<?php if ($yonetici): ?>
<!-- Toplu işlem çubuğu (satır formlarıyla çakışmamak için form dışarıda, inputlar form= ile bağlı) -->
<form method="post" action="toplu_islem.php" id="topluForm"
      onsubmit="return topluOnay()"><?= csrfField() ?></form>
<div class="card shadow-sm mb-2 d-none" id="topluBar">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold small"><span id="seciliAdet">0</span> ürün seçili:</span>
        <select name="islem" form="topluForm" class="form-select form-select-sm w-auto" id="topluIslem" onchange="topluAlan()">
            <option value="pasif">Arşive Taşı</option>
            <option value="aktif">Arşivden Çıkar</option>
            <option value="kategori">Kategori Değiştir</option>
            <option value="kdv">KDV Güncelle</option>
        </select>
        <select name="kategori_id" form="topluForm" class="form-select form-select-sm w-auto d-none" id="topluKategori">
            <?php foreach ($kategoriler as $k): ?>
            <option value="<?= $k['id'] ?>"><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="kdv_orani" form="topluForm" class="form-select form-select-sm w-auto d-none" id="topluKdv">
            <?php foreach ([0,1,10,20] as $kdv): ?><option value="<?= $kdv ?>">%<?= $kdv ?></option><?php endforeach; ?>
        </select>
        <button type="submit" form="topluForm" class="btn btn-sm btn-warning"><i class="bi bi-lightning"></i> Uygula</button>
        <input type="hidden" name="geri" form="topluForm" value="<?= escH(qs()) ?>">
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <?php if ($yonetici): ?><th style="width:28px"><input type="checkbox" class="form-check-input" id="tumunuSec" onclick="tumunuSec(this)"></th><?php endif; ?>
                <th style="width:46px"></th>
                <th><?= srlBaslik('kod','Kod') ?></th>
                <th><?= srlBaslik('ad','Ürün Adı') ?></th>
                <th>Kategori</th>
                <th class="text-end"><?= srlBaslik('alis','Alış') ?></th>
                <th class="text-end"><?= srlBaslik('satis','Satış') ?></th>
                <th class="text-end"><?= srlBaslik('marj','Marj') ?></th>
                <th class="text-center"><?= srlBaslik('stok','Stok') ?></th>
                <th class="text-end"><?= srlBaslik('deger','Değer') ?></th>
                <th style="min-width:170px">İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($urunler)): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Ürün bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($urunler as $u): ?>
            <?php
                $marj = ($u['alis_fiyati'] > 0 && $u['satis_fiyati'] > 0)
                    ? round(($u['satis_fiyati'] - $u['alis_fiyati']) / $u['alis_fiyati'] * 100, 1) : null;
                $marjRenk = $marj === null ? 'secondary' : ($marj < 0 ? 'danger' : ($marj < 10 ? 'warning' : 'success'));
            ?>
            <tr class="<?= $u['aktif'] ? '' : 'table-secondary' ?>">
                <?php if ($yonetici): ?>
                <td><input type="checkbox" class="form-check-input urun-sec" name="urun[]" form="topluForm" value="<?= $u['id'] ?>" onclick="seciliGuncelle()"></td>
                <?php endif; ?>
                <td>
                    <?php if ($u['resim']): ?>
                    <img src="<?= BASE_URL ?>/uploads/urunler/<?= escH($u['resim']) ?>" alt="" class="rounded border" style="width:40px;height:40px;object-fit:cover">
                    <?php else: ?>
                    <div class="rounded border bg-light d-flex align-items-center justify-content-center text-muted" style="width:40px;height:40px"><i class="bi bi-image"></i></div>
                    <?php endif; ?>
                </td>
                <td><code><?= escH($u['kod']) ?></code><?= $u['aktif'] ? '' : '<br><span class="badge bg-secondary">Arşiv</span>' ?></td>
                <td>
                    <a href="detay.php?id=<?= $u['id'] ?>" class="fw-semibold text-decoration-none"><?= escH($u['ad']) ?></a>
                    <br><small class="text-muted"><?= escH(trim($u['marka'] . ' ' . ($u['model'] ?? ''))) ?><?= empty($u['barkod']) ? ' · <span class="text-warning">barkodsuz</span>' : '' ?></small>
                </td>
                <td><?= escH($u['kategori_adi'] ?? '-') ?></td>
                <td class="text-end"><?= para($u['alis_fiyati']) ?></td>
                <td class="text-end fw-semibold text-primary"><?= para($u['satis_fiyati']) ?></td>
                <td class="text-end"><?php if ($marj !== null): ?><span class="badge bg-<?= $marjRenk ?>">%<?= $marj ?></span><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                <td class="text-center">
                    <?php $renk = $u['stok_adedi'] <= 0 ? 'dark' : ($u['stok_adedi'] <= $u['min_stok'] ? 'danger' : 'success'); ?>
                    <span class="badge bg-<?= $renk ?>" title="Min. stok: <?= $u['min_stok'] ?>"><?= $u['stok_adedi'] ?> <?= escH($u['birim']) ?></span>
                </td>
                <td class="text-end"><?= para($u['stok_adedi'] * $u['alis_fiyati']) ?></td>
                <td class="text-nowrap">
                    <a href="detay.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-info" title="Detay"><i class="bi bi-eye"></i></a>
                    <a href="duzenle.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" title="Düzenle"><i class="bi bi-pencil"></i></a>
                    <a href="<?= BASE_URL ?>/modules/stok/giris.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-success" title="Stok Giriş"><i class="bi bi-plus"></i></a>
                    <a href="ekle.php?kopya=<?= $u['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Kopyala"><i class="bi bi-files"></i></a>
                    <a href="etiket.php?ids=<?= $u['id'] ?>" class="btn btn-sm btn-outline-dark" title="Etiket"><i class="bi bi-upc"></i></a>
                    <?php if ($yonetici): ?>
                    <?php if ($u['aktif']): ?>
                    <form method="post" action="sil.php" class="d-inline"
                          onsubmit="return confirm('<?= escH($u['ad']) ?> arşive taşınacak.<?= $u['satis_sayisi'] ? '\n\nDikkat: Bu ürünün ' . (int)$u['satis_sayisi'] . ' satış kaydı var (kayıtlar silinmez, ürün satışa kapanır).' : '' ?>\n\nEmin misiniz?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="islem" value="arsivle">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Arşive Taşı"><i class="bi bi-archive"></i></button>
                    </form>
                    <?php else: ?>
                    <form method="post" action="sil.php" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="islem" value="aktif">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Arşivden Çıkar"><i class="bi bi-arrow-counterclockwise"></i></button>
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
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end flex-wrap">
            <?php for ($i=1; $i<=$sayfaSayisi; $i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>">
                <a class="page-link" href="?<?= qs(['s' => $i]) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<div class="mt-2 text-muted small">Toplam <?= $toplam ?> ürün listeleniyor</div>

<script>
function tumunuSec(kaynak) {
    document.querySelectorAll('.urun-sec').forEach(c => c.checked = kaynak.checked);
    seciliGuncelle();
}
function seciliGuncelle() {
    const adet = document.querySelectorAll('.urun-sec:checked').length;
    const bar = document.getElementById('topluBar');
    if (bar) {
        document.getElementById('seciliAdet').textContent = adet;
        bar.classList.toggle('d-none', adet === 0);
    }
}
function topluAlan() {
    const islem = document.getElementById('topluIslem').value;
    document.getElementById('topluKategori').classList.toggle('d-none', islem !== 'kategori');
    document.getElementById('topluKdv').classList.toggle('d-none', islem !== 'kdv');
}
function topluOnay() {
    const adet = document.querySelectorAll('.urun-sec:checked').length;
    if (!adet) { alert('Ürün seçilmedi.'); return false; }
    return confirm(adet + ' ürüne bu işlem uygulanacak. Emin misiniz?');
}
function etiketBas() {
    const ids = [...document.querySelectorAll('.urun-sec:checked')].map(c => c.value);
    if (!ids.length) { alert('Etiket basılacak ürünleri listeden işaretleyin.'); return; }
    window.open('etiket.php?ids=' + ids.join(','), '_blank');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
