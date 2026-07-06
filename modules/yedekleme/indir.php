<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);

$dosya   = basename($_GET['dosya'] ?? '');
$yedekDir = __DIR__ . '/../../backups/';
$yol     = $yedekDir . $dosya;

if (!$dosya || !file_exists($yol) || !preg_match('/\.(sql|sql\.gz|sql\.gz\.enc|sql\.enc|zip)$/', $dosya)) {
    flash('hata', 'Dosya bulunamadı.');
    header('Location: index.php'); exit;
}
logla('yedek_indirildi', 'yedekleme', 0, $dosya);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $dosya . '"');
header('Content-Length: ' . filesize($yol));
readfile($yol);
exit;
