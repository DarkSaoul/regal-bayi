<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$satis = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.telefon, m.adres, m.tc_no FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id WHERE s.id=?");
$satis->execute([$id]); $satis = $satis->fetch();
if (!$satis) { flash('hata','Satış bulunamadı.'); header('Location: index.php'); exit; }

$kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.satis_id=?");
$kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();

$odemeler = $pdo->prepare("SELECT * FROM odemeler WHERE satis_id=? ORDER BY tarih");
$odemeler->execute([$id]); $odemeler = $odemeler->fetchAll();

// Taksit hesaplamaları
$taksitli = $satis['odeme_tipi'] === 'taksitli' && $satis['taksit_sayisi'] > 1;
$aylik_taksit  = $taksitli ? ($satis['genel_toplam'] / $satis['taksit_sayisi']) : 0;
if ($taksitli) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM odemeler WHERE satis_id=? AND taksit_no IS NOT NULL");
    $stmt->execute([$id]);
    $odenen_taksit = (int)$stmt->fetchColumn();
}
$kalan_taksit  = $taksitli ? ($satis['taksit_sayisi'] - $odenen_taksit) : 0;
$taksit_ilerleme = $taksitli && $satis['taksit_sayisi'] > 0
    ? round(($odenen_taksit / $satis['taksit_sayisi']) * 100)
    : 0;

$sayfa_basligi = 'Fatura: ' . $satis['fatura_no'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between no-print">
    <h4><i class="bi bi-receipt text-primary"></i> <?= escH($satis['fatura_no']) ?></h4>
    <div class="d-flex gap-2">
        <?php if ($satis['kalan_tutar']>0): ?>
        <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $id ?>" class="btn btn-success">
            <i class="bi bi-cash-coin"></i> Tahsilat Al
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Yazdır</button>
        <a href="index.php" class="btn btn-outline-secondary">← Satışlar</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <!-- Fatura -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <div><h5 class="mb-0">SATIŞ FATURASI</h5><small><?= escH($satis['fatura_no']) ?></small></div>
                <div class="text-end">
                    <div><?= tarih($satis['tarih']) ?></div>
                    <span class="badge bg-<?= $satis['durum']==='tamamlandi'?'success':($satis['durum']==='iptal'?'danger':'warning') ?>">
                        <?= $satis['durum']==='tamamlandi'?'Tamamlandı':($satis['durum']==='iptal'?'İptal':'Bekliyor') ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-6">
                        <strong>SATICI</strong><br>
                        <?= escH(ayar('firma_adi','Regal Bayi')) ?><br>
                        <?php if (ayar('firma_telefon')): ?><small><?= escH(ayar('firma_telefon')) ?></small><br><?php endif; ?>
                        <?php if (ayar('firma_email')): ?><small class="text-muted"><?= escH(ayar('firma_email')) ?></small><br><?php endif; ?>
                        <?php if (ayar('firma_adres')): ?><small class="text-muted"><?= escH(ayar('firma_adres')) ?></small><?php endif; ?>
                    </div>
                    <div class="col-6 text-end">
                        <strong>MÜŞTERİ</strong><br>
                        <?= escH($satis['musteri_adi'] ?: 'Perakende') ?><br>
                        <small class="text-muted"><?= escH($satis['telefon']??'') ?></small>
                    </div>
                </div>
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr><th>#</th><th>Ürün</th><th>Miktar</th><th>Birim Fiyat</th><th>KDV</th><th>İndirim</th><th>Toplam</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kalemler as $i => $k): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><strong><?= escH($k['urun_adi']) ?></strong><br><small><?= escH($k['kod']) ?></small></td>
                        <td><?= $k['miktar'] ?></td>
                        <td><?= para($k['birim_fiyat']) ?></td>
                        <td>%<?= $k['kdv_orani'] ?><br><small><?= para($k['kdv_tutar']) ?></small></td>
                        <td><?= $k['indirim']>0 ? para($k['indirim']) : '-' ?></td>
                        <td class="fw-bold"><?= para($k['toplam']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="6" class="text-end">Ara Toplam</td><td><?= para($satis['ara_toplam']) ?></td></tr>
                        <tr><td colspan="6" class="text-end">KDV Toplam</td><td><?= para($satis['kdv_toplam']) ?></td></tr>
                        <?php if ($satis['indirim_toplam']>0): ?>
                        <tr><td colspan="6" class="text-end">İndirim</td><td class="text-danger">- <?= para($satis['indirim_toplam']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="fw-bold fs-5"><td colspan="6" class="text-end">GENEL TOPLAM</td><td><?= para($satis['genel_toplam']) ?></td></tr>
                    </tfoot>
                </table>
                <div class="row mt-3 g-2">
                    <div class="col-md-6">
                        <div class="p-2 bg-light rounded">
                            <div class="small text-muted mb-1">Ödeme Tipi</div>
                            <strong><?= ucfirst(str_replace('_',' ',$satis['odeme_tipi'])) ?></strong>
                            <?php if ($taksitli): ?>
                            <span class="badge bg-primary ms-1"><?= $satis['taksit_sayisi'] ?> Taksit</span>
                            <div class="small text-muted mt-1">Aylık taksit: <strong><?= para($aylik_taksit) ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 bg-success bg-opacity-10 rounded text-center">
                            <div class="small text-muted">Ödenen</div>
                            <div class="fw-bold text-success"><?= para($satis['odenen_tutar']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 <?= $satis['kalan_tutar']>0 ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10' ?> rounded text-center">
                            <div class="small text-muted">Kalan</div>
                            <div class="fw-bold <?= $satis['kalan_tutar']>0 ? 'text-danger' : 'text-success' ?>">
                                <?= $satis['kalan_tutar']>0 ? para($satis['kalan_tutar']) : 'Tahsil Edildi ✓' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($satis['notlar']): ?>
                <div class="mt-2 p-2 bg-light rounded small">Not: <?= escH($satis['notlar']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sağ panel -->
    <div class="col-md-4 no-print">

        <!-- Taksit Durumu (sadece taksitli satışlarda) -->
        <?php if ($taksitli): ?>
        <div class="card shadow-sm mb-3 border-primary">
            <div class="card-header bg-primary text-white fw-semibold py-2">
                <i class="bi bi-calendar-week"></i> Taksit Durumu
            </div>
            <div class="card-body">
                <!-- İlerleme çubuğu -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-success fw-semibold"><?= $odenen_taksit ?> taksit ödendi</span>
                        <span class="text-danger fw-semibold"><?= $kalan_taksit ?> taksit kaldı</span>
                    </div>
                    <div class="progress" style="height:12px; border-radius:8px">
                        <div class="progress-bar bg-success" style="width:<?= $taksit_ilerleme ?>%">
                            <?php if ($taksit_ilerleme >= 20): ?><?= $taksit_ilerleme ?>%<?php endif; ?>
                        </div>
                    </div>
                    <div class="text-center text-muted small mt-1">
                        <?= $odenen_taksit ?> / <?= $satis['taksit_sayisi'] ?> taksit
                    </div>
                </div>

                <!-- Taksit detay tablosu -->
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <td class="text-muted small">Toplam Taksit</td>
                        <td class="fw-bold text-center"><?= $satis['taksit_sayisi'] ?> taksit</td>
                    </tr>
                    <tr>
                        <td class="text-muted small">Aylık Taksit</td>
                        <td class="fw-bold text-primary text-center"><?= para($aylik_taksit) ?></td>
                    </tr>
                    <tr class="table-success">
                        <td class="small">Ödenen Taksit</td>
                        <td class="fw-bold text-success text-center"><?= $odenen_taksit ?> taksit</td>
                    </tr>
                    <tr class="<?= $kalan_taksit > 0 ? 'table-danger' : 'table-success' ?>">
                        <td class="small">Kalan Taksit</td>
                        <td class="fw-bold text-center <?= $kalan_taksit > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= $kalan_taksit > 0 ? $kalan_taksit . ' taksit' : '✓ Tamamlandı' ?>
                        </td>
                    </tr>
                    <?php if ($kalan_taksit > 0): ?>
                    <tr>
                        <td class="text-muted small">Kalan Tutar</td>
                        <td class="fw-bold text-danger text-center"><?= para($satis['kalan_tutar']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ödeme geçmişi -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Ödeme Geçmişi</div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr>
                    <th>Tarih</th>
                    <?php if ($taksitli): ?><th class="text-center">Taksit</th><?php endif; ?>
                    <th>Tutar</th>
                    <th>Tip</th>
                </tr></thead>
                <tbody>
                <?php if (empty($odemeler)): ?>
                    <tr><td colspan="<?= $taksitli ? 4 : 3 ?>" class="text-center text-muted">Ödeme yok</td></tr>
                <?php else: ?>
                <?php foreach ($odemeler as $o): ?>
                <tr>
                    <td><?= tarih($o['tarih']) ?></td>
                    <?php if ($taksitli): ?>
                    <td class="text-center">
                        <?php if ($o['taksit_no']): ?>
                            <span class="badge bg-primary"><?= $o['taksit_no'] ?>. taksit</span>
                        <?php else: ?>
                            <span class="text-muted small">Peşin</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td class="fw-bold text-success"><?= para($o['tutar']) ?></td>
                    <td><?= ucfirst(str_replace('_',' ',$o['odeme_tipi'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($satis['durum'] !== 'iptal'): ?>
        <!-- İptal -->
        <form method="post" action="iptal.php">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-outline-danger w-100"
                onclick="return confirm('Bu satışı iptal etmek istediğinize emin misiniz? Stok iadesi yapılacak.')"
                <?= $satis['durum']==='tamamlandi'?'':'disabled' ?>>
                <i class="bi bi-x-circle"></i> Satışı İptal Et
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
