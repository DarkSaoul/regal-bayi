<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$m = $pdo->prepare("SELECT * FROM musteriler WHERE id=?");
$m->execute([$id]);
$m = $m->fetch();
if (!$m) { flash('hata','Müşteri bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = $m['ad'] . ' ' . $m['soyad'];

$satislar = $pdo->prepare("SELECT * FROM satislar WHERE musteri_id=? ORDER BY tarih DESC");
$satislar->execute([$id]);
$satislar = $satislar->fetchAll();


require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between">
    <div>
        <h4><i class="bi bi-person-circle text-primary"></i> <?= escH($m['ad'].' '.($m['soyad']??'')) ?></h4>
        <?php if ($m['firma_adi']): ?><span class="text-muted"><?= escH($m['firma_adi']) ?></span><?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a href="duzenle.php?id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Düzenle</a>
        <a href="<?= BASE_URL ?>/modules/satislar/yeni.php?musteri_id=<?= $id ?>" class="btn btn-primary"><i class="bi bi-receipt"></i> Yeni Satış</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Müşteri Bilgileri</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th>Tip</th><td><?= $m['tip']==='kurumsal'?'Kurumsal':'Bireysel' ?></td></tr>
                    <tr><th>Telefon</th><td><?= escH($m['telefon']??'-') ?></td></tr>
                    <tr><th>Telefon 2</th><td><?= escH($m['telefon2']??'-') ?></td></tr>
                    <tr><th>E-posta</th><td><?= escH($m['email']??'-') ?></td></tr>
                    <tr><th>TC / Vergi</th><td><?= escH(($m['tc_no']??'-').' / '.($m['vergi_no']??'-')) ?></td></tr>
                    <tr><th>Şehir</th><td><?= escH($m['sehir']??'-') ?></td></tr>
                    <tr><th>Adres</th><td><?= escH($m['adres']??'-') ?></td></tr>
                    <tr><th>Toplam Borç</th><td class="<?= $m['toplam_borc']>0?'text-danger fw-bold':'' ?>"><?= para($m['toplam_borc']) ?></td></tr>
                </table>
                <?php if ($m['notlar']): ?>
                <div class="mt-2 p-2 bg-light rounded small"><?= escH($m['notlar']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Satış Geçmişi (<?= count($satislar) ?>)</div>
            <div class="card-body p-0">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Fatura No</th><th>Tarih</th><th>Tutar</th><th>Kalan</th><th>Durum</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($satislar)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Satış kaydı yok</td></tr>
                <?php else: ?>
                <?php foreach ($satislar as $s): ?>
                <tr>
                    <td><?= escH($s['fatura_no']) ?></td>
                    <td><?= tarih($s['tarih']) ?></td>
                    <td><?= para($s['genel_toplam']) ?></td>
                    <td class="<?= $s['kalan_tutar']>0?'text-danger':'' ?>"><?= $s['kalan_tutar']>0?para($s['kalan_tutar']):'-' ?></td>
                    <td><span class="badge bg-<?= $s['durum']==='tamamlandi'?'success':($s['durum']==='iptal'?'danger':'warning') ?>"><?= ucfirst($s['durum']) ?></span></td>
                    <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
