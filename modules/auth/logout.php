<?php
define('BASE_URL', '/regal');
session_start();
session_destroy();
header('Location: ' . BASE_URL . '/modules/auth/login.php');
exit;
