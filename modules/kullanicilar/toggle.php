<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
csrfVerify();
$pdo = db();
$id = (int)($_POST['id'] ?? 0);
if ($id && $id != $_SESSION['kullanici_id']) {
    // Son aktif yönetici pasife alınamaz — sistem kilitlenir
    $k = $pdo->prepare("SELECT rol, aktif FROM kullanicilar WHERE id=?");
    $k->execute([$id]); $k = $k->fetch();
    if ($k && $k['rol'] === 'yonetici' && $k['aktif']) {
        $sayi = (int)$pdo->query("SELECT COUNT(*) FROM kullanicilar WHERE rol='yonetici' AND aktif=1")->fetchColumn();
        if ($sayi <= 1) {
            flash('hata', 'Sistemdeki son aktif yönetici pasife alınamaz.');
            header('Location: index.php'); exit;
        }
    }
    $pdo->prepare("UPDATE kullanicilar SET aktif = 1-aktif WHERE id=?")->execute([$id]);
    logla('kullanici_toggle', 'kullanicilar', $id, 'Aktiflik durumu değiştirildi');
}
flash('basari', 'Kullanıcı durumu güncellendi.');
header('Location: index.php'); exit;
