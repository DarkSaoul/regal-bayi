<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Kasa & Finans';
$pdo = db();
$rol = $_SESSION['rol'] ?? '';

$bugun = date('Y-m-d');
$buAy  = date('Y-m');

$kasaBakiye  = kasaBakiyesi('kasa');
$bankaBakiye = kasaBakiyesi('banka');

$s = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) FROM kasa_hareketleri WHERE tip='giris' AND onay_durumu='onaylandi' AND tarih=?");
$s->execute([$bugun]); $bugunGiris = $s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) FROM kasa_hareketleri WHERE tip='cikis' AND onay_durumu='onaylandi' AND tarih=?");
$s->execute([$bugun]); $bugunCikis = $s->fetchColumn();
$bekleyenBorc = $pdo->query("SELECT COALESCE(SUM(kalan_tutar),0) FROM satislar WHERE kalan_tutar>0 AND durum='bekliyor'")->fetchColumn();

$minBakiyeUyari = (float)ayar('kasa_min_bakiye_uyari', '0');
$dusukBakiye = $minBakiyeUyari > 0 && $kasaBakiye < $minBakiyeUyari;
$onayBekleyen = onayBekleyenGiderSayisi();

// ── Filtreler ─────────────────────────────────────────────────
$bas = gecerliTarih($_GET['bas'] ?? '', date('Y-m-01'));
$bit = gecerliTarih($_GET['bit'] ?? '', $bugun);
$kategoriF = trim($_GET['kategori'] ?? '');
$hesapF = in_array($_GET['hesap'] ?? '', ['kasa','banka'], true) ? $_GET['hesap'] : '';

$siralamalar = ['tarih' => 'k.created_at', 'tutar' => 'k.tutar'];
$srl = isset($siralamalar[$_GET['srl'] ?? '']) ? $_GET['srl'] : 'tarih';
$yon = ($_GET['yon'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$orderBy = $siralamalar[$srl] . ' ' . strtoupper($yon);

$where = "WHERE k.tarih BETWEEN ? AND ?";
$params = [$bas, $bit];
if ($kategoriF) { $where .= " AND k.kategori=?"; $params[] = $kategoriF; }
if ($hesapF) { $where .= " AND k.hesap=?"; $params[] = $hesapF; }

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("SELECT k.*, ku.ad_soyad AS kullanici FROM kasa_hareketleri k LEFT JOIN kullanicilar ku ON k.kullanici_id=ku.id $where ORDER BY $orderBy");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kasa_hareketleri_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tarih','Tip','Hesap','Tutar','Kategori','Açıklama','Onay Durumu','Kullanıcı'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [$r['tarih'], $r['tip']==='giris'?'Giriş':'Çıkış', $r['hesap'], number_format($r['tutar'],2,',','.'),
            csvHucre($r['kategori']), csvHucre($r['aciklama']), $r['onay_durumu'], csvHucre($r['kullanici'])], ';');
    }
    fclose($out); exit;
}

$sayfa  = max(1, (int)($_GET['s'] ?? 1));
$limit  = 30; $offset = ($sayfa-1)*$limit;
$toplamSayi = $pdo->prepare("SELECT COUNT(*) FROM kasa_hareketleri k $where");
$toplamSayi->execute($params); $toplamSayi = (int)$toplamSayi->fetchColumn();
$sayfaSayisi = ceil($toplamSayi / $limit);

$stmt = $pdo->prepare("SELECT k.*, ku.ad_soyad AS kullanici FROM kasa_hareketleri k LEFT JOIN kullanicilar ku ON k.kullanici_id=ku.id
    $where ORDER BY $orderBy LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$hareketler = $stmt->fetchAll();

$tumKategoriler = $pdo->query("SELECT DISTINCT kategori FROM kasa_hareketleri WHERE kategori IS NOT NULL ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);

// Vadesi gelen tahsilatlar
$vadeli = $pdo->query("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s JOIN musteriler m ON s.musteri_id=m.id WHERE s.kalan_tutar>0 AND s.durum='bekliyor' ORDER BY s.tarih LIMIT 10")->fetchAll();

// ── Gelir-gider trend (seçili aralık, günlük) ─────────────────
$trend = $pdo->prepare("SELECT tarih, tip, SUM(tutar) AS toplam FROM kasa_hareketleri
    WHERE onay_durumu='onaylandi' AND tarih BETWEEN ? AND ? GROUP BY tarih, tip ORDER BY tarih");
$trend->execute([$bas, $bit]);
$trendGunler = []; // tarih => ['giris'=>x,'cikis'=>y]
foreach ($trend->fetchAll() as $t) {
    $trendGunler[$t['tarih']][$t['tip']] = (float)$t['toplam'];
}
ksort($trendGunler);

// ── Kategori bazlı gider dağılımı (bu ay) ─────────────────────
$kategoriDagilim = $pdo->prepare("SELECT kategori, SUM(tutar) AS toplam FROM kasa_hareketleri
    WHERE tip='cikis' AND onay_durumu='onaylandi' AND DATE_FORMAT(tarih,'%Y-%m')=? GROUP BY kategori ORDER BY toplam DESC");
$kategoriDagilim->execute([$buAy]);
$kategoriDagilim = $kategoriDagilim->fetchAll();

function qs(array $degisiklik = []): string {
    $q = array_merge([
        'bas' => $_GET['bas'] ?? '', 'bit' => $_GET['bit'] ?? '', 'kategori' => $_GET['kategori'] ?? '',
        'hesap' => $_GET['hesap'] ?? '', 'srl' => $_GET['srl'] ?? '', 'yon' => $_GET['yon'] ?? '', 's' => $_GET['s'] ?? '',
    ], $degisiklik);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
function srlBaslik(string $anahtar, string $etiket): string {
    global $srl, $yon;
    $siralamalar = ['tarih' => 'k.created_at', 'tutar' => 'k.tutar'];
    $srlAnahtar = array_search($srl, $siralamalar) ?: 'tarih';
    $yeniYon = ($srlAnahtar === $anahtar && $yon === 'asc') ? 'desc' : 'asc';
    $ok = $srlAnahtar === $anahtar ? ($yon === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '';
    return '<a class="text-decoration-none text-dark" href="?' . qs(['srl' => $anahtar, 'yon' => $yeniYon, 's' => 1]) . '">' . $etiket . $ok . '</a>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4><i class="bi bi-cash-stack text-primary"></i> Kasa & Finans</h4>
    <div class="d-flex gap-2 flex-wrap">
        <a href="kasa_hareketi.php" class="btn btn-outline-primary"><i class="bi bi-plus-circle"></i> Kasa Hareketi</a>
        <a href="tahsilat.php" class="btn btn-success"><i class="bi bi-cash-coin"></i> Tahsilat Al</a>
    </div>
</div>

<!-- Araç çubuğu -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="vardiya.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left-right"></i> Vardiya</a>
    <?php if ($rol === 'yonetici'): ?>
    <a href="kategoriler.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tags"></i> Kategoriler</a>
    <a href="gider_sablonlari.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Tekrarlayan Giderler</a>
    <a href="gider_toplu.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-upload"></i> Toplu Gider</a>
    <a href="onay.php" class="btn btn-sm btn-outline-warning position-relative">
        <i class="bi bi-check2-square"></i> Onaylar
        <?php if ($onayBekleyen > 0): ?><span class="badge bg-danger ms-1"><?= $onayBekleyen ?></span><?php endif; ?>
    </a>
    <a href="capraz_kontrol.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-circle"></i> Tedarikçi Çapraz Kontrol</a>
    <a href="kdv_raporu.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-receipt-cutoff"></i> KDV Özeti</a>
    <a href="nakit_akis.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-up"></i> Nakit Akış Tahmini</a>
    <?php endif; ?>
    <a href="kapanis.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-door-closed"></i> Kasa Kapanışı</a>
    <a href="taksit_takvimi.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar-week"></i> Taksit Takvimi</a>
</div>

<?php if ($dusukBakiye): ?>
<div class="alert alert-danger d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <span>Kasa bakiyesi (<?= para($kasaBakiye) ?>) uyarı eşiğinin (<?= para($minBakiyeUyari) ?>) altında.</span>
</div>
<?php endif; ?>

<!-- Kartlar -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 <?= $dusukBakiye ? 'bg-danger' : 'bg-success' ?> text-white">
            <div class="card-body">
                <div class="small">Kasa Bakiyesi (Nakit)</div>
                <div class="fw-bold fs-4"><?= para($kasaBakiye) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <div class="small">Banka Bakiyesi</div>
                <div class="fw-bold fs-4"><?= para($bankaBakiye) ?></div>
                <div class="small opacity-75">Kart + Havale tahsilatları</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-info text-white">
            <div class="card-body">
                <div class="small">Bugün Giriş / Çıkış</div>
                <div class="fw-bold fs-5"><?= para($bugunGiris) ?> / <?= para($bugunCikis) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body">
                <div class="small">Bekleyen Tahsilat</div>
                <div class="fw-bold fs-4"><?= para($bekleyenBorc) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Grafikler -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">Gelir - Gider Trendi (Seçili Aralık)</div>
            <div class="card-body"><canvas id="trendGrafik" height="90"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">Bu Ay Gider Dağılımı</div>
            <div class="card-body">
                <?php if (empty($kategoriDagilim)): ?>
                <div class="text-center text-muted py-4">Bu ay gider yok</div>
                <?php else: ?>
                <canvas id="kategoriGrafik" height="180"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filtre -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-2">
                <label class="form-label small mb-1">Başlangıç</label>
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= $bas ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Bitiş</label>
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= $bit ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Kategori</label>
                <select name="kategori" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tumKategoriler as $k): ?>
                    <option value="<?= escH($k) ?>" <?= $kategoriF===$k?'selected':'' ?>><?= escH($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Hesap</label>
                <select name="hesap" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <option value="kasa" <?= $hesapF==='kasa'?'selected':'' ?>>Kasa</option>
                    <option value="banka" <?= $hesapF==='banka'?'selected':'' ?>>Banka</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-1">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Temizle</a>
                <a href="?<?= qs(['export'=>'csv']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <!-- Kasa Hareketleri -->
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Kasa Hareketleri</div>
            <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th><?= srlBaslik('tarih','Tarih') ?></th><th>Tip</th><th>Hesap</th><th><?= srlBaslik('tutar','Tutar') ?></th><th>Kategori</th><th>Açıklama</th><th>Durum</th><th>Kullanıcı</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($hareketler)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">Hareket yok</td></tr>
                <?php else: ?>
                <?php foreach ($hareketler as $h): ?>
                <tr class="<?= $h['onay_durumu']==='bekliyor'?'table-warning':($h['onay_durumu']==='reddedildi'?'table-secondary':'') ?>">
                    <td><?= tarih($h['tarih']) ?></td>
                    <td><span class="badge bg-<?= $h['tip']==='giris'?'success':'danger' ?>"><?= $h['tip']==='giris'?'Giriş':'Çıkış' ?></span></td>
                    <td class="small"><?= $h['hesap']==='kasa'?'💵 Kasa':'🏦 Banka' ?></td>
                    <td class="fw-bold <?= $h['tip']==='giris'?'text-success':'text-danger' ?>"><?= para($h['tutar']) ?></td>
                    <td><?= escH($h['kategori']??'-') ?></td>
                    <td class="small"><?= escH($h['aciklama']??'-') ?><?php if ($h['belge']): ?> <a href="<?= BASE_URL ?>/uploads/kasa/<?= escH($h['belge']) ?>" target="_blank" title="Belge"><i class="bi bi-paperclip"></i></a><?php endif; ?></td>
                    <td>
                        <?php if ($h['onay_durumu']==='bekliyor'): ?><span class="badge bg-warning text-dark">Onay Bekliyor</span>
                        <?php elseif ($h['onay_durumu']==='reddedildi'): ?><span class="badge bg-secondary">Reddedildi</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= escH($h['kullanici']??'-') ?></td>
                    <td class="text-nowrap">
                        <?php if ($rol === 'yonetici' && !in_array($h['kategori'], ['Satış','Tahsilat'], true)): ?>
                        <a href="kasa_duzenle.php?id=<?= $h['id'] ?>" class="btn btn-xs btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="kasa_sil.php" class="d-inline"
                              onsubmit="return confirm('Bu hareketi silmek istediğinize emin misiniz?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger btn-sm py-0 px-1">
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
    </div>
    <!-- Bekleyen tahsilatlar -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold text-danger">
                <i class="bi bi-exclamation-circle"></i> Bekleyen Tahsilatlar
            </div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Müşteri</th><th>Fatura</th><th>Kalan</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($vadeli)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Bekleyen yok</td></tr>
                <?php else: ?>
                <?php foreach ($vadeli as $v): ?>
                <tr>
                    <td><?= escH($v['musteri_adi']) ?></td>
                    <td><small><?= escH($v['fatura_no']) ?></small></td>
                    <td class="text-danger fw-bold"><?= para($v['kalan_tutar']) ?></td>
                    <td><a href="tahsilat.php?satis_id=<?= $v['id'] ?>" class="btn btn-xs btn-outline-success btn-sm py-0 px-1"><i class="bi bi-cash"></i></a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<script>
const _trendEl = document.getElementById('trendGrafik');
if (_trendEl) document.addEventListener('DOMContentLoaded', () => new Chart(_trendEl, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($t) => date('d.m', strtotime($t)), array_keys($trendGunler))) ?>,
        datasets: [
            { label: 'Giriş', data: <?= json_encode(array_map(fn($g) => round($g['giris'] ?? 0, 2), $trendGunler)) ?>, backgroundColor: 'rgba(25,135,84,.7)', borderRadius: 4 },
            { label: 'Çıkış', data: <?= json_encode(array_map(fn($g) => round($g['cikis'] ?? 0, 2), $trendGunler)) ?>, backgroundColor: 'rgba(220,53,69,.7)', borderRadius: 4 }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true } } }
}));

const _katEl = document.getElementById('kategoriGrafik');
if (_katEl) document.addEventListener('DOMContentLoaded', () => new Chart(_katEl, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($kategoriDagilim, 'kategori')) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($k) => round($k['toplam'],2), $kategoriDagilim)) ?>,
            backgroundColor: ['#0d6efd','#dc3545','#ffc107','#198754','#6f42c1','#fd7e14','#20c997','#6c757d']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } }
}));
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
