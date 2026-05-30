<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);

$dosya   = basename($_GET['dosya'] ?? '');
$yedekDir = __DIR__ . '/../../backups/';
$yol     = $yedekDir . $dosya;

if (!$dosya || !file_exists($yol) || !str_ends_with($dosya, '.sql')) {
    flash('hata', 'Dosya bulunamadı.');
    header('Location: index.php'); exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $dosya . '"');
header('Content-Length: ' . filesize($yol));
readfile($yol);
exit;
