<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
csrfVerify();
$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$pdo->beginTransaction();
try {
    // FOR UPDATE ile satırı kilitle — çift iptal önleme.
    // Hem tamamlanmış hem bekleyen (vadeli/taksitli) satışlar iptal edilebilir.
    $satis = $pdo->prepare("SELECT * FROM satislar WHERE id=? AND durum IN ('tamamlandi','bekliyor') FOR UPDATE");
    $satis->execute([$id]); $satis = $satis->fetch();
    if (!$satis) {
        $pdo->rollBack();
        flash('hata', 'Satış bulunamadı veya zaten iptal edilmiş.');
        header('Location: detay.php?id=' . $id); exit;
    }
    $kalemler = $pdo->prepare("SELECT * FROM satis_kalemleri WHERE satis_id=?");
    $kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();
    // Ön siparişte stok hiç düşülmediyse iade girişi yapılmaz;
    // kısmi iadesi yapılmış kalemlerde yalnızca kalan miktar geri alınır.
    if ($satis['stok_dusuldu']) {
        foreach ($kalemler as $k) {
            $kalan = (int)$k['miktar'] - (int)$k['iade_miktar'];
            if ($kalan > 0) {
                stokGuncelle($k['urun_id'], $kalan, 'iade_giris', $satis['fatura_no'], 'İptal iadesi');
            }
        }
        // Satılmış seri no'ları depoya geri al
        $pdo->prepare("UPDATE seri_numaralari SET durum='stokta', satis_id=NULL WHERE satis_id=? AND durum='satildi'")
            ->execute([$id]);
    }

    $pdo->prepare("UPDATE satislar SET durum='iptal' WHERE id=?")->execute([$id]);

    // Ödenmemiş taksitleri iptal et (planı sil) — gecikmiş taksit sayaçlarını kirletmesin
    $pdo->prepare("DELETE FROM taksit_plani WHERE satis_id=? AND odendi=0")->execute([$id]);

    // Müşteriye/nakite iade: ödenen tutar varsa kasadan çıkış yaz
    if ($satis['odenen_tutar'] > 0) {
        $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?)")
            ->execute([date('Y-m-d'), 'cikis', $satis['odenen_tutar'],
                       'Satış iptali iadesi: ' . $satis['fatura_no'], 'İade', $_SESSION['kullanici_id']]);
    }

    // Müşteri borcunu açık satışlardan yeniden hesapla
    musteriBorcuYenile($satis['musteri_id'] ? (int)$satis['musteri_id'] : null);

    $pdo->commit();
    logla('satis_iptal', 'satislar', $id, 'Fatura: ' . $satis['fatura_no']
        . ($satis['odenen_tutar'] > 0 ? ' | İade: ' . para($satis['odenen_tutar']) : ''));
    flash('basari', 'Satış iptal edildi, stok iade edildi.'
        . ($satis['odenen_tutar'] > 0 ? ' Kasadan ' . para($satis['odenen_tutar']) . ' iade çıkışı yazıldı.' : ''));
} catch (Exception $e) {
    $pdo->rollBack();
    flash('hata', 'İptal sırasında hata: ' . $e->getMessage());
}
header('Location: detay.php?id=' . $id); exit;
