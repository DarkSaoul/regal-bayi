<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id WHERE u.id=?");
$stmt->execute([$id]);
$urun = $stmt->fetch();
if (!$urun) { flash('hata', 'Ürün bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = 'Ürün: ' . $urun['ad'];
$yonetici = ($_SESSION['rol'] ?? '') === 'yonetici';

$marj = ($urun['alis_fiyati'] > 0 && $urun['satis_fiyati'] > 0)
    ? round(($urun['satis_fiyati'] - $urun['alis_fiyati']) / $urun['alis_fiyati'] * 100, 1) : null;

// Son gerçek alış maliyeti (stok girişlerinden)
$sonAlis = $pdo->prepare("SELECT birim_maliyet, created_at FROM stok_hareketleri
    WHERE urun_id=? AND hareket_tipi='giris' AND birim_maliyet IS NOT NULL ORDER BY id DESC LIMIT 1");
$sonAlis->execute([$id]);
$sonAlis = $sonAlis->fetch();

// Satış istatistikleri
$sat = $pdo->prepare("SELECT COUNT(*) AS islem, COALESCE(SUM(sk.miktar),0) AS adet, COALESCE(SUM(sk.toplam),0) AS ciro,
        MAX(s.tarih) AS son_satis
    FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id WHERE sk.urun_id=? AND s.durum!='iptal'");
$sat->execute([$id]);
$satOzet = $sat->fetch();

// Listeler
$hareketler = $pdo->prepare("SELECT h.*, t.ad AS firma_adi FROM stok_hareketleri h
    LEFT JOIN tedarikciler t ON h.tedarikci_id=t.id WHERE h.urun_id=? ORDER BY h.id DESC LIMIT 50");
$hareketler->execute([$id]);
$hareketler = $hareketler->fetchAll();

$satislar = $pdo->prepare("SELECT sk.*, s.fatura_no, s.tarih, s.durum, s.id AS sid,
    COALESCE(NULLIF(m.firma_adi,''), TRIM(CONCAT_WS(' ', m.ad, m.soyad))) AS ad_soyad
    FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id LEFT JOIN musteriler m ON s.musteri_id=m.id
    WHERE sk.urun_id=? ORDER BY sk.id DESC LIMIT 50");
$satislar->execute([$id]);
$satislar = $satislar->fetchAll();

$seriler = [];
if ($urun['seri_no_takip']) {
    $seriler = $pdo->prepare("SELECT * FROM seri_numaralari WHERE urun_id=? ORDER BY id DESC LIMIT 100");
    $seriler->execute([$id]);
    $seriler = $seriler->fetchAll();
}

$fiyatlar = $pdo->prepare("SELECT f.*, ku.ad_soyad FROM fiyat_gecmisi f
    LEFT JOIN kullanicilar ku ON f.kullanici_id=ku.id WHERE f.urun_id=? ORDER BY f.id DESC LIMIT 50");
$fiyatlar->execute([$id]);
$fiyatlar = $fiyatlar->fetchAll();

$hareketEtiket = ['giris'=>'<span class="badge bg-success">Giriş</span>','cikis'=>'<span class="badge bg-danger">Çıkış</span>',
    'iade_giris'=>'<span class="badge bg-info">İade Giriş</span>','fire'=>'<span class="badge bg-warning text-dark">Fire</span>',
    'sayim_duzeltme'=>'<span class="badge bg-secondary">Sayım</span>'];
$seriEtiket = ['stokta'=>'success','satildi'=>'primary','ariza'=>'danger','iade'=>'warning','tesirde'=>'info'];
$kaynakEtiket = ['olusturma'=>'Oluşturma','duzenleme'=>'Düzenleme','toplu_fiyat'=>'Toplu Fiyat','ice_aktar'=>'İçe Aktarma'];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-box-seam text-primary"></i> <?= escH($urun['ad']) ?>
        <?= $urun['aktif'] ? '' : '<span class="badge bg-secondary">Arşiv</span>' ?></h4>
    <div class="d-flex gap-2">
        <a href="duzenle.php?id=<?= $urun['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> Düzenle</a>
        <a href="<?= BASE_URL ?>/modules/stok/giris.php?urun_id=<?= $urun['id'] ?>" class="btn btn-sm btn-success"><i class="bi bi-plus"></i> Stok Giriş</a>
        <a href="etiket.php?ids=<?= $urun['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-upc"></i> Etiket</a>
        <a href="ekle.php?kopya=<?= $urun['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-files"></i> Kopyala</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Liste</a>
    </div>
</div>

<div class="row g-3">
    <!-- Sol: bilgi kartı -->
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="text-center mb-3">
                    <?php if ($urun['resim']): ?>
                    <img src="<?= BASE_URL ?>/uploads/urunler/<?= escH($urun['resim']) ?>" alt="<?= escH($urun['ad']) ?>"
                         class="img-fluid rounded border" style="max-height:220px">
                    <?php else: ?>
                    <div class="bg-light border rounded d-flex align-items-center justify-content-center text-muted" style="height:140px">
                        <i class="bi bi-image fs-1"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted">Kod</th><td><code><?= escH($urun['kod']) ?></code></td></tr>
                    <tr><th class="text-muted">Barkod</th><td><?= $urun['barkod'] ? '<code>'.escH($urun['barkod']).'</code>' : '<span class="text-warning">Yok</span>' ?></td></tr>
                    <tr><th class="text-muted">Kategori</th><td><?= escH($urun['kategori_adi'] ?? '-') ?></td></tr>
                    <tr><th class="text-muted">Marka / Model</th><td><?= escH(trim($urun['marka'].' / '.($urun['model'] ?: '-'))) ?></td></tr>
                    <tr><th class="text-muted">Renk</th><td><?= escH($urun['renk'] ?: '-') ?></td></tr>
                    <tr><th class="text-muted">Birim</th><td><?= escH($urun['birim']) ?></td></tr>
                    <tr><th class="text-muted">KDV</th><td>%<?= (float)$urun['kdv_orani'] ?></td></tr>
                    <tr><th class="text-muted">Seri No Takibi</th><td><?= $urun['seri_no_takip'] ? 'Evet' : 'Hayır' ?></td></tr>
                    <tr><th class="text-muted">Kayıt Tarihi</th><td><?= tarihSaat($urun['created_at']) ?></td></tr>
                    <?php if ($urun['aciklama']): ?>
                    <tr><th class="text-muted">Açıklama</th><td><?= nl2br(escH($urun['aciklama'])) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Sağ: istatistik + sekmeler -->
    <div class="col-lg-8">
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="card shadow-sm h-100"><div class="card-body py-2">
                    <div class="small text-muted">Stok</div>
                    <?php $renk = $urun['stok_adedi'] <= 0 ? 'dark' : ($urun['stok_adedi'] <= $urun['min_stok'] ? 'danger' : 'success'); ?>
                    <div class="fw-bold text-<?= $renk ?>"><?= $urun['stok_adedi'] ?> <?= escH($urun['birim']) ?></div>
                    <div class="small text-muted">Min: <?= $urun['min_stok'] ?><?= $urun['tesir_adedi'] ? ' · Teşhir: '.$urun['tesir_adedi'] : '' ?></div>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm h-100"><div class="card-body py-2">
                    <div class="small text-muted">Alış → Satış</div>
                    <div class="fw-bold"><?= para($urun['alis_fiyati']) ?> → <?= para($urun['satis_fiyati']) ?></div>
                    <?php if ($marj !== null): ?>
                    <div class="small"><span class="badge bg-<?= $marj < 0 ? 'danger' : ($marj < 10 ? 'warning' : 'success') ?>">Marj %<?= $marj ?></span></div>
                    <?php endif; ?>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm h-100"><div class="card-body py-2">
                    <div class="small text-muted">Son Alış Maliyeti</div>
                    <div class="fw-bold"><?= $sonAlis ? para($sonAlis['birim_maliyet']) : '<span class="text-muted">-</span>' ?></div>
                    <?php if ($sonAlis): ?><div class="small text-muted"><?= tarih($sonAlis['created_at']) ?></div><?php endif; ?>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm h-100"><div class="card-body py-2">
                    <div class="small text-muted">Toplam Satış</div>
                    <div class="fw-bold"><?= (int)$satOzet['adet'] ?> <?= escH($urun['birim']) ?> · <?= para($satOzet['ciro']) ?></div>
                    <div class="small text-muted"><?= $satOzet['son_satis'] ? 'Son: '.tarih($satOzet['son_satis']) : 'Hiç satılmadı' ?></div>
                </div></div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white p-0">
                <ul class="nav nav-tabs card-header-tabs m-2" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabStok" type="button">Stok Hareketleri (<?= count($hareketler) ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSatis" type="button">Satışlar (<?= count($satislar) ?>)</button></li>
                    <?php if ($urun['seri_no_takip']): ?>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSeri" type="button">Seri No (<?= count($seriler) ?>)</button></li>
                    <?php endif; ?>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabFiyat" type="button">Fiyat Geçmişi (<?= count($fiyatlar) ?>)</button></li>
                </ul>
            </div>
            <div class="card-body p-0 tab-content">
                <div class="tab-pane fade show active" id="tabStok">
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Tarih</th><th>Tip</th><th class="text-end">Miktar</th><th class="text-end">Önce→Sonra</th><th class="text-end">B.Maliyet</th><th>Tedarikçi</th><th>Açıklama</th></tr></thead>
                        <tbody>
                        <?php if (!$hareketler): ?><tr><td colspan="7" class="text-center text-muted py-3">Hareket yok</td></tr><?php endif; ?>
                        <?php foreach ($hareketler as $h): ?>
                        <tr>
                            <td class="text-nowrap"><?= tarihSaat($h['created_at']) ?></td>
                            <td><?= $hareketEtiket[$h['hareket_tipi']] ?? escH($h['hareket_tipi']) ?></td>
                            <td class="text-end"><?= $h['miktar'] ?></td>
                            <td class="text-end text-muted"><?= $h['onceki_stok'] ?> → <?= $h['sonraki_stok'] ?></td>
                            <td class="text-end"><?= $h['birim_maliyet'] !== null ? para($h['birim_maliyet']) : '-' ?></td>
                            <td><?= escH($h['firma_adi'] ?? '-') ?></td>
                            <td class="small"><?= escH($h['aciklama'] ?: ($h['belge_no'] ?: '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="tabSatis">
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Tarih</th><th>Fatura</th><th>Müşteri</th><th class="text-end">Miktar</th><th class="text-end">B.Fiyat</th><th class="text-end">Toplam</th><th>Durum</th></tr></thead>
                        <tbody>
                        <?php if (!$satislar): ?><tr><td colspan="7" class="text-center text-muted py-3">Satış yok</td></tr><?php endif; ?>
                        <?php foreach ($satislar as $s): ?>
                        <tr>
                            <td class="text-nowrap"><?= tarih($s['tarih']) ?></td>
                            <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $s['sid'] ?>"><code><?= escH($s['fatura_no']) ?></code></a></td>
                            <td><?= escH($s['ad_soyad'] ?? 'Perakende') ?></td>
                            <td class="text-end"><?= $s['miktar'] ?></td>
                            <td class="text-end"><?= para($s['birim_fiyat']) ?></td>
                            <td class="text-end fw-semibold"><?= para($s['toplam']) ?></td>
                            <td><?php $dr = ['tamamlandi'=>'success','bekliyor'=>'warning','iptal'=>'danger'][$s['durum']] ?? 'secondary'; ?>
                                <span class="badge bg-<?= $dr ?>"><?= escH($s['durum']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php if ($urun['seri_no_takip']): ?>
                <div class="tab-pane fade" id="tabSeri">
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Seri No</th><th>Durum</th><th>Kayıt</th></tr></thead>
                        <tbody>
                        <?php if (!$seriler): ?><tr><td colspan="3" class="text-center text-muted py-3">Seri no kaydı yok</td></tr><?php endif; ?>
                        <?php foreach ($seriler as $sn): ?>
                        <tr>
                            <td><code><?= escH($sn['seri_no']) ?></code></td>
                            <td><span class="badge bg-<?= $seriEtiket[$sn['durum']] ?? 'secondary' ?>"><?= escH($sn['durum']) ?></span></td>
                            <td class="text-nowrap"><?= tarihSaat($sn['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
                <div class="tab-pane fade" id="tabFiyat">
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Tarih</th><th class="text-end">Alış (Eski→Yeni)</th><th class="text-end">Satış (Eski→Yeni)</th><th>Kaynak</th><th>Kullanıcı</th></tr></thead>
                        <tbody>
                        <?php if (!$fiyatlar): ?><tr><td colspan="5" class="text-center text-muted py-3">Fiyat değişikliği kaydı yok</td></tr><?php endif; ?>
                        <?php foreach ($fiyatlar as $f): ?>
                        <tr>
                            <td class="text-nowrap"><?= tarihSaat($f['created_at']) ?></td>
                            <td class="text-end <?= $f['yeni_alis'] > $f['eski_alis'] ? 'text-danger' : ($f['yeni_alis'] < $f['eski_alis'] ? 'text-success' : '') ?>">
                                <?= para($f['eski_alis']) ?> → <?= para($f['yeni_alis']) ?></td>
                            <td class="text-end <?= $f['yeni_satis'] > $f['eski_satis'] ? 'text-success' : ($f['yeni_satis'] < $f['eski_satis'] ? 'text-danger' : '') ?>">
                                <?= para($f['eski_satis']) ?> → <?= para($f['yeni_satis']) ?></td>
                            <td><?= $kaynakEtiket[$f['kaynak']] ?? escH($f['kaynak']) ?></td>
                            <td><?= escH($f['ad_soyad'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
