<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT k.*, u.ad AS ust_ad FROM kategoriler k LEFT JOIN kategoriler u ON k.ust_id=u.id WHERE k.id=?");
$stmt->execute([$id]);
$kategori = $stmt->fetch();
if (!$kategori) { flash('hata', 'Kategori bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = 'Kategori: ' . $kategori['ad'];
$yonetici = ($_SESSION['rol'] ?? '') === 'yonetici';

// Alt kategoriler + bu kategori ağacındaki tüm id'ler
$altlar = $pdo->prepare("SELECT * FROM kategoriler WHERE ust_id=? ORDER BY sira, ad");
$altlar->execute([$id]);
$altlar = $altlar->fetchAll();
$agacIds = array_merge([$id], array_map(fn($a) => (int)$a['id'], $altlar));
$yt = implode(',', array_fill(0, count($agacIds), '?'));

// Özet istatistik (ağaç genelinde, aktif ürünler)
$ozet = $pdo->prepare("SELECT COUNT(*) AS urun, COALESCE(SUM(stok_adedi),0) AS stok,
        COALESCE(SUM(stok_adedi*alis_fiyati),0) AS maliyet, COALESCE(SUM(stok_adedi*satis_fiyati),0) AS satis_deger,
        SUM(CASE WHEN stok_adedi <= min_stok THEN 1 ELSE 0 END) AS dusuk
    FROM urunler WHERE aktif=1 AND kategori_id IN ($yt)");
$ozet->execute($agacIds);
$ozet = $ozet->fetch();

// Ciro: son 30 gün + tüm zamanlar
$ciro = $pdo->prepare("SELECT COALESCE(SUM(sk.toplam),0) AS toplam,
        COALESCE(SUM(CASE WHEN s.tarih >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN sk.toplam ELSE 0 END),0) AS son30,
        COALESCE(SUM(sk.miktar),0) AS adet
    FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id JOIN satislar s ON sk.satis_id=s.id
    WHERE s.durum!='iptal' AND u.kategori_id IN ($yt)");
$ciro->execute($agacIds);
$ciro = $ciro->fetch();

// Alt kategori bazlı ürün sayıları
$altSayilar = [];
$as = $pdo->prepare("SELECT kategori_id, COUNT(*) AS s FROM urunler WHERE aktif=1 AND kategori_id IN ($yt) GROUP BY kategori_id");
$as->execute($agacIds);
foreach ($as->fetchAll() as $r) $altSayilar[$r['kategori_id']] = (int)$r['s'];

// Ürünler (ağaç genelinde)
$urunler = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id
    WHERE u.aktif=1 AND u.kategori_id IN ($yt) ORDER BY u.ad LIMIT 200");
$urunler->execute($agacIds);
$urunler = $urunler->fetchAll();

// Düşük stok listesi
$dusukler = array_values(array_filter($urunler, fn($u) => $u['stok_adedi'] <= $u['min_stok']));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0">
        <i class="bi bi-<?= escH($kategori['ikon'] ?: 'tags') ?>" <?= $kategori['renk'] ? 'style="color:'.escH($kategori['renk']).'"' : 'class="text-primary"' ?>></i>
        <?= escH($kategori['ad']) ?>
        <?php if ($kategori['ust_ad']): ?><small class="text-muted fs-6">(<?= escH($kategori['ust_ad']) ?> altında)</small><?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/urunler/index.php?kat=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-box-seam"></i> Ürünleri Listele</a>
        <?php if ($yonetici): ?>
        <a href="<?= BASE_URL ?>/modules/urunler/toplu_fiyat.php?kategori_id=<?= $id ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-percent"></i> Zam/İndirim</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kategoriler</a>
    </div>
</div>

<?php if ($kategori['aciklama']): ?>
<div class="alert alert-light border py-2"><?= escH($kategori['aciklama']) ?></div>
<?php endif; ?>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Ürün</div>
            <div class="fw-bold"><?= (int)$ozet['urun'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Toplam Stok</div>
            <div class="fw-bold"><?= (int)$ozet['stok'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Envanter (Alış)</div>
            <div class="fw-bold text-danger"><?= para($ozet['maliyet']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Envanter (Satış)</div>
            <div class="fw-bold text-success"><?= para($ozet['satis_deger']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">30 Gün Ciro</div>
            <div class="fw-bold text-primary"><?= para($ciro['son30']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Toplam Ciro</div>
            <div class="fw-bold"><?= para($ciro['toplam']) ?> <small class="text-muted">(<?= (int)$ciro['adet'] ?> adet)</small></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-box-seam text-primary"></i> Ürünler (<?= count($urunler) ?><?= count($urunler) >= 200 ? '+, ilk 200' : '' ?>)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Kod</th><th>Ürün</th><?php if ($altlar): ?><th>Kategori</th><?php endif; ?>
                        <th class="text-end">Alış</th><th class="text-end">Satış</th><th class="text-center">Stok</th></tr></thead>
                    <tbody>
                    <?php if (!$urunler): ?><tr><td colspan="6" class="text-center text-muted py-3">Bu kategoride ürün yok</td></tr><?php endif; ?>
                    <?php foreach ($urunler as $u): ?>
                    <tr>
                        <td><code><?= escH($u['kod']) ?></code></td>
                        <td><a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $u['id'] ?>" class="text-decoration-none"><?= escH($u['ad']) ?></a></td>
                        <?php if ($altlar): ?><td class="small text-muted"><?= escH($u['kategori_adi'] ?? '-') ?></td><?php endif; ?>
                        <td class="text-end"><?= para($u['alis_fiyati']) ?></td>
                        <td class="text-end fw-semibold"><?= para($u['satis_fiyati']) ?></td>
                        <td class="text-center">
                            <?php $renk = $u['stok_adedi'] <= 0 ? 'dark' : ($u['stok_adedi'] <= $u['min_stok'] ? 'danger' : 'success'); ?>
                            <span class="badge bg-<?= $renk ?>"><?= $u['stok_adedi'] ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($kategori['varsayilan_kdv'] !== null || $kategori['hedef_marj'] !== null): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-sliders text-primary"></i> Varsayılanlar</div>
            <div class="card-body py-2 d-flex gap-2">
                <?php if ($kategori['varsayilan_kdv'] !== null): ?><span class="badge bg-info text-dark">KDV %<?= (float)$kategori['varsayilan_kdv'] ?></span><?php endif; ?>
                <?php if ($kategori['hedef_marj'] !== null): ?><span class="badge bg-primary">Hedef Marj %<?= (float)$kategori['hedef_marj'] ?></span><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($altlar): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-diagram-3 text-primary"></i> Alt Kategoriler (<?= count($altlar) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($altlar as $alt): ?>
                <li class="list-group-item py-2 d-flex justify-content-between align-items-center">
                    <a href="detay.php?id=<?= $alt['id'] ?>" class="text-decoration-none">
                        <i class="bi bi-<?= escH($alt['ikon'] ?: 'folder') ?> me-1" <?= $alt['renk'] ? 'style="color:'.escH($alt['renk']).'"' : '' ?>></i>
                        <?= escH($alt['ad']) ?>
                    </a>
                    <span class="badge bg-secondary"><?= $altSayilar[$alt['id']] ?? 0 ?> ürün</span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm <?= $dusukler ? 'border-danger' : '' ?>">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-exclamation-triangle text-danger"></i> Düşük Stok (<?= count($dusukler) ?>)
            </div>
            <?php if ($dusukler): ?>
            <ul class="list-group list-group-flush" style="max-height:300px;overflow-y:auto">
                <?php foreach ($dusukler as $du): ?>
                <li class="list-group-item py-1 small d-flex justify-content-between align-items-center">
                    <a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $du['id'] ?>" class="text-decoration-none text-truncate"><?= escH($du['ad']) ?></a>
                    <span class="badge bg-danger flex-shrink-0"><?= $du['stok_adedi'] ?>/<?= $du['min_stok'] ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="card-body py-2 small text-success"><i class="bi bi-check-circle"></i> Tüm ürünler yeterli stokta.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
