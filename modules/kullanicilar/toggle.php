<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
csrfVerify();
$pdo = db();
$id = (int)($_POST['id'] ?? 0);
if ($id && $id != $_SESSION['kullanici_id']) {
    $pdo->prepare("UPDATE kullanicilar SET aktif = 1-aktif WHERE id=?")->execute([$id]);
}
flash('basari', 'Kullanıcı durumu güncellendi.');
header('Location: index.php'); exit;
