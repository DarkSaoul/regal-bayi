<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$pdo = db();
$id = (int)($_GET['id'] ?? 0);

// Stok hareketi varsa silme
$bagli = $pdo->prepare("SELECT COUNT(*) FROM stok_hareketleri WHERE tedarikci_id=?");
$bagli->execute([$id]);
if ((int)$bagli->fetchColumn() > 0) {
    flash('hata', 'Bu tedarikçiye ait stok hareketleri var, silinemez.');
} else {
    $pdo->prepare("DELETE FROM tedarikciler WHERE id=?")->execute([$id]);
    flash('basari', 'Tedarikçi silindi.');
}
header('Location: index.php'); exit;
