<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Tahsilat Al';
$pdo = db();

$satis_id = (int)($_GET['satis_id'] ?? 0);
$satis = null;
$musteri_id = (int)($_GET['musteri_id'] ?? 0); // müşteri detayından "Tahsilat Al" kısayolu
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
    $tip    = in_array($_POST['odeme_tipi'] ?? '', ['nakit','kredi_karti','havale']) ? $_POST['odeme_tipi'] : 'nakit';
    $tarih  = $_POST['tarih'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || strtotime($tarih) === false) $tarih = date('Y-m-d');
    $aciklama = trim($_POST['aciklama'] ?? '');

    $pdo->beginTransaction();
    try {
        // Satırı kilitle — eşzamanlı çift tahsilat önlenir
        $satisRow = $pdo->prepare("SELECT * FROM satislar WHERE id=? FOR UPDATE");
        $satisRow->execute([$sid]); $satisRow = $satisRow->fetch();

        if (!$satisRow || $tutar <= 0) {
            throw new RuntimeException('Geçersiz veri.');
        }
        if ($satisRow['durum'] !== 'bekliyor' || $satisRow['kalan_tutar'] <= 0) {
            throw new RuntimeException('Bu satış için tahsilat alınamaz (iptal edilmiş veya tamamlanmış).');
        }

        // Fazla ödeme kabul edilmez — kalanın üzeri para üstüdür, kayda girmez
        $tutar = min(round($tutar, 2), (float)$satisRow['kalan_tutar']);

        $pdo->prepare("INSERT INTO odemeler (satis_id,musteri_id,tarih,tutar,odeme_tipi,taksit_no,aciklama,kullanici_id) VALUES (?,?,?,?,?,NULL,?,?)")
            ->execute([$sid, $satisRow['musteri_id'], $tarih, $tutar, $tip, $aciklama ?: 'Tahsilat - '.$satisRow['fatura_no'], $_SESSION['kullanici_id']]);
        $odeme_id = (int)$pdo->lastInsertId();

        $yeni_kalan  = round(max(0, $satisRow['kalan_tutar'] - $tutar), 2);
        $yeni_odenen = round($satisRow['odenen_tutar'] + $tutar, 2);
        $yeni_durum  = $yeni_kalan <= 0 ? 'tamamlandi' : 'bekliyor';
        $pdo->prepare("UPDATE satislar SET odenen_tutar=?, kalan_tutar=?, durum=? WHERE id=?")
            ->execute([$yeni_odenen, $yeni_kalan, $yeni_durum, $sid]);

        // Taksit planını güncelle: ödeme, açık taksitleri vade sırasıyla kapatır
        $son_taksit_no = null;
        $acikTaksitler = $pdo->prepare("SELECT id, taksit_no, tutar FROM taksit_plani WHERE satis_id=? AND odendi=0 ORDER BY taksit_no FOR UPDATE");
        $acikTaksitler->execute([$sid]);
        $kalanOdeme = $tutar;
        foreach ($acikTaksitler->fetchAll() as $tp) {
            if ($kalanOdeme < $tp['tutar'] - 0.005) break;
            $pdo->prepare("UPDATE taksit_plani SET odendi=1, odeme_tarihi=?, odeme_id=? WHERE id=?")
                ->execute([$tarih, $odeme_id, $tp['id']]);
            $kalanOdeme = round($kalanOdeme - $tp['tutar'], 2);
            $son_taksit_no = (int)$tp['taksit_no'];
        }
        // Ödemeyi kapattığı son taksit numarasıyla ilişkilendir
        if ($son_taksit_no !== null) {
            $pdo->prepare("UPDATE odemeler SET taksit_no=? WHERE id=?")->execute([$son_taksit_no, $odeme_id]);
        }

        // Müşteri borcunu açık satışlardan yeniden hesapla
        musteriBorcuYenile($satisRow['musteri_id'] ? (int)$satisRow['musteri_id'] : null);

        // Nakit → kasa hesabı, kart/havale → banka hesabı
        $hesap = $tip === 'nakit' ? 'kasa' : 'banka';
        $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,hesap,tutar,aciklama,kategori,odeme_id,kullanici_id) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$tarih, 'giris', $hesap, $tutar, 'Tahsilat: '.$satisRow['fatura_no'], 'Tahsilat', $odeme_id, $_SESSION['kullanici_id']]);
        $pdo->commit();
        logla('tahsilat', 'finans', $sid, 'Fatura: '.$satisRow['fatura_no'].' | '.para($tutar));
        flash('basari', para($tutar) . ' tahsilat alındı.');
        header('Location: ' . BASE_URL . '/modules/satislar/detay.php?id=' . $sid); exit;
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        flash('hata', $e->getMessage());
        header('Location: index.php'); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('hata', 'Tahsilat sırasında hata: ' . $e->getMessage());
        header('Location: index.php'); exit;
    }
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
            <?php
            // ?tutar= ile önerilen bir tutar geldiyse (örn. erken ödeme hesaplayıcısından) onu kullan,
            // kalan tutarı aşamaz.
            $onerilenTutar = $satis && isset($_GET['tutar']) && is_numeric($_GET['tutar'])
                ? min((float)$_GET['tutar'], (float)$satis['kalan_tutar']) : ($satis ? $satis['kalan_tutar'] : '');
            ?>
            <input type="number" name="tutar" class="form-control" step="0.01" min="0.01" required
                   <?= $satis ? 'max="' . $satis['kalan_tutar'] . '"' : '' ?>
                   value="<?= $onerilenTutar ?>">
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
