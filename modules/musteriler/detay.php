<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
$yonetici = ($_SESSION['rol'] ?? '') === 'yonetici';

$m = $pdo->prepare("SELECT * FROM musteriler WHERE id=?");
$m->execute([$id]); $m = $m->fetch();
if (!$m) { flash('hata','Müşteri bulunamadı.'); header('Location: index.php'); exit; }
$adi = trim($m['ad'] . ' ' . ($m['soyad'] ?? ''));
$gorunenAd = ($m['tip'] === 'kurumsal' && $m['firma_adi']) ? $m['firma_adi'] : $adi;
$sayfa_basligi = $gorunenAd;

// ── Not ekleme / silme ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    if (isset($_POST['not_ekle'])) {
        $metin = mb_substr(trim($_POST['not_metni'] ?? ''), 0, 500, 'UTF-8');
        if ($metin !== '') {
            $pdo->prepare("INSERT INTO musteri_notlari (musteri_id, not_metni, kullanici_id) VALUES (?,?,?)")
                ->execute([$id, $metin, $_SESSION['kullanici_id'] ?? null]);
            flash('basari', 'Not eklendi.');
        }
    } elseif (isset($_POST['not_sil']) && $yonetici) {
        $pdo->prepare("DELETE FROM musteri_notlari WHERE id=? AND musteri_id=?")->execute([(int)$_POST['not_id'], $id]);
        flash('basari', 'Not silindi.');
    }
    header('Location: detay.php?id=' . $id . '#notlar'); exit;
}

// ── Veriler ──────────────────────────────────────────────────
$satislar = $pdo->prepare("SELECT * FROM satislar WHERE musteri_id=? ORDER BY tarih DESC, id DESC");
$satislar->execute([$id]); $satislar = $satislar->fetchAll();

// Ödemeler — iptal satışa ait olanlar işaretlenir, toplamlara/ekstreye katılmaz
$odemeler = $pdo->prepare("SELECT o.*, s.fatura_no, s.durum AS satis_durum FROM odemeler o
    LEFT JOIN satislar s ON o.satis_id = s.id
    WHERE o.musteri_id=? ORDER BY o.tarih DESC, o.id DESC");
$odemeler->execute([$id]); $odemeler = $odemeler->fetchAll();
$gecerliOdemeler = array_values(array_filter($odemeler, fn($o) => ($o['satis_durum'] ?? '') !== 'iptal'));

// Taksitler
$taksitler = $pdo->prepare("SELECT t.*, s.fatura_no, s.id AS sid FROM taksit_plani t
    JOIN satislar s ON t.satis_id=s.id
    WHERE s.musteri_id=? AND s.durum!='iptal' ORDER BY t.odendi, t.vade_tarihi");
$taksitler->execute([$id]); $taksitler = $taksitler->fetchAll();
$bekleyenTaksitler = array_values(array_filter($taksitler, fn($t) => !$t['odendi']));
$gecikmisler = array_values(array_filter($bekleyenTaksitler, fn($t) => $t['vade_tarihi'] < date('Y-m-d')));
$gecikmisToplam = array_sum(array_column($gecikmisler, 'tutar'));

// Notlar
$notlar = $pdo->prepare("SELECT n.*, ku.ad_soyad FROM musteri_notlari n
    LEFT JOIN kullanicilar ku ON n.kullanici_id=ku.id WHERE n.musteri_id=? ORDER BY n.id DESC");
$notlar->execute([$id]); $notlar = $notlar->fetchAll();

// Satın alma özeti
$topUrunler = $pdo->prepare("SELECT u.ad, u.kod, SUM(sk.miktar) AS adet, SUM(sk.toplam) AS tutar
    FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id JOIN urunler u ON sk.urun_id=u.id
    WHERE s.musteri_id=? AND s.durum!='iptal' GROUP BY sk.urun_id ORDER BY tutar DESC LIMIT 5");
$topUrunler->execute([$id]); $topUrunler = $topUrunler->fetchAll();
$topKategoriler = $pdo->prepare("SELECT COALESCE(k.ad,'Kategorisiz') AS kategori, SUM(sk.miktar) AS adet, SUM(sk.toplam) AS tutar
    FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id JOIN urunler u ON sk.urun_id=u.id
    LEFT JOIN kategoriler k ON u.kategori_id=k.id
    WHERE s.musteri_id=? AND s.durum!='iptal' GROUP BY u.kategori_id ORDER BY tutar DESC LIMIT 5");
$topKategoriler->execute([$id]); $topKategoriler = $topKategoriler->fetchAll();

// Özet
$aktifSatislar = array_values(array_filter($satislar, fn($s) => $s['durum'] !== 'iptal'));
$toplamSatis   = array_sum(array_column($aktifSatislar, 'genel_toplam'));
$toplamOdenen  = array_sum(array_column($gecerliOdemeler, 'tutar'));
$aktifSatis    = count($aktifSatislar);
$ortSepet      = $aktifSatis ? $toplamSatis / $aktifSatis : 0;
$ilkSatis      = $aktifSatislar ? min(array_column($aktifSatislar, 'tarih')) : null;
$aySayisi      = $ilkSatis ? max(1, round((time() - strtotime($ilkSatis)) / 2592000, 1)) : 0;
$siklik        = $aySayisi ? round($aktifSatis / $aySayisi, 1) : 0;

// Ekstre (iptal satışlar ve onların ödemeleri hariç)
$ekstre = [];
foreach ($aktifSatislar as $s) {
    $ekstre[] = ['tarih' => $s['tarih'], 'tip' => 'borc', 'aciklama' => 'Satış — ' . $s['fatura_no'],
        'tutar' => $s['genel_toplam'], 'link' => BASE_URL . '/modules/satislar/detay.php?id=' . $s['id']];
}
foreach ($gecerliOdemeler as $o) {
    $ekstre[] = ['tarih' => $o['tarih'], 'tip' => 'odeme',
        'aciklama' => 'Ödeme' . ($o['fatura_no'] ? ' — ' . $o['fatura_no'] : '') . ($o['taksit_no'] ? ' (' . $o['taksit_no'] . '. taksit)' : ''),
        'tutar' => $o['tutar'], 'link' => null];
}
usort($ekstre, fn($a, $b) => strcmp($a['tarih'], $b['tarih']));

// ── Ekstre CSV ───────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ekstre_' . $id . '_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tarih','İşlem','Borç','Ödeme'], ';');
    $bakiye = 0;
    foreach ($ekstre as $e) {
        if ($e['tip'] === 'borc') { fputcsv($out, [$e['tarih'], csvHucre($e['aciklama']), number_format($e['tutar'],2,',','.'), ''], ';'); $bakiye += $e['tutar']; }
        else { fputcsv($out, [$e['tarih'], csvHucre($e['aciklama']), '', number_format($e['tutar'],2,',','.')], ';'); $bakiye -= $e['tutar']; }
    }
    fputcsv($out, ['', 'KALAN BAKIYE', number_format(max(0, $bakiye),2,',','.'), ''], ';');
    fclose($out); exit;
}

// ── Yazdırılabilir ekstre ────────────────────────────────────
if (isset($_GET['yazdir'])) {
    $firma = ayar('firma_adi', 'Regal Bayi');
    ?><!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><title>Hesap Ekstresi — <?= escH($gorunenAd) ?></title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, sans-serif; font-size:12px; padding:10mm; }
    h2 { font-size:16px; } .ust { display:flex; justify-content:space-between; border-bottom:2px solid #333; padding-bottom:6px; margin-bottom:8px; }
    .bilgi div { margin-bottom:2px; }
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th, td { border:1px solid #999; padding:4px 6px; text-align:left; }
    th { background:#f0f0f0; } .sag { text-align:right; }
    tfoot td { font-weight:bold; background:#f8f8f8; }
    .arac { margin-bottom:10px; } .arac button { padding:6px 14px; }
    @media print { .arac { display:none; } }
</style></head><body>
<div class="arac"><button onclick="window.print()">🖨 Yazdır</button> <button onclick="history.back()">← Geri</button></div>
<div class="ust">
    <div><h2><?= escH($firma) ?></h2><div>HESAP EKSTRESİ</div></div>
    <div style="text-align:right"><div>Tarih: <?= date('d.m.Y') ?></div></div>
</div>
<div class="bilgi">
    <div><strong>Müşteri:</strong> <?= escH($gorunenAd) ?><?= $m['tip']==='kurumsal' && $adi ? ' (' . escH($adi) . ')' : '' ?></div>
    <?php if ($m['telefon']): ?><div><strong>Telefon:</strong> <?= escH($m['telefon']) ?></div><?php endif; ?>
    <?php if ($m['tc_no']): ?><div><strong>TC No:</strong> <?= escH($m['tc_no']) ?></div><?php endif; ?>
    <?php if ($m['vergi_no']): ?><div><strong>Vergi No:</strong> <?= escH($m['vergi_no']) ?></div><?php endif; ?>
</div>
<table>
    <thead><tr><th style="width:24mm">Tarih</th><th>İşlem</th><th class="sag" style="width:30mm">Borç</th>
        <th class="sag" style="width:30mm">Ödeme</th><th class="sag" style="width:30mm">Bakiye</th></tr></thead>
    <tbody>
    <?php $bakiye = 0; foreach ($ekstre as $e): ?>
    <?php $bakiye += $e['tip'] === 'borc' ? $e['tutar'] : -$e['tutar']; ?>
    <tr><td><?= tarih($e['tarih']) ?></td><td><?= escH($e['aciklama']) ?></td>
        <td class="sag"><?= $e['tip']==='borc' ? para($e['tutar']) : '' ?></td>
        <td class="sag"><?= $e['tip']==='odeme' ? para($e['tutar']) : '' ?></td>
        <td class="sag"><?= para($bakiye) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4">KALAN BAKİYE</td><td class="sag"><?= para(max(0, $bakiye)) ?></td></tr></tfoot>
</table>
</body></html><?php
    exit;
}

// WhatsApp hatırlatma mesajı
$firma = ayar('firma_adi', 'Regal Bayi');
$hatirlatmaMesaji = "Sayın $gorunenAd, $firma kaydınızda "
    . ($gecikmisToplam > 0
        ? 'vadesi geçmiş ' . count($gecikmisler) . ' taksit (toplam ' . para($gecikmisToplam) . ') bulunmaktadır.'
        : 'kalan ' . para($m['toplam_borc']) . ' bakiyeniz bulunmaktadır.')
    . ' Ödemeniz için teşekkür ederiz.';
$waHatirlatma = $m['telefon'] ? whatsappLink($m['telefon'], $hatirlatmaMesaji) : null;
$wa = $m['telefon'] ? whatsappLink($m['telefon']) : null;

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-<?= $m['tip']==='kurumsal' ? 'building' : 'person-circle' ?> text-primary"></i>
            <?= escH($gorunenAd) ?>
            <?= $m['aktif'] ? '' : '<span class="badge bg-secondary">Arşiv</span>' ?></h4>
        <?php if ($m['tip'] === 'kurumsal' && $adi): ?><span class="text-muted"><i class="bi bi-person"></i> <?= escH($adi) ?></span>
        <?php elseif ($m['firma_adi']): ?><span class="text-muted"><i class="bi bi-building"></i> <?= escH($m['firma_adi']) ?></span><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($m['toplam_borc'] > 0 && in_array($_SESSION['rol'] ?? '', ['yonetici','kasiyer'], true)): ?>
        <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?musteri_id=<?= $id ?>" class="btn btn-success btn-sm">
            <i class="bi bi-cash-coin"></i> Tahsilat Al</a>
        <?php endif; ?>
        <?php if ($waHatirlatma && $m['toplam_borc'] > 0): ?>
        <a href="<?= escH($waHatirlatma) ?>" target="_blank" class="btn btn-outline-success btn-sm" title="WhatsApp ile borç hatırlatma mesajı">
            <i class="bi bi-whatsapp"></i> Hatırlat</a>
        <?php endif; ?>
        <a href="?id=<?= $id ?>&yazdir=1" target="_blank" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer"></i> Ekstre</a>
        <a href="?id=<?= $id ?>&export=1" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="duzenle.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Düzenle</a>
        <a href="<?= BASE_URL ?>/modules/satislar/yeni.php?musteri_id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-receipt"></i> Yeni Satış</a>
    </div>
</div>

<?php if ($gecikmisler): ?>
<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 py-2">
    <i class="bi bi-calendar-x fs-5"></i>
    <span><strong><?= count($gecikmisler) ?> taksit vadesi geçmiş</strong> — toplam <strong><?= para($gecikmisToplam) ?></strong>
        (en eski: <?= tarih($gecikmisler[0]['vade_tarihi']) ?>).</span>
    <?php if ($waHatirlatma): ?>
    <a href="<?= escH($waHatirlatma) ?>" target="_blank" class="btn btn-sm btn-success ms-auto"><i class="bi bi-whatsapp"></i> WhatsApp ile Hatırlat</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Özet Kartlar -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="small text-muted">Toplam Satış</div>
            <div class="fw-bold"><?= para($toplamSatis) ?></div>
            <div class="small text-muted"><?= $aktifSatis ?> işlem</div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="small text-muted">Toplam Ödenen</div>
            <div class="fw-bold text-success"><?= para($toplamOdenen) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100 <?= $m['toplam_borc'] > 0 ? 'border-danger' : '' ?>"><div class="card-body py-2 text-center">
            <div class="small text-muted">Kalan Borç</div>
            <div class="fw-bold <?= $m['toplam_borc'] > 0 ? 'text-danger' : 'text-success' ?>"><?= para($m['toplam_borc']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="small text-muted">Ortalama Sepet</div>
            <div class="fw-bold"><?= para($ortSepet) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="small text-muted">Sıklık</div>
            <div class="fw-bold"><?= $siklik ?: '-' ?><?= $siklik ? ' /ay' : '' ?></div>
            <?php if ($ilkSatis): ?><div class="small text-muted">ilk: <?= tarih($ilkSatis) ?></div><?php endif; ?>
        </div></div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card shadow-sm h-100 <?= $gecikmisler ? 'border-danger' : '' ?>"><div class="card-body py-2 text-center">
            <div class="small text-muted">Bekleyen Taksit</div>
            <div class="fw-bold <?= $gecikmisler ? 'text-danger' : '' ?>"><?= count($bekleyenTaksitler) ?><?= $gecikmisler ? ' (' . count($gecikmisler) . ' gecikmiş)' : '' ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <!-- Sol sütun -->
    <div class="col-lg-4">
        <!-- Müşteri bilgileri -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-person-vcard text-primary"></i> Müşteri Bilgileri</div>
            <div class="card-body py-2">
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted">Tip</th><td><?= $m['tip']==='kurumsal' ? 'Kurumsal' : 'Bireysel' ?></td></tr>
                    <?php if ($m['telefon']): ?>
                    <tr><th class="text-muted">Telefon</th><td>
                        <a href="tel:<?= escH(telefonNormalize($m['telefon'])) ?>"><?= escH($m['telefon']) ?></a>
                        <?php if ($wa): ?><a href="<?= escH($wa) ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-1 ms-1"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
                    </td></tr>
                    <?php endif; ?>
                    <?php if ($m['telefon2']): ?><tr><th class="text-muted">Telefon 2</th><td><a href="tel:<?= escH(telefonNormalize($m['telefon2'])) ?>"><?= escH($m['telefon2']) ?></a></td></tr><?php endif; ?>
                    <?php if ($m['email']): ?><tr><th class="text-muted">E-posta</th><td><a href="mailto:<?= escH($m['email']) ?>"><?= escH($m['email']) ?></a></td></tr><?php endif; ?>
                    <?php if ($m['tc_no']): ?><tr><th class="text-muted">TC No</th><td><?= escH(veriMaskele($m['tc_no'])) ?></td></tr><?php endif; ?>
                    <?php if ($m['vergi_no']): ?><tr><th class="text-muted">Vergi No</th><td><?= escH($m['vergi_no']) ?></td></tr><?php endif; ?>
                    <?php if ($m['sehir']): ?><tr><th class="text-muted">Şehir</th><td><?= escH($m['sehir']) ?></td></tr><?php endif; ?>
                    <?php if ($m['adres']): ?><tr><th class="text-muted">Adres</th><td><?= nl2br(escH($m['adres'])) ?></td></tr><?php endif; ?>
                    <tr><th class="text-muted">Kayıt</th><td><?= tarih($m['created_at']) ?></td></tr>
                    <?php if ($m['notlar']): ?><tr><th class="text-muted">Genel Not</th><td class="small"><?= nl2br(escH($m['notlar'])) ?></td></tr><?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Satın alma özeti -->
        <?php if ($topUrunler): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-bag text-primary"></i> Satın Alma Özeti</div>
            <div class="card-body py-2 small">
                <div class="fw-semibold text-muted mb-1">En çok aldığı ürünler</div>
                <?php foreach ($topUrunler as $t): ?>
                <div class="d-flex justify-content-between"><span class="text-truncate me-2"><?= escH($t['ad']) ?></span>
                    <span class="text-nowrap"><?= $t['adet'] ?> ad. · <?= para($t['tutar']) ?></span></div>
                <?php endforeach; ?>
                <hr class="my-2">
                <div class="fw-semibold text-muted mb-1">Kategoriler</div>
                <?php foreach ($topKategoriler as $t): ?>
                <div class="d-flex justify-content-between"><span><?= escH($t['kategori']) ?></span>
                    <span class="text-nowrap"><?= para($t['tutar']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notlar -->
        <div class="card shadow-sm" id="notlar">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-journal-text text-primary"></i> Not Geçmişi (<?= count($notlar) ?>)</div>
            <div class="card-body py-2">
                <form method="post" class="d-flex gap-2 mb-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="not_ekle" value="1">
                    <input type="text" name="not_metni" class="form-control form-control-sm" placeholder="Not ekle (örn: arandı, pazartesi ödeyecek)" maxlength="500" required>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-plus"></i></button>
                </form>
                <?php if (!$notlar): ?><div class="text-muted small">Henüz not yok.</div><?php endif; ?>
                <div style="max-height:260px;overflow-y:auto">
                <?php foreach ($notlar as $n): ?>
                <div class="border-start border-3 border-primary ps-2 mb-2 small">
                    <div><?= escH($n['not_metni']) ?></div>
                    <div class="text-muted d-flex justify-content-between">
                        <span><?= tarihSaat($n['created_at']) ?><?= $n['ad_soyad'] ? ' · ' . escH($n['ad_soyad']) : '' ?></span>
                        <?php if ($yonetici): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Not silinsin mi?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="not_sil" value="1">
                            <input type="hidden" name="not_id" value="<?= $n['id'] ?>">
                            <button class="btn btn-link btn-sm text-danger p-0" style="font-size:.75rem">sil</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sağ: sekmeler -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white p-0">
                <ul class="nav nav-tabs card-header-tabs m-2">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabEkstre" type="button">Ekstre</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSatis" type="button">Satışlar (<?= count($satislar) ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTaksit" type="button">
                        Taksitler (<?= count($bekleyenTaksitler) ?><?= $gecikmisler ? ' <span class="text-danger">!</span>' : '' ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabOdeme" type="button">Ödemeler (<?= count($odemeler) ?>)</button></li>
                </ul>
            </div>
            <div class="card-body p-0 tab-content">
                <!-- Ekstre -->
                <div class="tab-pane fade show active" id="tabEkstre">
                    <div class="table-responsive" style="max-height:480px;overflow-y:auto">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="sticky-top bg-white"><tr><th>Tarih</th><th>Açıklama</th>
                            <th class="text-end">Borç</th><th class="text-end">Ödeme</th><th class="text-end">Bakiye</th></tr></thead>
                        <tbody>
                        <?php if (!$ekstre): ?><tr><td colspan="5" class="text-center text-muted py-3">Hareket yok</td></tr><?php endif; ?>
                        <?php $bakiye = 0; foreach ($ekstre as $e): $bakiye += $e['tip']==='borc' ? $e['tutar'] : -$e['tutar']; ?>
                        <tr>
                            <td class="text-nowrap"><?= tarih($e['tarih']) ?></td>
                            <td><?= $e['link'] ? '<a href="'.$e['link'].'" class="text-decoration-none">'.escH($e['aciklama']).'</a>' : escH($e['aciklama']) ?></td>
                            <td class="text-end text-danger"><?= $e['tip']==='borc' ? para($e['tutar']) : '' ?></td>
                            <td class="text-end text-success"><?= $e['tip']==='odeme' ? para($e['tutar']) : '' ?></td>
                            <td class="text-end fw-semibold"><?= para($bakiye) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <!-- Satışlar -->
                <div class="tab-pane fade" id="tabSatis">
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Fatura</th><th>Tarih</th><th class="text-end">Toplam</th>
                            <th class="text-end">Kalan</th><th>Ödeme</th><th>Durum</th></tr></thead>
                        <tbody>
                        <?php if (!$satislar): ?><tr><td colspan="6" class="text-center text-muted py-3">Satış yok</td></tr><?php endif; ?>
                        <?php foreach ($satislar as $s): ?>
                        <tr class="<?= $s['durum']==='iptal' ? 'opacity-50' : '' ?>">
                            <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $s['id'] ?>"><code><?= escH($s['fatura_no']) ?></code></a></td>
                            <td class="text-nowrap"><?= tarih($s['tarih']) ?></td>
                            <td class="text-end fw-semibold"><?= para($s['genel_toplam']) ?></td>
                            <td class="text-end <?= $s['kalan_tutar']>0?'text-danger':'' ?>"><?= $s['kalan_tutar']>0 ? para($s['kalan_tutar']) : '-' ?></td>
                            <td><small><?= escH($s['odeme_tipi']) ?><?= $s['taksit_sayisi']>1 ? ' ('.$s['taksit_sayisi'].'x)' : '' ?></small></td>
                            <td><span class="badge bg-<?= ['tamamlandi'=>'success','bekliyor'=>'warning','iptal'=>'danger'][$s['durum']] ?? 'secondary' ?>"><?= escH($s['durum']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <!-- Taksitler -->
                <div class="tab-pane fade" id="tabTaksit">
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Fatura</th><th>Taksit</th><th>Vade</th><th class="text-end">Tutar</th><th>Durum</th></tr></thead>
                        <tbody>
                        <?php if (!$taksitler): ?><tr><td colspan="5" class="text-center text-muted py-3">Taksitli satış yok</td></tr><?php endif; ?>
                        <?php foreach ($taksitler as $t): ?>
                        <?php $gecikti = !$t['odendi'] && $t['vade_tarihi'] < date('Y-m-d'); ?>
                        <tr class="<?= $gecikti ? 'table-danger' : ($t['odendi'] ? '' : 'table-warning') ?>">
                            <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $t['sid'] ?>"><code><?= escH($t['fatura_no']) ?></code></a></td>
                            <td><?= $t['taksit_no'] ?>. taksit</td>
                            <td class="text-nowrap"><?= tarih($t['vade_tarihi']) ?><?= $gecikti ? ' <i class="bi bi-exclamation-triangle-fill text-danger"></i>' : '' ?></td>
                            <td class="text-end fw-semibold"><?= para($t['tutar']) ?></td>
                            <td><?= $t['odendi']
                                ? '<span class="badge bg-success">Ödendi' . ($t['odeme_tarihi'] ? ' · ' . tarih($t['odeme_tarihi']) : '') . '</span>'
                                : ($gecikti ? '<span class="badge bg-danger">Gecikmiş</span>' : '<span class="badge bg-warning text-dark">Bekliyor</span>') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <!-- Ödemeler -->
                <div class="tab-pane fade" id="tabOdeme">
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Tarih</th><th>Fatura</th><th class="text-end">Tutar</th><th>Tip</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$odemeler): ?><tr><td colspan="5" class="text-center text-muted py-3">Ödeme yok</td></tr><?php endif; ?>
                        <?php foreach ($odemeler as $o): ?>
                        <?php $iptalMi = ($o['satis_durum'] ?? '') === 'iptal'; ?>
                        <tr class="<?= $iptalMi ? 'opacity-50' : '' ?>">
                            <td class="text-nowrap"><?= tarih($o['tarih']) ?></td>
                            <td><?= $o['fatura_no'] ? '<code>'.escH($o['fatura_no']).'</code>' : '-' ?><?= $o['taksit_no'] ? ' <small>('.$o['taksit_no'].'. taksit)</small>' : '' ?></td>
                            <td class="text-end fw-semibold text-success"><?= para($o['tutar']) ?></td>
                            <td><small><?= escH($o['odeme_tipi'] ?? '-') ?></small></td>
                            <td><?= $iptalMi ? '<span class="badge bg-secondary" title="Satış iptal edildi; tutar iade edildi, toplamlara dahil değil">iptal satış</span>' : '' ?></td>
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
