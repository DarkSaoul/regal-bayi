<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
startSecureSession();
logla('cikis', 'auth', (int)($_SESSION['kullanici_id'] ?? 0), ($_SESSION['ad_soyad'] ?? '') . ' çıkış yaptı');
session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/modules/auth/login.php');
exit;
