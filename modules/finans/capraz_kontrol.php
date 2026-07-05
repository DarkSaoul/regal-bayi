<?php
// Tedarikçi ödemeleri ile kasa hareketleri arasında çapraz kontrol
// Not: kasa_hareketleri'nde hangi tedarikçiye ait olduğu tutulmuyor (yalnızca
// kategori='Tedarikçi Ödemesi'), bu yüzden karşılaştırma dönem toplamı bazındadır.
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Tedarikçi Ödemesi Çapraz Kontrolü';
$pdo = db();

$bas = gecerliTarih($_GET['bas'] ?? '', date('Y-m-01'));
$bit = gecerliTarih($_GET['bit'] ?? '', date('Y-m-d'));

$tedarikciOdemeleri = $pdo->prepare("SELECT to2.*, t.ad AS tedarikci_adi FROM tedarikci_odemeleri to2
    JOIN tedarikciler t ON to2.tedarikci_id=t.id WHERE to2.tarih BETWEEN ? AND ? ORDER BY to2.tarih");
$tedarikciOdemeleri->execute([$bas, $bit]); $tedarikciOdemeleri = $tedarikciOdemeleri->fetchAll();
$tedarikciNakitToplam = array_sum(array_map(fn($r) => $r['odeme_tipi']==='nakit' ? (float)$r['tutar'] : 0, $tedarikciOdemeleri));
$tedarikciToplamTum = array_sum(array_column($tedarikciOdemeleri, 'tutar'));

$kasaTedarikciHareketleri = $pdo->prepare("SELECT k.*, ku.ad_soyad AS kullanici FROM kasa_hareketleri k
    LEFT JOIN kullanicilar ku ON k.kullanici_id=ku.id
    WHERE k.kategori='Tedarikçi Ödemesi' AND k.tip='cikis' AND k.onay_durumu='onaylandi' AND k.tarih BETWEEN ? AND ? ORDER BY k.tarih");
$kasaTedarikciHareketleri->execute([$bas, $bit]); $kasaTedarikciHareketleri = $kasaTedarikciHareketleri->fetchAll();
$kasaToplam = array_sum(array_column($kasaTedarikciHareketleri, 'tutar'));

$fark = round($tedarikciNakitToplam - $kasaToplam, 2);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-arrow-left-right text-primary"></i> Tedarikçi Ödemesi Çapraz Kontrolü</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Kasa & Finans</a>
</div>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle"></i> Kasa hareketlerinde ödemenin hangi tedarikçiye ait olduğu ayrıca tutulmadığı için karşılaştırma
    <strong>dönem toplamı</strong> bazındadır: <em>Tedarikçilere Ödenen Nakit</em> ("Tedarikçiler" modülünden girilen gerçek ödemeler)
    ile <em>Kasadan "Tedarikçi Ödemesi" Kategorili Çıkışlar</em> (Kasa Hareketi ekranından elle girilenler) arasında fark varsa,
    bir ödeme kasaya işlenmemiş veya kasaya fazladan/mükerrer işlenmiş olabilir.
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label small mb-1">Başlangıç</label>
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= $bas ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Bitiş</label>
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= $bit ?>">
            </div>
            <div class="col-md-3"><button class="btn btn-sm btn-primary">Göster</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2">
                <div class="small">Tedarikçilere Ödenen (Nakit)</div>
                <div class="fw-bold fs-5"><?= para($tedarikciNakitToplam) ?></div>
                <div class="small opacity-75">Tüm yöntemler toplamı: <?= para($tedarikciToplamTum) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-info text-white">
            <div class="card-body py-2">
                <div class="small">Kasadan "Tedarikçi Ödemesi" Çıkışı</div>
                <div class="fw-bold fs-5"><?= para($kasaToplam) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 <?= abs($fark) < 0.01 ? 'bg-success' : 'bg-danger' ?> text-white">
            <div class="card-body py-2">
                <div class="small">Fark</div>
                <div class="fw-bold fs-5"><?= para($fark) ?></div>
                <?php if (abs($fark) < 0.01): ?><div class="small">✓ Tutarlı</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">Tedarikçi Ödemeleri (Nakit)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Tarih</th><th>Tedarikçi</th><th class="text-end">Tutar</th></tr></thead>
                    <tbody>
                    <?php $nakitler = array_filter($tedarikciOdemeleri, fn($r) => $r['odeme_tipi']==='nakit'); ?>
                    <?php if (empty($nakitler)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Kayıt yok</td></tr>
                    <?php endif; ?>
                    <?php foreach ($nakitler as $r): ?>
                    <tr><td><?= tarih($r['tarih']) ?></td><td><?= escH($r['tedarikci_adi']) ?></td><td class="text-end fw-bold"><?= para($r['tutar']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">Kasa "Tedarikçi Ödemesi" Hareketleri</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Tarih</th><th>Açıklama</th><th class="text-end">Tutar</th></tr></thead>
                    <tbody>
                    <?php if (empty($kasaTedarikciHareketleri)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Kayıt yok</td></tr>
                    <?php endif; ?>
                    <?php foreach ($kasaTedarikciHareketleri as $r): ?>
                    <tr><td><?= tarih($r['tarih']) ?></td><td class="small"><?= escH($r['aciklama'] ?: '-') ?></td><td class="text-end fw-bold"><?= para($r['tutar']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
