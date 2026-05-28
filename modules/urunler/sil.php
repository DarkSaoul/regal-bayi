<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$pdo->prepare("UPDATE urunler SET aktif=0 WHERE id=?")->execute([$id]);
flash('basari', 'Ürün pasife alındı.');
header('Location: index.php'); exit;
