<?php
// .env dosyası hiç yoksa (ilk kurulum) — install.php hariç her yerden oraya yönlendir
$_envFile = __DIR__ . '/../.env';
if (!file_exists($_envFile) && !str_ends_with(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/install.php')) {
    $_taban = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . $_taban . '/install.php');
    exit;
}

// .env dosyasından yapılandırma oku
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
define('DB_PORT',    $_ENV['DB_PORT']    ?? '3306');
define('DB_USER',    $_ENV['DB_USER']    ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'regal_bayi');
define('APP_ENV',    $_ENV['APP_ENV']    ?? 'production');
define('SESSION_TIMEOUT', (int)($_ENV['SESSION_TIMEOUT'] ?? 1800));
define('BACKUP_ENCRYPTION_KEY', $_ENV['YEDEK_SIFRELEME_ANAHTARI'] ?? '');

// mysqldump/mysql araçlarının yolu — Linux (XAMPP) ve Windows (XAMPP) için otomatik tespit,
// .env üzerinden (MYSQLDUMP_YOLU/MYSQL_YOLU) elle de geçersiz kılınabilir.
function mysqlAracYoluBul(string $arac): string {
    $windowsAday = ['C:\\xampp\\mysql\\bin\\' . $arac . '.exe'];
    $linuxAday   = ['/opt/lampp/bin/' . $arac, '/usr/bin/' . $arac, '/usr/local/bin/' . $arac];
    foreach ((PHP_OS_FAMILY === 'Windows' ? $windowsAday : $linuxAday) as $yol) {
        if (is_file($yol)) return $yol;
    }
    return $arac; // PATH üzerinden bulunmaya çalışılır (son çare)
}
define('MYSQLDUMP_PATH', $_ENV['MYSQLDUMP_YOLU'] ?? mysqlAracYoluBul('mysqldump'));
define('MYSQL_PATH', $_ENV['MYSQL_YOLU'] ?? mysqlAracYoluBul('mysql'));

function db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // Geliştirme ortamında detay göster, üretimde gizle
            if (APP_ENV === 'development') {
                die('<div style="font-family:sans-serif;color:red;padding:20px">
                    <h3>Veritabanı Bağlantı Hatası</h3>
                    <p>' . htmlspecialchars($e->getMessage()) . '</p>
                </div>');
            }
            error_log('DB Bağlantı Hatası: ' . $e->getMessage());
            die('<div style="font-family:sans-serif;padding:20px">
                Sistem geçici olarak kullanılamıyor. Lütfen yönetici ile iletişime geçin.
            </div>');
        }
    }
    return $pdo;
}
