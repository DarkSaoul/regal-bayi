<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Tahsilat Al';
$pdo = db();

$satis_id = (int)($_GET['satis_id'] ?? 0);
$satis = null;
$musteri_id = 0;
if ($satis_id) {
    $stmt = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id WHERE s.id=?");
    $stmt->execute([$satis_id]);
    $satis = $stmt->fetch();
    $musteri_id = (int)($satis['musteri_id'] ?? 0);
}

// Müşteri belirlenmişse sadece o müşterinin vadeli satışları
if ($musteri_id) {
    $stmt = $pdo->prepare("SELECT s.id, s.fatura_no, s.kalan_tutar, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s JOIN musteriler m ON s.musteri_id=m.id WHERE s.kalan_tutar>0 AND s.durum='bekliyor' AND s.musteri_id=? ORDER BY s.tarih");
    $stmt->execute([$musteri_id]);
    $vadeli_satislar = $stmt->fetchAll();
} else {
    $vadeli_satislar = $pdo->query("SELECT s.id, s.fatura_no, s.kalan_tutar, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s JOIN musteriler m ON s.musteri_id=m.id WHERE s.kalan_tutar>0 AND s.durum='bekliyor' ORDER BY s.tarih")->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $sid    = (int)$_POST['satis_id'];
    $tutar  = (float)$_POST['tutar'];
    $tip    = $_POST['odeme_tipi'] ?? 'nakit';
    $tarih  = $_POST['tarih'] ?: date('Y-m-d');
    $aciklama = trim($_POST['aciklama'] ?? '');

    $satisRow = $pdo->prepare("SELECT * FROM satislar WHERE id=?");
    $satisRow->execute([$sid]); $satisRow = $satisRow->fetch();

    if ($satisRow && $tutar > 0) {
        $pdo->beginTransaction();

        // Taksitli satışsa bir sonraki taksit numarasını bul
        $taksit_no = null;
        if ($satisRow['odeme_tipi'] === 'taksitli' && $satisRow['taksit_sayisi'] > 1) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM odemeler WHERE satis_id=? AND taksit_no IS NOT NULL");
            $stmt->execute([$sid]);
            $taksit_no = (int)$stmt->fetchColumn() + 1;
        }

        $pdo->prepare("INSERT INTO odemeler (satis_id,musteri_id,tarih,tutar,odeme_tipi,taksit_no,aciklama,kullanici_id) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$sid, $satisRow['musteri_id'], $tarih, $tutar, $tip, $taksit_no, $aciklama ?: 'Tahsilat - '.$satisRow['fatura_no'], $_SESSION['kullanici_id']]);
        // Fazla ödeme yapılmasını önle
        $gercek_tutar = min($tutar, $satisRow['kalan_tutar']);
        $yeni_kalan   = round(max(0, $satisRow['kalan_tutar'] - $tutar), 2);
        $yeni_odenen  = round($satisRow['odenen_tutar'] + $tutar, 2);
        $yeni_durum   = $yeni_kalan <= 0 ? 'tamamlandi' : 'bekliyor';
        $pdo->prepare("UPDATE satislar SET odenen_tutar=?, kalan_tutar=?, durum=? WHERE id=?")->execute([$yeni_odenen, $yeni_kalan, $yeni_durum, $sid]);
        // Borçtan yalnızca gerçekten kalan kadarını düş, negatife izin verme
        if ($satisRow['musteri_id'] && $gercek_tutar > 0) {
            $pdo->prepare("UPDATE musteriler SET toplam_borc = GREATEST(0, toplam_borc - ?) WHERE id=?")
                ->execute([$gercek_tutar, $satisRow['musteri_id']]);
        }
        $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?)")
            ->execute([$tarih, 'giris', $tutar, 'Tahsilat: '.$satisRow['fatura_no'], 'Tahsilat', $_SESSION['kullanici_id']]);
        $pdo->commit();
        flash('basari', para($tutar) . ' tahsilat alındı.');
        header('Location: ' . BASE_URL . '/modules/satislar/detay.php?id=' . $sid); exit;
    }
    flash('hata', 'Geçersiz veri.'); header('Location: index.php'); exit;
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-cash-coin text-success"></i> Tahsilat Al</h4>
</div>

<?php if ($satis && $musteri_id): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="max-width:550px">
    <i class="bi bi-person-fill fs-5"></i>
    <span>
        <strong><?= escH($satis['musteri_adi']) ?></strong> adına tahsilat —
        yalnızca bu müşterinin vadeli satışları listeleniyor.
    </span>
</div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:550px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label fw-semibold">Satış / Fatura</label>
            <select name="satis_id" class="form-select" required>
                <option value="">Seçin...</option>
                <?php foreach ($vadeli_satislar as $v): ?>
                <option value="<?= $v['id'] ?>" <?= $v['id']==$satis_id?'selected':'' ?>>
                    <?= escH($v['fatura_no']) ?> — <?= escH($v['musteri_adi']) ?> — Kalan: <?= para($v['kalan_tutar']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tahsilat Tutarı (₺) <span class="text-danger">*</span></label>
            <input type="number" name="tutar" class="form-control" step="0.01" min="0.01" required
                   value="<?= $satis ? $satis['kalan_tutar'] : '' ?>">
            <?php if ($satis): ?>
            <div class="form-text">Maksimum: <?= para($satis['kalan_tutar']) ?></div>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Ödeme Tipi</label>
            <select name="odeme_tipi" class="form-select">
                <option value="nakit">Nakit</option>
                <option value="kredi_karti">Kredi Kartı</option>
                <option value="havale">Havale / EFT</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Açıklama</label>
            <input type="text" name="aciklama" class="form-control">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Tahsilatı Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
