<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo']);
$sayfa_basligi = 'Sayım Geçmişi';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

// ── Oturum detayı verisi ─────────────────────────────────────
$sayim = null; $detaylar = [];
if ($id) {
    $s = $pdo->prepare("SELECT s.*, ku.ad_soyad FROM sayimlar s LEFT JOIN kullanicilar ku ON s.kullanici_id=ku.id WHERE s.id=?");
    $s->execute([$id]);
    $sayim = $s->fetch();
    if (!$sayim) { flash('hata', 'Sayım kaydı bulunamadı.'); header('Location: sayim_gecmis.php'); exit; }
    $d = $pdo->prepare("SELECT sd.*, u.kod, u.ad, k.ad AS kategori FROM sayim_detaylari sd
        JOIN urunler u ON sd.urun_id=u.id LEFT JOIN kategoriler k ON u.kategori_id=k.id
        WHERE sd.sayim_id=? ORDER BY sd.maliyet ASC");
    $d->execute([$id]);
    $detaylar = $d->fetchAll();
}

// ── Detay CSV ────────────────────────────────────────────────
if ($id && isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sayim_' . $id . '_' . date('Y-m-d', strtotime($sayim['created_at'])) . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kod','Ürün','Kategori','Önceki','Sayılan','Fark','Maliyet Etkisi'], ';');
    foreach ($detaylar as $d) {
        fputcsv($out, [csvHucre($d['kod']), csvHucre($d['ad']), csvHucre($d['kategori'] ?? ''),
            $d['onceki'], $d['sayilan'], $d['fark'], number_format($d['maliyet'], 2, ',', '.')], ';');
    }
    fclose($out); exit;
}

// ── Tutanak yazdırma ─────────────────────────────────────────
if ($id && isset($_GET['yazdir'])) {
    $firma = ayar('firma_adi', 'Regal Bayi');
    ?><!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><title>Sayım Tutanağı #<?= $id ?></title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, sans-serif; font-size:12px; padding:10mm; }
    h2 { font-size:16px; } .ust { display:flex; justify-content:space-between; border-bottom:2px solid #333; padding-bottom:6px; margin-bottom:8px; }
    .bilgi div { margin-bottom:2px; }
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th, td { border:1px solid #999; padding:4px 6px; text-align:left; }
    th { background:#f0f0f0; } .orta { text-align:center; } .sag { text-align:right; }
    tfoot td { font-weight:bold; background:#f8f8f8; }
    .imza { display:flex; justify-content:space-between; margin-top:22mm; }
    .imza div { width:60mm; border-top:1px solid #333; padding-top:4px; text-align:center; }
    .arac { margin-bottom:10px; } .arac button { padding:6px 14px; }
    @media print { .arac { display:none; } }
</style></head><body>
<div class="arac"><button onclick="window.print()">🖨 Yazdır</button> <button onclick="history.back()">← Geri</button></div>
<div class="ust">
    <div><h2><?= escH($firma) ?></h2><div>STOK SAYIM TUTANAĞI — #<?= $id ?></div></div>
    <div style="text-align:right"><div>Tarih: <?= tarihSaat($sayim['created_at']) ?></div></div>
</div>
<div class="bilgi">
    <div><strong>Kapsam:</strong> <?= escH($sayim['kapsam']) ?></div>
    <div><strong>Açıklama:</strong> <?= escH($sayim['aciklama']) ?></div>
    <div><strong>Sayan:</strong> <?= escH($sayim['ad_soyad'] ?? '-') ?></div>
    <div><strong>Özet:</strong> <?= $sayim['sayilan'] ?> ürün sayıldı, <?= $sayim['degisen'] ?> üründe fark;
        net fark <?= $sayim['net_fark'] > 0 ? '+' : '' ?><?= $sayim['net_fark'] ?> adet,
        maliyet etkisi <?= para($sayim['maliyet_etkisi']) ?><?= $sayim['fire_islendi'] ? ' (eksikler fire kaydedildi)' : '' ?>.</div>
</div>
<table>
    <thead><tr><th style="width:24mm">Kod</th><th>Ürün</th><th class="orta" style="width:18mm">Önceki</th>
        <th class="orta" style="width:18mm">Sayılan</th><th class="orta" style="width:16mm">Fark</th><th class="sag" style="width:28mm">Maliyet</th></tr></thead>
    <tbody>
    <?php if (!$detaylar): ?><tr><td colspan="6" class="orta">Fark yok — sayım sistemle birebir eşleşti.</td></tr><?php endif; ?>
    <?php foreach ($detaylar as $d): ?>
    <tr><td><?= escH($d['kod']) ?></td><td><?= escH($d['ad']) ?></td>
        <td class="orta"><?= $d['onceki'] ?></td><td class="orta"><?= $d['sayilan'] ?></td>
        <td class="orta"><?= $d['fark'] > 0 ? '+' : '' ?><?= $d['fark'] ?></td>
        <td class="sag"><?= para($d['maliyet']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4">TOPLAM</td>
        <td class="orta"><?= $sayim['net_fark'] > 0 ? '+' : '' ?><?= $sayim['net_fark'] ?></td>
        <td class="sag"><?= para($sayim['maliyet_etkisi']) ?></td></tr></tfoot>
</table>
<div class="imza"><div>Sayan</div><div>Onaylayan</div></div>
</body></html><?php
    exit;
}

// ── Liste + analiz verisi ────────────────────────────────────
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$limit = 20; $offset = ($sayfa - 1) * $limit;
$toplam = (int)$pdo->query("SELECT COUNT(*) FROM sayimlar")->fetchColumn();
$sayfaSayisi = max(1, ceil($toplam / $limit));
$oturumlar = $pdo->query("SELECT s.*, ku.ad_soyad FROM sayimlar s LEFT JOIN kullanicilar ku ON s.kullanici_id=ku.id
    ORDER BY s.id DESC LIMIT $limit OFFSET $offset")->fetchAll();

// Fark analizi (son 90 gün)
$analiz = $pdo->query("SELECT
        COALESCE(SUM(CASE WHEN sd.maliyet < 0 THEN sd.maliyet ELSE 0 END),0) AS kayip,
        COALESCE(SUM(CASE WHEN sd.maliyet > 0 THEN sd.maliyet ELSE 0 END),0) AS fazla,
        COUNT(DISTINCT sd.sayim_id) AS oturum
    FROM sayim_detaylari sd JOIN sayimlar s ON sd.sayim_id=s.id
    WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)")->fetch();
$envanterDegeri = (float)$pdo->query("SELECT COALESCE(SUM(stok_adedi*alis_fiyati),0) FROM urunler WHERE aktif=1")->fetchColumn();
$kayipOrani = $envanterDegeri > 0 ? abs((float)$analiz['kayip']) / $envanterDegeri * 100 : 0;

$enCokKayipUrun = $pdo->query("SELECT u.kod, u.ad, SUM(sd.fark) AS fark, SUM(sd.maliyet) AS maliyet
    FROM sayim_detaylari sd JOIN sayimlar s ON sd.sayim_id=s.id JOIN urunler u ON sd.urun_id=u.id
    WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND sd.fark < 0
    GROUP BY sd.urun_id ORDER BY maliyet ASC LIMIT 5")->fetchAll();

$enCokKayipKategori = $pdo->query("SELECT COALESCE(k.ad,'Kategorisiz') AS kategori, SUM(sd.maliyet) AS maliyet
    FROM sayim_detaylari sd JOIN sayimlar s ON sd.sayim_id=s.id JOIN urunler u ON sd.urun_id=u.id
    LEFT JOIN kategoriler k ON u.kategori_id=k.id
    WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND sd.fark < 0
    GROUP BY u.kategori_id ORDER BY maliyet ASC LIMIT 5")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-clock-history text-primary"></i> Sayım Geçmişi</h4>
    <div class="d-flex gap-2">
        <a href="sayim.php" class="btn btn-sm btn-primary"><i class="bi bi-clipboard-check"></i> Yeni Sayım</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">← Stok</a>
    </div>
</div>

<?php if ($sayim): ?>
<!-- ═══ OTURUM DETAYI ═══ -->
<div class="card shadow-sm mb-3 border-primary">
    <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-clipboard-data text-primary"></i> Sayım #<?= $sayim['id'] ?> — <?= tarihSaat($sayim['created_at']) ?></span>
        <div class="d-flex gap-2">
            <a href="?id=<?= $sayim['id'] ?>&yazdir=1" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Tutanak</a>
            <a href="?id=<?= $sayim['id'] ?>&csv=1" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
            <a href="sayim_gecmis.php" class="btn btn-sm btn-outline-secondary">← Liste</a>
        </div>
    </div>
    <div class="card-body">
        <p class="mb-2"><strong>Kapsam:</strong> <?= escH($sayim['kapsam']) ?> · <strong>Sayan:</strong> <?= escH($sayim['ad_soyad'] ?? '-') ?>
            · <strong>Not:</strong> <?= escH($sayim['aciklama']) ?>
            <?= $sayim['fire_islendi'] ? '<span class="badge bg-danger">Eksikler fire kaydedildi</span>' : '' ?></p>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2"><div class="border rounded p-2"><div class="small text-muted">Sayılan</div><div class="fw-bold"><?= $sayim['sayilan'] ?></div></div></div>
            <div class="col-6 col-md-2"><div class="border rounded p-2"><div class="small text-muted">Değişen</div><div class="fw-bold"><?= $sayim['degisen'] ?></div></div></div>
            <div class="col-6 col-md-2"><div class="border rounded p-2"><div class="small text-muted">Çakışma Atlanan</div><div class="fw-bold <?= $sayim['atlanan'] ? 'text-warning' : '' ?>"><?= $sayim['atlanan'] ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Net Fark</div><div class="fw-bold <?= $sayim['net_fark'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $sayim['net_fark'] > 0 ? '+' : '' ?><?= $sayim['net_fark'] ?> adet</div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Maliyet Etkisi</div><div class="fw-bold <?= $sayim['maliyet_etkisi'] < 0 ? 'text-danger' : 'text-success' ?>"><?= para($sayim['maliyet_etkisi']) ?></div></div></div>
        </div>
        <div class="table-responsive" style="max-height:400px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0">
                <thead class="sticky-top bg-white"><tr><th>Kod</th><th>Ürün</th><th>Kategori</th>
                    <th class="text-center">Önceki</th><th class="text-center">Sayılan</th><th class="text-center">Fark</th><th class="text-end">Maliyet</th></tr></thead>
                <tbody>
                <?php if (!$detaylar): ?><tr><td colspan="7" class="text-center text-muted py-3">Fark yok — sayım sistemle birebir eşleşti.</td></tr><?php endif; ?>
                <?php foreach ($detaylar as $d): ?>
                <tr class="<?= $d['fark'] < 0 ? 'table-danger' : 'table-success' ?>">
                    <td><code><?= escH($d['kod']) ?></code></td>
                    <td><a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $d['urun_id'] ?>" class="text-decoration-none"><?= escH($d['ad']) ?></a></td>
                    <td class="small text-muted"><?= escH($d['kategori'] ?? '-') ?></td>
                    <td class="text-center"><?= $d['onceki'] ?></td>
                    <td class="text-center fw-bold"><?= $d['sayilan'] ?></td>
                    <td class="text-center fw-bold"><?= $d['fark'] > 0 ? '+' : '' ?><?= $d['fark'] ?></td>
                    <td class="text-end"><?= para($d['maliyet']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ FARK ANALİZİ (90 gün) ═══ -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">90 Günde Sayım Kaybı</div>
            <div class="fw-bold text-danger"><?= para(abs($analiz['kayip'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Fazla Çıkan</div>
            <div class="fw-bold text-success"><?= para($analiz['fazla']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100 <?= $kayipOrani > 1 ? 'border-danger' : '' ?>"><div class="card-body py-2">
            <div class="small text-muted">Kayıp Oranı <span title="90 günlük sayım kaybı / mevcut envanter değeri (alış)">ⓘ</span></div>
            <div class="fw-bold <?= $kayipOrani > 1 ? 'text-danger' : '' ?>">%<?= number_format($kayipOrani, 2, ',', '.') ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Sayım Oturumu (90g)</div>
            <div class="fw-bold"><?= (int)$analiz['oturum'] ?></div>
        </div></div>
    </div>
</div>

<?php if ($enCokKayipUrun || $enCokKayipKategori): ?>
<div class="row g-3 mb-3">
    <?php if ($enCokKayipUrun): ?>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-graph-down-arrow text-danger"></i> En Çok Kayıp Veren Ürünler (90g)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($enCokKayipUrun as $e): ?>
                <li class="list-group-item py-1 small d-flex justify-content-between">
                    <span><code><?= escH($e['kod']) ?></code> <?= escH($e['ad']) ?></span>
                    <span class="text-danger fw-bold text-nowrap"><?= $e['fark'] ?> adet · <?= para($e['maliyet']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($enCokKayipKategori): ?>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-tags text-danger"></i> Kategori Bazlı Kayıp (90g)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($enCokKayipKategori as $e): ?>
                <li class="list-group-item py-1 small d-flex justify-content-between">
                    <span><?= escH($e['kategori']) ?></span>
                    <span class="text-danger fw-bold"><?= para($e['maliyet']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══ OTURUM LİSTESİ ═══ -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-list-ul text-primary"></i> Sayım Oturumları</div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
            <thead class="table-light"><tr>
                <th>#</th><th>Tarih</th><th>Kapsam</th><th>Sayan</th>
                <th class="text-center">Sayılan</th><th class="text-center">Değişen</th>
                <th class="text-center">Net Fark</th><th class="text-end">Maliyet</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$oturumlar): ?><tr><td colspan="9" class="text-center text-muted py-4">Henüz sayım kaydı yok</td></tr><?php endif; ?>
            <?php foreach ($oturumlar as $o): ?>
            <tr>
                <td><?= $o['id'] ?></td>
                <td class="text-nowrap"><?= tarihSaat($o['created_at']) ?></td>
                <td class="small"><?= escH($o['kapsam']) ?><?= $o['fire_islendi'] ? ' <span class="badge bg-danger">fire</span>' : '' ?><?= $o['atlanan'] ? ' <span class="badge bg-warning text-dark">' . $o['atlanan'] . ' çakışma</span>' : '' ?></td>
                <td class="small"><?= escH($o['ad_soyad'] ?? '-') ?></td>
                <td class="text-center"><?= $o['sayilan'] ?></td>
                <td class="text-center fw-bold"><?= $o['degisen'] ?></td>
                <td class="text-center fw-bold <?= $o['net_fark'] < 0 ? 'text-danger' : ($o['net_fark'] > 0 ? 'text-success' : '') ?>"><?= $o['net_fark'] > 0 ? '+' : '' ?><?= $o['net_fark'] ?></td>
                <td class="text-end <?= $o['maliyet_etkisi'] < 0 ? 'text-danger' : '' ?>"><?= para($o['maliyet_etkisi']) ?></td>
                <td class="text-end text-nowrap">
                    <a href="?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-info py-0" title="Detay"><i class="bi bi-eye"></i></a>
                    <a href="?id=<?= $o['id'] ?>&yazdir=1" target="_blank" class="btn btn-sm btn-outline-dark py-0" title="Tutanak"><i class="bi bi-printer"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($sayfaSayisi > 1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end">
            <?php for ($i=1; $i<=$sayfaSayisi; $i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>"><a class="page-link" href="?s=<?= $i ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
