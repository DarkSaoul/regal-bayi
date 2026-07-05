<?php
// Günlük özet — "bugün sistemde ne değişti" tek ekranda, salt okunur
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Günlük Özet';
$pdo = db();

$gun = $_GET['tarih'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) $gun = date('Y-m-d');

$satisOzet = $pdo->prepare("SELECT COUNT(*) AS adet, COALESCE(SUM(genel_toplam),0) AS ciro FROM satislar WHERE DATE(tarih)=? AND durum != 'iptal'");
$satisOzet->execute([$gun]); $satisOzet = $satisOzet->fetch();

$yeniMusteri = $pdo->prepare("SELECT COUNT(*) FROM musteriler WHERE DATE(created_at)=?");
$yeniMusteri->execute([$gun]); $yeniMusteri = (int)$yeniMusteri->fetchColumn();

$stokHareket = $pdo->prepare("SELECT hareket_tipi, COUNT(*) AS adet, SUM(miktar) AS toplam_miktar FROM stok_hareketleri WHERE DATE(created_at)=? GROUP BY hareket_tipi");
$stokHareket->execute([$gun]); $stokHareket = $stokHareket->fetchAll();

$girisYapanlar = $pdo->prepare(
    "SELECT k.ad_soyad, k.rol, MIN(a.created_at) AS ilk_giris FROM aktivite_loglari a
     JOIN kullanicilar k ON a.kullanici_id=k.id
     WHERE a.aksiyon='giris' AND DATE(a.created_at)=? GROUP BY k.id ORDER BY ilk_giris"
);
$girisYapanlar->execute([$gun]); $girisYapanlar = $girisYapanlar->fetchAll();

$ayarDegisiklikleri = $pdo->prepare(
    "SELECT g.anahtar, g.eski_deger, g.yeni_deger, g.not_metni, k.ad_soyad FROM ayar_gecmisi g
     LEFT JOIN kullanicilar k ON g.kullanici_id=k.id WHERE DATE(g.created_at)=? ORDER BY g.id"
);
$ayarDegisiklikleri->execute([$gun]); $ayarDegisiklikleri = $ayarDegisiklikleri->fetchAll();

$aktiviteOzet = $pdo->prepare(
    "SELECT aksiyon, COUNT(*) AS adet FROM aktivite_loglari WHERE DATE(created_at)=? GROUP BY aksiyon ORDER BY adet DESC"
);
$aktiviteOzet->execute([$gun]); $aktiviteOzet = $aktiviteOzet->fetchAll();

$tahsilat = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) FROM kasa_hareketleri WHERE DATE(tarih)=? AND kategori='Tahsilat' AND tip='giris'");
$tahsilat->execute([$gun]); $tahsilat = (float)$tahsilat->fetchColumn();

$gider = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) FROM kasa_hareketleri WHERE DATE(tarih)=? AND tip='cikis'");
$gider->execute([$gun]); $gider = (float)$gider->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4><i class="bi bi-calendar-check text-primary"></i> Günlük Özet</h4>
    <div class="d-flex gap-2 align-items-center">
        <form method="get" class="d-flex gap-2">
            <input type="date" name="tarih" class="form-control form-control-sm" value="<?= escH($gun) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
        </form>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">← Ayarlar</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Satış</div>
            <div class="fw-bold fs-5"><?= (int)$satisOzet['adet'] ?> adet</div>
            <div class="small text-success"><?= para($satisOzet['ciro']) ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Yeni Müşteri</div>
            <div class="fw-bold fs-5"><?= $yeniMusteri ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Tahsilat</div>
            <div class="fw-bold fs-5 text-success"><?= para($tahsilat) ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Gider</div>
            <div class="fw-bold fs-5 text-danger"><?= para($gider) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-box-seam text-primary"></i> Stok Hareketleri</div>
            <div class="card-body">
                <?php if ($stokHareket): ?>
                <?php $hareketAd = ['giris'=>'Giriş','cikis'=>'Çıkış','iade_giris'=>'İade Girişi','fire'=>'Fire','sayim_duzeltme'=>'Sayım Düzeltme']; ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Hareket</th><th>İşlem Sayısı</th><th>Toplam Miktar</th></tr></thead>
                    <tbody>
                    <?php foreach ($stokHareket as $h): ?>
                    <tr><td><?= $hareketAd[$h['hareket_tipi']] ?? escH($h['hareket_tipi']) ?></td><td><?= $h['adet'] ?></td><td><?= number_format($h['toplam_miktar']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-muted small">Bu tarihte stok hareketi yok.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-check text-primary"></i> Giriş Yapan Kullanıcılar</div>
            <div class="card-body">
                <?php if ($girisYapanlar): ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Ad Soyad</th><th>Rol</th><th>İlk Giriş</th></tr></thead>
                    <tbody>
                    <?php foreach ($girisYapanlar as $g): ?>
                    <tr><td><?= escH($g['ad_soyad']) ?></td><td><span class="badge bg-secondary"><?= escH($g['rol']) ?></span></td><td class="small"><?= tarihSaat($g['ilk_giris']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-muted small">Bu tarihte giriş kaydı yok.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-gear text-primary"></i> Ayar Değişiklikleri</div>
            <div class="card-body p-0">
                <?php if ($ayarDegisiklikleri): ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Ayar</th><th>Eski→Yeni</th><th>Kim</th></tr></thead>
                    <tbody>
                    <?php foreach ($ayarDegisiklikleri as $a): ?>
                    <tr>
                        <td><code><?= escH($a['anahtar']) ?></code></td>
                        <td class="small"><?= escH(mb_substr((string)$a['eski_deger'],0,20)) ?> → <strong><?= escH(mb_substr((string)$a['yeni_deger'],0,20)) ?></strong></td>
                        <td class="small"><?= escH($a['ad_soyad'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="card-body text-muted small">Bu tarihte ayar değişikliği yok.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-primary"></i> Aktivite Özeti</div>
            <div class="card-body p-0">
                <?php if ($aktiviteOzet): ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>İşlem</th><th>Adet</th></tr></thead>
                    <tbody>
                    <?php foreach ($aktiviteOzet as $a): ?>
                    <tr><td><?= escH($a['aksiyon']) ?></td><td><?= $a['adet'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="card-body text-muted small">Bu tarihte aktivite kaydı yok.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
