<?php
define('BASE_URL', '/regal');
session_start();
if (!empty($_SESSION['kullanici_id'])) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
} else {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
}
exit;
