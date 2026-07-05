<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Müşteriler';
$pdo = db();
$yonetici = ($_SESSION['rol'] ?? '') === 'yonetici';

$arama = trim($_GET['ara'] ?? '');
$tip   = in_array($_GET['tip'] ?? '', ['bireysel','kurumsal'], true) ? $_GET['tip'] : '';
$sehir = trim($_GET['sehir'] ?? '');
$ozel  = in_array($_GET['ozel'] ?? '', ['borclu','pasif','vip'], true) ? $_GET['ozel'] : '';
$durum = $_GET['durum'] ?? '1';
if (!in_array($durum, ['1','0','tumu'], true)) $durum = '1';
$limit = (int)($_GET['adet'] ?? 25);
if (!in_array($limit, [25,50,100], true)) $limit = 25;
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$offset = ($sayfa - 1) * $limit;
$pasifGun = 180;

$siralamalar = [
    'id'       => 'm.id',
    'ad'       => "COALESCE(NULLIF(m.firma_adi,''), m.ad)",
    'borc'     => 'm.toplam_borc',
    'sonsatis' => 'son_satis',
];
$srl = isset($siralamalar[$_GET['srl'] ?? '']) ? $_GET['srl'] : 'id';
$yon = ($_GET['yon'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$where = "WHERE 1=1"; $params = [];
if ($durum !== 'tumu') { $where .= " AND m.aktif=" . (int)$durum; }
if ($arama) {
    $where .= " AND (m.ad LIKE ? OR m.soyad LIKE ? OR m.firma_adi LIKE ? OR m.telefon LIKE ? OR m.telefon2 LIKE ? OR m.tc_no LIKE ?)";
    $params = array_merge($params, array_fill(0, 6, likeParam($arama)));
}
if ($tip)   { $where .= " AND m.tip=?"; $params[] = $tip; }
if ($sehir) { $where .= " AND m.sehir=?"; $params[] = $sehir; }
if ($ozel === 'borclu') $where .= " AND m.toplam_borc > 0";
if ($ozel === 'pasif')  $where .= " AND EXISTS (SELECT 1 FROM satislar sx WHERE sx.musteri_id=m.id AND sx.durum!='iptal')
    AND NOT EXISTS (SELECT 1 FROM satislar sy WHERE sy.musteri_id=m.id AND sy.durum!='iptal' AND sy.tarih >= DATE_SUB(CURDATE(), INTERVAL $pasifGun DAY))";

// VIP: ciro bazlı ilk 10 müşteri
$vipler = $pdo->query("SELECT musteri_id FROM satislar WHERE durum!='iptal' AND musteri_id IS NOT NULL
    GROUP BY musteri_id ORDER BY SUM(genel_toplam) DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
$vipler = array_map('intval', $vipler);
if ($ozel === 'vip') { $where .= $vipler ? " AND m.id IN (" . implode(',', $vipler) . ")" : " AND 0"; }

$select = "SELECT m.*, (SELECT MAX(sx.tarih) FROM satislar sx WHERE sx.musteri_id=m.id AND sx.durum!='iptal') AS son_satis
    FROM musteriler m $where";

// CSV export (filtreli)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("$select ORDER BY m.id DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="musteriler_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tip','Ad Soyad','Firma','Telefon','Telefon 2','E-posta','Şehir','TC No','Vergi No','Borç','Son Satış','Kayıt Tarihi','Durum'], ';');
    foreach ($stmt->fetchAll() as $m) {
        fputcsv($out, [$m['tip'] === 'kurumsal' ? 'Kurumsal' : 'Bireysel',
            csvHucre(trim($m['ad'] . ' ' . ($m['soyad'] ?? ''))), csvHucre($m['firma_adi']),
            csvHucre($m['telefon']), csvHucre($m['telefon2']), csvHucre($m['email']), csvHucre($m['sehir']),
            csvHucre($m['tc_no']), csvHucre($m['vergi_no']),
            number_format($m['toplam_borc'], 2, ',', '.'), $m['son_satis'] ?? '',
            substr($m['created_at'], 0, 10), $m['aktif'] ? 'Aktif' : 'Arşiv'], ';');
    }
    fclose($out); exit;
}

// Özet kartları
$ozet = $pdo->query("SELECT COUNT(*) AS toplam,
    SUM(CASE WHEN toplam_borc > 0 THEN 1 ELSE 0 END) AS borclu,
    COALESCE(SUM(toplam_borc),0) AS alacak,
    SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') THEN 1 ELSE 0 END) AS bu_ay
    FROM musteriler WHERE aktif=1")->fetch();
$pasifSayi = (int)$pdo->query("SELECT COUNT(*) FROM musteriler m WHERE m.aktif=1
    AND EXISTS (SELECT 1 FROM satislar sx WHERE sx.musteri_id=m.id AND sx.durum!='iptal')
    AND NOT EXISTS (SELECT 1 FROM satislar sy WHERE sy.musteri_id=m.id AND sy.durum!='iptal' AND sy.tarih >= DATE_SUB(CURDATE(), INTERVAL $pasifGun DAY))")->fetchColumn();

// Şehir dağılımı (grafik)
$sehirDagilim = $pdo->query("SELECT COALESCE(NULLIF(TRIM(sehir),''),'Belirtilmemiş') AS sehir, COUNT(*) AS adet
    FROM musteriler WHERE aktif=1 GROUP BY sehir ORDER BY adet DESC LIMIT 8")->fetchAll();

$toplamStmt = $pdo->prepare("SELECT COUNT(*) FROM musteriler m $where");
$toplamStmt->execute($params);
$toplam = (int)$toplamStmt->fetchColumn();
$sayfaSayisi = max(1, ceil($toplam / $limit));

$orderBy = $siralamalar[$srl] . ' ' . strtoupper($yon);
$stmt = $pdo->prepare("$select ORDER BY $orderBy, m.id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$musteriler = $stmt->fetchAll();

$sehirler = $pdo->query("SELECT DISTINCT sehir FROM musteriler WHERE sehir IS NOT NULL AND sehir!='' ORDER BY sehir")->fetchAll(PDO::FETCH_COLUMN);
$tumMusteriler = $yonetici ? $pdo->query("SELECT id, ad, soyad, firma_adi, telefon FROM musteriler ORDER BY ad")->fetchAll() : [];

function qs(array $d = []): string {
    $q = array_merge(['ara'=>$_GET['ara'] ?? '', 'tip'=>$_GET['tip'] ?? '', 'sehir'=>$_GET['sehir'] ?? '',
        'ozel'=>$_GET['ozel'] ?? '', 'durum'=>$_GET['durum'] ?? '1', 'adet'=>$_GET['adet'] ?? '',
        'srl'=>$_GET['srl'] ?? '', 'yon'=>$_GET['yon'] ?? '', 's'=>$_GET['s'] ?? ''], $d);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
function srlBaslik(string $anahtar, string $etiket): string {
    global $srl, $yon;
    $yeniYon = ($srl === $anahtar && $yon === 'asc') ? 'desc' : 'asc';
    $ok = $srl === $anahtar ? ($yon === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '';
    return '<a class="text-decoration-none text-dark" href="?' . qs(['srl'=>$anahtar, 'yon'=>$yeniYon, 's'=>1]) . '">' . $etiket . $ok . '</a>';
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-people text-primary"></i> Müşteriler</h4>
    <div class="d-flex flex-wrap gap-2">
        <a href="?<?= qs(['export'=>'csv','s'=>'']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
        <?php if ($yonetici): ?>
        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#birlestirModal"><i class="bi bi-sign-merge-left"></i> Birleştir</button>
        <?php endif; ?>
        <a href="ekle.php" class="btn btn-sm btn-primary"><i class="bi bi-person-plus"></i> Yeni Müşteri</a>
    </div>
</div>

<!-- Özet kartları + şehir grafiği -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Aktif Müşteri</div>
            <div class="fw-bold"><?= (int)$ozet['toplam'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <a href="?<?= qs(['ozel'=>'borclu','s'=>1]) ?>" class="text-decoration-none">
        <div class="card shadow-sm h-100 <?= $ozet['borclu'] ? 'border-danger' : '' ?>"><div class="card-body py-2">
            <div class="small text-muted">Borçlu</div>
            <div class="fw-bold text-danger"><?= (int)$ozet['borclu'] ?> kişi</div>
        </div></div></a>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Toplam Alacak</div>
            <div class="fw-bold text-danger"><?= para($ozet['alacak']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Bu Ay Yeni</div>
            <div class="fw-bold text-success">+<?= (int)$ozet['bu_ay'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <a href="?<?= qs(['ozel'=>'pasif','s'=>1]) ?>" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Pasif (<?= $pasifGun ?>g+)</div>
            <div class="fw-bold text-warning"><?= $pasifSayi ?> kişi</div>
        </div></div></a>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100">
            <div class="card-body py-1 px-2 d-flex align-items-center gap-1">
                <canvas id="sehirGrafik" style="max-height:64px;max-width:64px"></canvas>
                <div class="small text-muted lh-sm">Şehir<br>Dağılımı</div>
            </div>
        </div>
    </div>
</div>

<!-- Filtreler -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ad, firma, telefon veya TC..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="tip" class="form-select form-select-sm">
                    <option value="">Tip: Tümü</option>
                    <option value="bireysel" <?= $tip==='bireysel'?'selected':'' ?>>Bireysel</option>
                    <option value="kurumsal" <?= $tip==='kurumsal'?'selected':'' ?>>Kurumsal</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="sehir" class="form-select form-select-sm">
                    <option value="">Şehir: Tümü</option>
                    <?php foreach ($sehirler as $s): ?>
                    <option value="<?= escH($s) ?>" <?= $sehir===$s?'selected':'' ?>><?= escH($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="ozel" class="form-select form-select-sm">
                    <option value="">Özel: Yok</option>
                    <option value="borclu" <?= $ozel==='borclu'?'selected':'' ?>>Borçlular</option>
                    <option value="pasif" <?= $ozel==='pasif'?'selected':'' ?>><?= $pasifGun ?>+ gün gelmeyen</option>
                    <option value="vip" <?= $ozel==='vip'?'selected':'' ?>>VIP (ciro ilk 10)</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="durum" class="form-select form-select-sm">
                    <option value="1" <?= $durum==='1'?'selected':'' ?>>Aktif</option>
                    <option value="0" <?= $durum==='0'?'selected':'' ?>>Arşiv</option>
                    <option value="tumu" <?= $durum==='tumu'?'selected':'' ?>>Tümü</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="adet" class="form-select form-select-sm">
                    <?php foreach ([25,50,100] as $a): ?>
                    <option value="<?= $a ?>" <?= $limit===$a?'selected':'' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th>Tip</th>
                <th><?= srlBaslik('ad','Ad Soyad / Firma') ?></th>
                <th>Telefon</th><th>Şehir</th>
                <th><?= srlBaslik('sonsatis','Son Satış') ?></th>
                <th class="text-end"><?= srlBaslik('borc','Borç') ?></th>
                <th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($musteriler)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Müşteri bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($musteriler as $m): ?>
            <?php
                $kurumsal = $m['tip'] === 'kurumsal';
                $kisiAdi = trim($m['ad'] . ' ' . ($m['soyad'] ?? ''));
                $wa = $m['telefon'] ? whatsappLink($m['telefon']) : null;
            ?>
            <tr class="<?= $m['aktif'] ? '' : 'table-secondary' ?>">
                <td>
                    <span class="badge bg-<?= $kurumsal ? 'info' : 'secondary' ?>"><?= $kurumsal ? 'Kurumsal' : 'Bireysel' ?></span>
                    <?= $m['aktif'] ? '' : '<br><span class="badge bg-secondary mt-1">Arşiv</span>' ?>
                </td>
                <td>
                    <?php if ($kurumsal && $m['firma_adi']): ?>
                    <a href="detay.php?id=<?= $m['id'] ?>" class="text-decoration-none"><strong><?= escH($m['firma_adi']) ?></strong></a>
                    <?= in_array((int)$m['id'], $vipler, true) ? '<i class="bi bi-star-fill text-warning" title="VIP — ciro ilk 10"></i>' : '' ?>
                    <br><small class="text-muted"><?= escH($kisiAdi) ?></small>
                    <?php else: ?>
                    <a href="detay.php?id=<?= $m['id'] ?>" class="text-decoration-none"><strong><?= escH($kisiAdi) ?></strong></a>
                    <?= in_array((int)$m['id'], $vipler, true) ? '<i class="bi bi-star-fill text-warning" title="VIP — ciro ilk 10"></i>' : '' ?>
                    <?php if ($m['firma_adi']): ?><br><small class="text-muted"><?= escH($m['firma_adi']) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap">
                    <?php if ($m['telefon'] && ayar('veri_maskeleme_aktif','0') === '1'): ?>
                    <?= escH(veriMaskele($m['telefon'])) ?>
                    <?php elseif ($m['telefon']): ?>
                    <a href="tel:<?= escH(telefonNormalize($m['telefon'])) ?>" class="text-decoration-none"><?= escH($m['telefon']) ?></a>
                    <?php if ($wa): ?><a href="<?= escH($wa) ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-1 ms-1" title="WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
                    <?php else: ?>-<?php endif; ?>
                </td>
                <td><?= escH($m['sehir'] ?: '-') ?></td>
                <td class="text-nowrap"><?= $m['son_satis'] ? tarih($m['son_satis']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end <?= $m['toplam_borc']>0?'text-danger fw-bold':'' ?>">
                    <?= $m['toplam_borc']>0 ? para($m['toplam_borc']) : '-' ?>
                </td>
                <td class="text-nowrap">
                    <a href="detay.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detay"><i class="bi bi-eye"></i></a>
                    <a href="duzenle.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Düzenle"><i class="bi bi-pencil"></i></a>
                    <?php if ($yonetici): ?>
                    <form method="post" action="islem.php" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <?php if ($m['aktif']): ?>
                        <input type="hidden" name="islem" value="arsivle">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Arşivle"
                                onclick="return confirm('<?= escH(addslashes($kurumsal && $m['firma_adi'] ? $m['firma_adi'] : $kisiAdi)) ?> arşive taşınacak. Emin misiniz?')">
                            <i class="bi bi-archive"></i></button>
                        <?php else: ?>
                        <input type="hidden" name="islem" value="aktif">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Arşivden Çıkar"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <?php endif; ?>
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
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end flex-wrap">
            <?php for ($i=1; $i<=$sayfaSayisi; $i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>"><a class="page-link" href="?<?= qs(['s'=>$i]) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<div class="mt-2 text-muted small">Toplam <?= $toplam ?> müşteri listeleniyor</div>

<?php if ($yonetici): ?>
<!-- Birleştir Modal -->
<div class="modal fade" id="birlestirModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="islem.php"
                  onsubmit="return confirm('Kaynak müşteri silinip tüm satış/ödeme/not kayıtları hedefe taşınacak. Bu işlem geri alınamaz. Emin misiniz?')">
                <?= csrfField() ?>
                <input type="hidden" name="islem" value="birlestir">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-sign-merge-left text-warning"></i> Müşteri Birleştir (çift kayıt)</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Kaynak <small class="text-muted">(silinecek çift kayıt)</small></label>
                        <select name="kaynak_id" class="form-select" required>
                            <option value="">Seçin...</option>
                            <?php foreach ($tumMusteriler as $tm): ?>
                            <option value="<?= $tm['id'] ?>">#<?= $tm['id'] ?> — <?= escH(trim(($tm['firma_adi'] ?: '') . ' ' . $tm['ad'] . ' ' . ($tm['soyad'] ?? ''))) ?><?= $tm['telefon'] ? ' (' . escH($tm['telefon']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Hedef <small class="text-muted">(kayıtlar buraya taşınacak)</small></label>
                        <select name="hedef_id" class="form-select" required>
                            <option value="">Seçin...</option>
                            <?php foreach ($tumMusteriler as $tm): ?>
                            <option value="<?= $tm['id'] ?>">#<?= $tm['id'] ?> — <?= escH(trim(($tm['firma_adi'] ?: '') . ' ' . $tm['ad'] . ' ' . ($tm['soyad'] ?? ''))) ?><?= $tm['telefon'] ? ' (' . escH($tm['telefon']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-warning py-2 small mb-0">Satışlar, ödemeler ve notlar hedefe taşınır; borç yeniden hesaplanır; kaynak silinir.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-sign-merge-left"></i> Birleştir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('sehirGrafik');
    if (canvas && typeof Chart !== 'undefined') {
        const veri = <?= json_encode($sehirDagilim, JSON_UNESCAPED_UNICODE) ?>;
        new Chart(canvas, {
            type: 'doughnut',
            data: { labels: veri.map(v => v.sehir),
                datasets: [{ data: veri.map(v => parseInt(v.adet)),
                    backgroundColor: ['#0d6efd','#dc3545','#198754','#ffc107','#6f42c1','#fd7e14','#20c997','#6c757d'], borderWidth: 1 }] },
            options: { plugins: { legend: { display: false } } }
        });
    }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
