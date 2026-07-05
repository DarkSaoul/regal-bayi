<?php
// Veri bütünlüğü kontrolü — bilinen tutarlılık kurallarını tarar, otomatik değişiklik yapmaz
// (istisna: "Müşteri Borcu" satırındaki "Düzelt" butonu, zaten var olan güvenli musteriBorcuYenile() fonksiyonunu çağırır)
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Veri Bütünlüğü Kontrolü';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksiyon'] ?? '') === 'musteri_borcu_duzelt') {
    csrfVerify();
    $id = (int)($_POST['musteri_id'] ?? 0);
    if ($id) { musteriBorcuYenile($id); flash('basari', 'Müşteri borcu yeniden hesaplandı.'); }
    header('Location: veri_butunlugu.php'); exit;
}

// 1) Müşteri borcu tutarlılığı
$musteriSorunlari = $pdo->query(
    "SELECT m.id, TRIM(CONCAT(m.ad,' ',COALESCE(m.soyad,''))) AS ad_soyad, m.firma_adi, m.toplam_borc AS kayitli,
            COALESCE((SELECT SUM(s.kalan_tutar) FROM satislar s WHERE s.musteri_id=m.id AND s.durum='bekliyor'),0) AS hesaplanan
     FROM musteriler m
     HAVING ABS(kayitli - hesaplanan) > 0.01"
)->fetchAll();

// 2) Negatif stok
$negatifStok = $pdo->query("SELECT id, kod, ad, stok_adedi FROM urunler WHERE stok_adedi < 0")->fetchAll();

// 3) İade miktarı satılan miktardan fazla
$iadeSorunlari = $pdo->query(
    "SELECT sk.id, s.fatura_no, u.ad AS urun_adi, sk.miktar, sk.iade_miktar
     FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id JOIN urunler u ON sk.urun_id=u.id
     WHERE sk.iade_miktar > sk.miktar"
)->fetchAll();

// 4) Taksitli satışlarda taksit planı toplamı ile genel toplam uyuşmazlığı
$taksitSorunlari = $pdo->query(
    "SELECT s.id, s.fatura_no, s.genel_toplam, COALESCE(SUM(tp.tutar),0) AS taksit_toplam
     FROM satislar s JOIN taksit_plani tp ON tp.satis_id=s.id
     WHERE s.odeme_tipi='taksitli' AND s.durum != 'iptal'
     GROUP BY s.id HAVING ABS(s.genel_toplam - taksit_toplam) > 0.5"
)->fetchAll();

$toplamSorun = count($musteriSorunlari) + count($negatifStok) + count($iadeSorunlari) + count($taksitSorunlari);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-clipboard-data text-primary"></i> Veri Bütünlüğü Kontrolü</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Ayarlar</a>
</div>

<div class="alert <?= $toplamSorun === 0 ? 'alert-success' : 'alert-warning' ?>">
    <i class="bi bi-<?= $toplamSorun === 0 ? 'check-circle' : 'exclamation-triangle' ?>"></i>
    <?= $toplamSorun === 0 ? 'Bilinen tutarsızlık kurallarına göre herhangi bir sorun bulunamadı.' : "$toplamSorun potansiyel tutarsızlık bulundu." ?>
    <span class="small text-muted d-block mt-1">Bu kontrol yalnızca görüntüler; veri üzerinde otomatik değişiklik yapmaz (müşteri borcu satırındaki "Düzelt" butonu hariç — o da zaten sistemin kendi kullandığı güvenli yeniden hesaplama fonksiyonunu çalıştırır).</span>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-person-lines-fill text-primary"></i> Müşteri Borcu Tutarlılığı <span class="badge bg-<?= $musteriSorunlari ? 'danger' : 'success' ?>"><?= count($musteriSorunlari) ?></span></div>
    <?php if ($musteriSorunlari): ?>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Müşteri</th><th>Kayıtlı Borç</th><th>Hesaplanan Borç</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($musteriSorunlari as $m): ?>
            <tr>
                <td><?= escH($m['firma_adi'] ?: $m['ad_soyad']) ?></td>
                <td class="text-danger"><?= para($m['kayitli']) ?></td>
                <td class="fw-bold"><?= para($m['hesaplanan']) ?></td>
                <td>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?><input type="hidden" name="aksiyon" value="musteri_borcu_duzelt"><input type="hidden" name="musteri_id" value="<?= $m['id'] ?>">
                        <button class="btn btn-sm btn-outline-primary py-0">Düzelt</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body text-muted small">Tüm müşteri borç bakiyeleri açık satışlarla tutarlı.</div>
    <?php endif; ?>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-box-seam text-primary"></i> Negatif Stok <span class="badge bg-<?= $negatifStok ? 'danger' : 'success' ?>"><?= count($negatifStok) ?></span></div>
    <?php if ($negatifStok): ?>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Kod</th><th>Ürün</th><th>Stok</th></tr></thead>
            <tbody>
            <?php foreach ($negatifStok as $u): ?>
            <tr><td><?= escH($u['kod']) ?></td><td><?= escH($u['ad']) ?></td><td class="text-danger fw-bold"><?= $u['stok_adedi'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body text-muted small">Negatif stoklu ürün yok.</div>
    <?php endif; ?>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-return-left text-primary"></i> İade Miktarı Tutarsızlığı <span class="badge bg-<?= $iadeSorunlari ? 'danger' : 'success' ?>"><?= count($iadeSorunlari) ?></span></div>
    <?php if ($iadeSorunlari): ?>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Fatura No</th><th>Ürün</th><th>Satılan</th><th>İade</th></tr></thead>
            <tbody>
            <?php foreach ($iadeSorunlari as $i): ?>
            <tr><td><?= escH($i['fatura_no']) ?></td><td><?= escH($i['urun_adi']) ?></td><td><?= $i['miktar'] ?></td><td class="text-danger fw-bold"><?= $i['iade_miktar'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body text-muted small">İade miktarı satılan miktarı aşan kalem yok.</div>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar3 text-primary"></i> Taksit Planı Toplam Tutarlılığı <span class="badge bg-<?= $taksitSorunlari ? 'danger' : 'success' ?>"><?= count($taksitSorunlari) ?></span></div>
    <?php if ($taksitSorunlari): ?>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Fatura No</th><th>Satış Toplamı</th><th>Taksit Planı Toplamı</th></tr></thead>
            <tbody>
            <?php foreach ($taksitSorunlari as $t): ?>
            <tr><td><?= escH($t['fatura_no']) ?></td><td><?= para($t['genel_toplam']) ?></td><td class="text-danger fw-bold"><?= para($t['taksit_toplam']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body text-muted small">Tüm taksitli satışların plan toplamı satış toplamıyla uyumlu.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
