<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Stok Hareketleri';
$pdo = db();

$urun_id   = (int)($_GET['urun_id'] ?? 0);
$tedarikci = (int)($_GET['tedarikci'] ?? 0);
$tip       = $_GET['tip'] ?? '';
if (!in_array($tip, ['','giris','cikis','iade_giris','fire','sayim_duzeltme'], true)) $tip = '';
$bas = gecerliTarih($_GET['bas'] ?? '', date('Y-m-d', strtotime('-30 days')));
$bit = gecerliTarih($_GET['bit'] ?? '', date('Y-m-d'));
$limit = 50;
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$offset = ($sayfa - 1) * $limit;

$where = "WHERE DATE(sh.created_at) BETWEEN ? AND ?";
$params = [$bas, $bit];
if ($urun_id)   { $where .= " AND sh.urun_id=?"; $params[] = $urun_id; }
if ($tedarikci) { $where .= " AND sh.tedarikci_id=?"; $params[] = $tedarikci; }
if ($tip)       { $where .= " AND sh.hareket_tipi=?"; $params[] = $tip; }

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("SELECT sh.*, u.ad AS urun_adi, u.kod, k.ad_soyad AS kullanici, t.ad AS tedarikci_adi
        FROM stok_hareketleri sh JOIN urunler u ON sh.urun_id=u.id
        LEFT JOIN kullanicilar k ON sh.kullanici_id=k.id LEFT JOIN tedarikciler t ON sh.tedarikci_id=t.id
        $where ORDER BY sh.id DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stok_hareketleri_' . $bas . '_' . $bit . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tarih','Kod','Ürün','Tip','Miktar','Önceki','Sonraki','Birim Maliyet','Toplam Maliyet','Belge','Tedarikçi','Kullanıcı','Açıklama'], ';');
    foreach ($stmt->fetchAll() as $h) {
        fputcsv($out, [$h['created_at'], csvHucre($h['kod']), csvHucre($h['urun_adi']), csvHucre($h['hareket_tipi']),
            $h['miktar'], $h['onceki_stok'], $h['sonraki_stok'],
            $h['birim_maliyet'] !== null ? number_format($h['birim_maliyet'], 2, ',', '.') : '',
            $h['toplam_maliyet'] !== null ? number_format($h['toplam_maliyet'], 2, ',', '.') : '',
            csvHucre($h['belge_no']), csvHucre($h['tedarikci_adi']), csvHucre($h['kullanici']), csvHucre($h['aciklama'])], ';');
    }
    fclose($out); exit;
}

// Dönem özeti
$ozet = $pdo->prepare("SELECT sh.hareket_tipi, COUNT(*) AS islem, SUM(sh.miktar) AS adet, COALESCE(SUM(sh.toplam_maliyet),0) AS maliyet
    FROM stok_hareketleri sh $where GROUP BY sh.hareket_tipi");
$ozet->execute($params);
$ozetler = [];
foreach ($ozet->fetchAll() as $o) $ozetler[$o['hareket_tipi']] = $o;

$toplamStmt = $pdo->prepare("SELECT COUNT(*) FROM stok_hareketleri sh $where");
$toplamStmt->execute($params);
$toplam = (int)$toplamStmt->fetchColumn();
$sayfaSayisi = max(1, ceil($toplam / $limit));

$stmt = $pdo->prepare("SELECT sh.*, u.ad AS urun_adi, u.kod, k.ad_soyad AS kullanici, t.ad AS tedarikci_adi
    FROM stok_hareketleri sh JOIN urunler u ON sh.urun_id=u.id
    LEFT JOIN kullanicilar k ON sh.kullanici_id=k.id LEFT JOIN tedarikciler t ON sh.tedarikci_id=t.id
    $where ORDER BY sh.id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$hareketler = $stmt->fetchAll();

$tedarikciler = $pdo->query("SELECT id, ad FROM tedarikciler ORDER BY ad")->fetchAll();
$filtreUrun = null;
if ($urun_id) {
    $fu = $pdo->prepare("SELECT kod, ad FROM urunler WHERE id=?"); $fu->execute([$urun_id]); $filtreUrun = $fu->fetch();
}

function qs(array $d = []): string {
    $q = array_merge(['urun_id'=>$_GET['urun_id'] ?? '', 'tedarikci'=>$_GET['tedarikci'] ?? '', 'tip'=>$_GET['tip'] ?? '',
        'bas'=>$_GET['bas'] ?? '', 'bit'=>$_GET['bit'] ?? '', 's'=>$_GET['s'] ?? ''], $d);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}

$tipEtiketleri = [
    'giris' => ['Giriş','success'], 'cikis' => ['Çıkış','danger'], 'iade_giris' => ['İade Giriş','info'],
    'fire' => ['Fire','warning'], 'sayim_duzeltme' => ['Sayım','secondary'],
];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-clock-history text-primary"></i> Stok Hareketleri
        <?php if ($filtreUrun): ?><small class="text-muted fs-6">— <?= escH($filtreUrun['ad']) ?></small><?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <a href="?<?= qs(['export'=>'csv','s'=>'']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Stok</a>
    </div>
</div>

<!-- Filtreler -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <?php if ($urun_id): ?><input type="hidden" name="urun_id" value="<?= $urun_id ?>"><?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Başlangıç</label>
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= escH($bas) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Bitiş</label>
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= escH($bit) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Tip</label>
                <select name="tip" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tipEtiketleri as $t => [$ad, $r]): ?>
                    <option value="<?= $t ?>" <?= $tip===$t?'selected':'' ?>><?= $ad ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0">Tedarikçi</label>
                <select name="tedarikci" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tedarikciler as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $tedarikci==$t['id']?'selected':'' ?>><?= escH($t['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
            <?php if ($urun_id): ?>
            <div class="col-6 col-md-2">
                <a href="?<?= qs(['urun_id'=>'','s'=>'']) ?>" class="btn btn-sm btn-outline-secondary w-100">Ürün filtresini kaldır</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Dönem özeti -->
<div class="row g-2 mb-3">
    <?php foreach ($tipEtiketleri as $t => [$ad, $r]): $o = $ozetler[$t] ?? null; ?>
    <div class="col-6 col-md">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted"><?= $ad ?></div>
            <div class="fw-bold text-<?= $r ?>"><?= $o ? (int)$o['adet'] . ' adet' : '—' ?></div>
            <?php if ($o && $o['maliyet'] > 0): ?><div class="small text-muted"><?= para($o['maliyet']) ?></div><?php endif; ?>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th>Tarih</th><th>Ürün</th><th>Tip</th>
                <th class="text-center">Miktar</th><th class="text-center">Önce→Sonra</th>
                <th class="text-end">Maliyet</th>
                <th>Belge</th><th>Tedarikçi</th><th>Kullanıcı</th><th>Açıklama</th>
            </tr></thead>
            <tbody>
            <?php if (!$hareketler): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Bu filtrelerle hareket bulunamadı</td></tr>
            <?php endif; ?>
            <?php foreach ($hareketler as $h): ?>
            <?php
            [$tipAdi, $renk] = $tipEtiketleri[$h['hareket_tipi']] ?? [$h['hareket_tipi'], 'secondary'];
            // Sayım düzeltmesinin yönünü göster
            if ($h['hareket_tipi'] === 'sayim_duzeltme') {
                $artis = $h['sonraki_stok'] > $h['onceki_stok'];
                $tipAdi = 'Sayım ' . ($artis ? '↑' : '↓');
                $renk = $artis ? 'info' : 'warning';
            }
            ?>
            <tr>
                <td class="text-nowrap"><?= tarihSaat($h['created_at']) ?></td>
                <td><a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $h['urun_id'] ?>" class="text-decoration-none">
                    <strong><?= escH($h['urun_adi']) ?></strong></a><br><small class="text-muted"><?= escH($h['kod']) ?></small></td>
                <td><span class="badge bg-<?= $renk ?>"><?= $tipAdi ?></span></td>
                <td class="text-center fw-bold"><?= $h['miktar'] ?></td>
                <td class="text-center text-muted"><?= $h['onceki_stok'] ?> → <?= $h['sonraki_stok'] ?></td>
                <td class="text-end"><?= $h['toplam_maliyet'] !== null ? para($h['toplam_maliyet']) : '-' ?></td>
                <td><?= escH($h['belge_no'] ?: '-') ?></td>
                <td><?= escH($h['tedarikci_adi'] ?: '-') ?></td>
                <td><?= escH($h['kullanici'] ?: '-') ?></td>
                <td class="small"><?= escH($h['aciklama'] ?: '-') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($sayfaSayisi > 1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end flex-wrap">
            <?php for ($i=1; $i<=$sayfaSayisi; $i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>"><a class="page-link" href="?<?= qs(['s'=>$i]) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<div class="mt-2 text-muted small">Toplam <?= $toplam ?> hareket (<?= tarih($bas) ?> – <?= tarih($bit) ?>)</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
