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
$islem = ($_POST['islem'] ?? 'arsivle') === 'aktif' ? 'aktif' : 'arsivle';
if ($id) {
    if ($islem === 'aktif') {
        $pdo->prepare("UPDATE urunler SET aktif=1 WHERE id=?")->execute([$id]);
        logla('urun_aktif', 'urunler', $id, 'Ürün arşivden çıkarıldı');
        flash('basari', 'Ürün arşivden çıkarıldı, yeniden satışa açık.');
    } else {
        $pdo->prepare("UPDATE urunler SET aktif=0 WHERE id=?")->execute([$id]);
        logla('urun_pasif', 'urunler', $id, 'Ürün arşive taşındı');
        flash('basari', 'Ürün arşive taşındı. "Arşiv" filtresinden geri alabilirsiniz.');
    }
}
header('Location: index.php'); exit;
