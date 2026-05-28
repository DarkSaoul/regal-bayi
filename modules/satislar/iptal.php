<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
csrfVerify();
$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$pdo->beginTransaction();
try {
    // FOR UPDATE ile satırı kilitle — çift iptal önleme
    $satis = $pdo->prepare("SELECT * FROM satislar WHERE id=? AND durum='tamamlandi' FOR UPDATE");
    $satis->execute([$id]); $satis = $satis->fetch();
    if (!$satis) {
        $pdo->rollBack();
        flash('hata', 'Satış bulunamadı veya zaten iptal edilmiş.');
        header('Location: detay.php?id=' . $id); exit;
    }
    $kalemler = $pdo->prepare("SELECT * FROM satis_kalemleri WHERE satis_id=?");
    $kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();
    foreach ($kalemler as $k) {
        stokGuncelle($k['urun_id'], $k['miktar'], 'iade_giris', $satis['fatura_no'], 'İptal iadesi');
    }
    $pdo->prepare("UPDATE satislar SET durum='iptal' WHERE id=?")->execute([$id]);
    // Müşteri borcundan kalan tutarı düş
    if ($satis['musteri_id'] && $satis['kalan_tutar'] > 0) {
        $pdo->prepare("UPDATE musteriler SET toplam_borc = GREATEST(0, toplam_borc - ?) WHERE id=?")
            ->execute([$satis['kalan_tutar'], $satis['musteri_id']]);
    }
    $pdo->commit();
    flash('basari', 'Satış iptal edildi, stok iade edildi.');
} catch (Exception $e) {
    $pdo->rollBack();
    flash('hata', 'İptal sırasında hata: ' . $e->getMessage());
}
header('Location: detay.php?id=' . $id); exit;
