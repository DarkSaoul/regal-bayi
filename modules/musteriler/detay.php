<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$m = $pdo->prepare("SELECT * FROM musteriler WHERE id=?");
$m->execute([$id]); $m = $m->fetch();
if (!$m) { flash('hata','Müşteri bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = trim($m['ad'] . ' ' . ($m['soyad'] ?? ''));

// Satış geçmişi
$satislar = $pdo->prepare("SELECT * FROM satislar WHERE musteri_id=? ORDER BY tarih DESC");
$satislar->execute([$id]); $satislar = $satislar->fetchAll();

// Ödeme geçmişi
$odemeler = $pdo->prepare("
    SELECT o.*, s.fatura_no FROM odemeler o
    LEFT JOIN satislar s ON o.satis_id = s.id
    WHERE o.musteri_id=? ORDER BY o.tarih DESC, o.id DESC
");
$odemeler->execute([$id]); $odemeler = $odemeler->fetchAll();

// Özet
$toplamSatis   = array_sum(array_column($satislar,'genel_toplam'));
$toplamOdenen  = array_sum(array_column($odemeler,'tutar'));
$aktifSatis    = count(array_filter($satislar, fn($s) => $s['durum'] !== 'iptal'));

// Ekstre (hareketler birleşik)
$ekstre = [];
foreach ($satislar as $s) {
    if ($s['durum'] === 'iptal') continue;
    $ekstre[] = [
        'tarih'   => $s['tarih'],
        'tip'     => 'borc',
        'aciklama'=> 'Satış — ' . $s['fatura_no'],
        'tutar'   => $s['genel_toplam'],
        'link'    => BASE_URL . '/modules/satislar/detay.php?id=' . $s['id'],
    ];
}
foreach ($odemeler as $o) {
    $ekstre[] = [
        'tarih'   => $o['tarih'],
        'tip'     => 'odeme',
        'aciklama'=> 'Ödeme' . ($o['fatura_no'] ? ' — ' . $o['fatura_no'] : '') . ($o['taksit_no'] ? ' (' . $o['taksit_no'] . '. taksit)' : ''),
        'tutar'   => $o['tutar'],
        'link'    => null,
    ];
}
usort($ekstre, fn($a,$b) => strcmp($a['tarih'], $b['tarih']));

// Ekstre CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ekstre_' . $id . '_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['Tarih','İşlem','Borç','Ödeme'],';');
    $bakiye = 0;
    foreach ($ekstre as $e) {
        if ($e['tip']==='borc')  { fputcsv($out,[$e['tarih'],csvHucre($e['aciklama']),number_format($e['tutar'],2,',','.'),''],';'); $bakiye += $e['tutar']; }
        else                     { fputcsv($out,[$e['tarih'],csvHucre($e['aciklama']),'',number_format($e['tutar'],2,',','.')],';'); $bakiye -= $e['tutar']; }
    }
    fputcsv($out,['','KALAN BAKIYE',number_format(max(0,$bakiye),2,',','.'),''],';');
    fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between">
    <div>
        <h4><i class="bi bi-person-circle text-primary"></i> <?= escH(trim($m['ad'].' '.($m['soyad']??''))) ?></h4>
        <?php if ($m['firma_adi']): ?>
        <span class="text-muted"><i class="bi bi-building"></i> <?= escH($m['firma_adi']) ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a href="?id=<?= $id ?>&export=1" class="btn btn-outline-success btn-sm">
            <i class="bi bi-filetype-csv"></i> Ekstre İndir
        </a>
        <a href="duzenle.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil"></i> Düzenle
        </a>
        <a href="<?= BASE_URL ?>/modules/satislar/yeni.php?musteri_id=<?= $id ?>" class="btn btn-primary">
            <i class="bi bi-receipt"></i> Yeni Satış
        </a>
    </div>
</div>

<!-- Özet Kartlar -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2 text-center">
                <div class="small opacity-75">Toplam Satış</div>
                <div class="fw-bold fs-5"><?= para($toplamSatis) ?></div>
                <div class="small opacity-75"><?= $aktifSatis ?> işlem</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body py-2 text-center">
                <div class="small opacity-75">Toplam Ödenen</div>
                <div class="fw-bold fs-5"><?= para($toplamOdenen) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 <?= $m['toplam_borc']>0?'bg-danger':'bg-success' ?> text-white">
            <div class="card-body py-2 text-center">
                <div class="small opacity-75">Kalan Borç</div>
                <div class="fw-bold fs-5"><?= para($m['toplam_borc']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 bg-info text-white">
            <div class="card-body py-2 text-center">
                <div class="small opacity-75">Ödeme Sayısı</div>
                <div class="fw-bold fs-5"><?= count($odemeler) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Sol: Bilgiler -->
    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Müşteri Bilgileri</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><th class="ps-3 text-muted fw-normal" width="110">Tip</th><td><?= $m['tip']==='kurumsal'?'Kurumsal':'Bireysel' ?></td></tr>
                    <?php if ($m['telefon']): ?><tr><th class="ps-3 text-muted fw-normal">Telefon</th><td><a href="tel:<?= escH($m['telefon']) ?>"><?= escH($m['telefon']) ?></a></td></tr><?php endif; ?>
                    <?php if ($m['telefon2']): ?><tr><th class="ps-3 text-muted fw-normal">Tel 2</th><td><?= escH($m['telefon2']) ?></td></tr><?php endif; ?>
                    <?php if ($m['email']): ?><tr><th class="ps-3 text-muted fw-normal">E-posta</th><td><?= escH($m['email']) ?></td></tr><?php endif; ?>
                    <?php if ($m['tc_no']): ?><tr><th class="ps-3 text-muted fw-normal">TC No</th><td><?= escH($m['tc_no']) ?></td></tr><?php endif; ?>
                    <?php if ($m['vergi_no']): ?><tr><th class="ps-3 text-muted fw-normal">Vergi No</th><td><?= escH($m['vergi_no']) ?></td></tr><?php endif; ?>
                    <?php if ($m['sehir']): ?><tr><th class="ps-3 text-muted fw-normal">Şehir</th><td><?= escH($m['sehir']) ?></td></tr><?php endif; ?>
                    <?php if ($m['adres']): ?><tr><th class="ps-3 text-muted fw-normal">Adres</th><td class="small"><?= escH($m['adres']) ?></td></tr><?php endif; ?>
                    <tr><th class="ps-3 text-muted fw-normal">Kayıt</th><td><?= tarih($m['created_at']) ?></td></tr>
                </table>
                <?php if ($m['notlar']): ?>
                <div class="mx-3 mb-3 mt-1 p-2 bg-light rounded small">
                    <i class="bi bi-sticky text-warning"></i> <?= escH($m['notlar']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ödeme Geçmişi -->
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cash-coin text-success"></i> Ödeme Geçmişi</span>
                <span class="badge bg-secondary"><?= count($odemeler) ?></span>
            </div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
            <?php if (empty($odemeler)): ?>
                <div class="text-center text-muted py-3 small">Ödeme yok</div>
            <?php else: ?>
            <table class="table table-sm mb-0">
                <thead><tr><th>Tarih</th><th>Fatura</th><th class="text-end">Tutar</th><th>Tip</th></tr></thead>
                <tbody>
                <?php foreach ($odemeler as $o): ?>
                <tr>
                    <td class="small"><?= tarih($o['tarih']) ?></td>
                    <td class="small text-muted"><?= escH($o['fatura_no'] ?? '—') ?></td>
                    <td class="text-end text-success fw-bold small"><?= para($o['tutar']) ?></td>
                    <td><small class="badge bg-light text-secondary"><?= ucfirst($o['odeme_tipi']) ?></small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sağ: Satışlar + Ekstre -->
    <div class="col-md-8">
        <!-- Nav tabs -->
        <ul class="nav nav-tabs mb-3" id="musteriTabs">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-satislar">
                    <i class="bi bi-receipt"></i> Satış Geçmişi <span class="badge bg-secondary ms-1"><?= count($satislar) ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ekstre">
                    <i class="bi bi-card-list"></i> Hesap Ekstresi
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Satış Geçmişi -->
            <div class="tab-pane fade show active" id="tab-satislar">
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Fatura No</th><th>Tarih</th><th>Tutar</th><th>Ödenen</th><th>Kalan</th><th>Durum</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($satislar)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Satış kaydı yok</td></tr>
                        <?php else: ?>
                        <?php foreach ($satislar as $s): ?>
                        <?php $renk = $s['durum']==='tamamlandi'?'success':($s['durum']==='iptal'?'danger':'warning'); ?>
                        <tr>
                            <td class="fw-semibold"><?= escH($s['fatura_no']) ?></td>
                            <td><?= tarih($s['tarih']) ?></td>
                            <td><?= para($s['genel_toplam']) ?></td>
                            <td class="text-success"><?= $s['odenen_tutar']>0?para($s['odenen_tutar']):'-' ?></td>
                            <td class="<?= $s['kalan_tutar']>0?'text-danger fw-bold':'' ?>"><?= $s['kalan_tutar']>0?para($s['kalan_tutar']):'-' ?></td>
                            <td><span class="badge bg-<?= $renk ?>"><?= $s['durum']==='tamamlandi'?'Tamamlandı':($s['durum']==='iptal'?'İptal':'Bekliyor') ?></span></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-eye"></i></a>
                                <?php if ($s['kalan_tutar']>0): ?>
                                <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-success py-0"><i class="bi bi-cash-coin"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Ekstre -->
            <div class="tab-pane fade" id="tab-ekstre">
                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-card-list text-primary"></i> Hesap Ekstresi</span>
                        <a href="?id=<?= $id ?>&export=1" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-filetype-csv"></i> İndir
                        </a>
                    </div>
                    <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Tarih</th><th>Açıklama</th><th class="text-end">Borç</th><th class="text-end">Ödeme</th><th class="text-end">Bakiye</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($ekstre)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Hareket yok</td></tr>
                        <?php else: ?>
                        <?php $bakiye = 0; ?>
                        <?php foreach ($ekstre as $e): ?>
                        <?php
                            if ($e['tip']==='borc')  $bakiye += $e['tutar'];
                            else                     $bakiye -= $e['tutar'];
                        ?>
                        <tr class="<?= $e['tip']==='borc'?'':'table-success bg-opacity-25' ?>">
                            <td class="text-nowrap"><?= tarih($e['tarih']) ?></td>
                            <td>
                                <?php if ($e['link']): ?>
                                <a href="<?= $e['link'] ?>"><?= escH($e['aciklama']) ?></a>
                                <?php else: ?>
                                <?= escH($e['aciklama']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-danger"><?= $e['tip']==='borc'?para($e['tutar']):'' ?></td>
                            <td class="text-end text-success"><?= $e['tip']==='odeme'?para($e['tutar']):'' ?></td>
                            <td class="text-end fw-bold <?= $bakiye>0?'text-danger':($bakiye<0?'text-success':'') ?>"><?= para($bakiye) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold table-light">
                            <td colspan="4" class="text-end">KALAN BAKIYE</td>
                            <td class="text-end <?= $m['toplam_borc']>0?'text-danger':'text-success' ?>"><?= para($m['toplam_borc']) ?></td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
