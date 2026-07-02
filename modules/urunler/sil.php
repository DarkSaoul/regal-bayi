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
if ($id) {
    $pdo->prepare("UPDATE urunler SET aktif=0 WHERE id=?")->execute([$id]);
    logla('urun_pasif', 'urunler', $id, 'Ürün pasife alındı');
    flash('basari', 'Ürün pasife alındı.');
}
header('Location: index.php'); exit;
