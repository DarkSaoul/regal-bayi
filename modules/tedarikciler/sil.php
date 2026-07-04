<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}
csrfVerify();
$pdo = db();
$id = (int)($_POST['id'] ?? 0);

$t = $pdo->prepare("SELECT toplam_borc FROM tedarikciler WHERE id=?");
$t->execute([$id]); $borc = $t->fetchColumn();

$stokVar   = (int)$pdo->query("SELECT COUNT(*) FROM stok_hareketleri WHERE tedarikci_id=" . (int)$id)->fetchColumn();
$odemeVar  = (int)$pdo->query("SELECT COUNT(*) FROM tedarikci_odemeleri WHERE tedarikci_id=" . (int)$id)->fetchColumn();
$borcVar   = (int)$pdo->query("SELECT COUNT(*) FROM tedarikci_borclar WHERE tedarikci_id=" . (int)$id)->fetchColumn();
$siparisVar= (int)$pdo->query("SELECT COUNT(*) FROM tedarikci_siparisleri WHERE tedarikci_id=" . (int)$id)->fetchColumn();

if ($borc === false) {
    flash('hata', 'Tedarikçi bulunamadı.');
} elseif ((float)$borc > 0) {
    flash('hata', 'Bu tedarikçinin açık borcu var, silinemez. Önce borcu kapatın.');
} elseif ($stokVar > 0 || $odemeVar > 0 || $borcVar > 0 || $siparisVar > 0) {
    flash('hata', 'Bu tedarikçiye ait geçmiş kayıtlar (stok/ödeme/borç/sipariş) var, silinemez.');
} elseif ($id) {
    $pdo->prepare("DELETE FROM tedarikciler WHERE id=?")->execute([$id]);
    logla('tedarikci_sil', 'tedarikciler', $id, 'Tedarikçi silindi');
    flash('basari', 'Tedarikçi silindi.');
}
header('Location: index.php'); exit;
