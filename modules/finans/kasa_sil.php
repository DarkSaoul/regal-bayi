<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
csrfVerify();
$id  = (int)($_POST['id'] ?? 0);
$pdo = db();
$h   = $pdo->prepare("SELECT k.*, (SELECT sistem FROM kasa_kategoriler WHERE ad=k.kategori) AS kategori_sistem FROM kasa_hareketleri k WHERE k.id=?");
$h->execute([$id]); $h = $h->fetch();
if ($h && !$h['kategori_sistem']) {
    if ($h['belge'] && is_file(__DIR__ . '/../../uploads/kasa/' . basename($h['belge']))) {
        @unlink(__DIR__ . '/../../uploads/kasa/' . basename($h['belge']));
    }
    $pdo->prepare("DELETE FROM kasa_hareketleri WHERE id=?")->execute([$id]);
    logla('kasa_sil', 'finans', $id, $h['tip'] . ' | ' . para($h['tutar']) . ' | ' . $h['aciklama']);
    flash('basari', 'Kasa hareketi silindi.');
} else {
    flash('hata', 'Satış ve tahsilat kaynaklı hareketler silinemez.');
}
header('Location: index.php'); exit;
