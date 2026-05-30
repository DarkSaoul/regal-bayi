<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Kasa & Finans';
$pdo = db();

$bugun = date('Y-m-d');
$buAy  = date('Y-m');

$kasaBakiye = $pdo->query("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE -tutar END),0) FROM kasa_hareketleri")->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) FROM kasa_hareketleri WHERE tip='giris' AND tarih=?");
$s->execute([$bugun]); $bugunGiris = $s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) FROM kasa_hareketleri WHERE tip='cikis' AND tarih=?");
$s->execute([$bugun]); $bugunCikis = $s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(tutar),0) FROM kasa_hareketleri WHERE tip='giris' AND DATE_FORMAT(tarih,'%Y-%m')=?");
$s->execute([$buAy]); $aylikGiris = $s->fetchColumn();
$bekleyenBorc = $pdo->query("SELECT COALESCE(SUM(kalan_tutar),0) FROM satislar WHERE kalan_tutar>0 AND durum='bekliyor'")->fetchColumn();

// Son kasa hareketleri
$hareketler = $pdo->query("SELECT k.*, ku.ad_soyad AS kullanici FROM kasa_hareketleri k LEFT JOIN kullanicilar ku ON k.kullanici_id=ku.id ORDER BY k.created_at DESC LIMIT 30")->fetchAll();

// Vadesi gelen tahsilatlar
$vadeli = $pdo->query("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s JOIN musteriler m ON s.musteri_id=m.id WHERE s.kalan_tutar>0 AND s.durum='bekliyor' ORDER BY s.tarih LIMIT 10")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-cash-stack text-primary"></i> Kasa & Finans</h4>
    <div class="d-flex gap-2">
        <a href="kasa_hareketi.php" class="btn btn-outline-primary"><i class="bi bi-plus-circle"></i> Kasa Hareketi</a>
        <a href="tahsilat.php" class="btn btn-success"><i class="bi bi-cash-coin"></i> Tahsilat Al</a>
    </div>
</div>

<!-- Kartlar -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body">
                <div class="small">Kasa Bakiyesi</div>
                <div class="fw-bold fs-4"><?= para($kasaBakiye) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <div class="small">Bugün Giriş</div>
                <div class="fw-bold fs-4"><?= para($bugunGiris) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-danger text-white">
            <div class="card-body">
                <div class="small">Bugün Çıkış</div>
                <div class="fw-bold fs-4"><?= para($bugunCikis) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body">
                <div class="small">Bekleyen Tahsilat</div>
                <div class="fw-bold fs-4"><?= para($bekleyenBorc) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Kasa Hareketleri -->
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Son Kasa Hareketleri</div>
            <div class="card-body p-0">
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Tarih</th><th>Tip</th><th>Tutar</th><th>Kategori</th><th>Açıklama</th><th>Kullanıcı</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($hareketler)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Hareket yok</td></tr>
                <?php else: ?>
                <?php foreach ($hareketler as $h): ?>
                <tr>
                    <td><?= tarih($h['tarih']) ?></td>
                    <td><span class="badge bg-<?= $h['tip']==='giris'?'success':'danger' ?>"><?= $h['tip']==='giris'?'Giriş':'Çıkış' ?></span></td>
                    <td class="fw-bold <?= $h['tip']==='giris'?'text-success':'text-danger' ?>"><?= para($h['tutar']) ?></td>
                    <td><?= escH($h['kategori']??'-') ?></td>
                    <td><?= escH($h['aciklama']??'-') ?></td>
                    <td><?= escH($h['kullanici']??'-') ?></td>
                    <td>
                        <?php if (!in_array($h['kategori'],['Satış','Tahsilat']) && ($_SESSION['rol']??'')==='yonetici'): ?>
                        <form method="post" action="kasa_sil.php" class="d-inline"
                              onsubmit="return confirm('Bu hareketi silmek istediğinize emin misiniz?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger btn-sm py-0 px-1">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
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
    <!-- Bekleyen tahsilatlar -->
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold text-danger">
                <i class="bi bi-exclamation-circle"></i> Bekleyen Tahsilatlar
            </div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Müşteri</th><th>Fatura</th><th>Kalan</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($vadeli)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Bekleyen yok</td></tr>
                <?php else: ?>
                <?php foreach ($vadeli as $v): ?>
                <tr>
                    <td><?= escH($v['musteri_adi']) ?></td>
                    <td><small><?= escH($v['fatura_no']) ?></small></td>
                    <td class="text-danger fw-bold"><?= para($v['kalan_tutar']) ?></td>
                    <td><a href="tahsilat.php?satis_id=<?= $v['id'] ?>" class="btn btn-xs btn-outline-success btn-sm py-0 px-1"><i class="bi bi-cash"></i></a></td>
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
