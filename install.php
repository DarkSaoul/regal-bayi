<?php
// ══════════════════════════════════════════════════════════════
// Regal Bayi Yönetim Sistemi — Kurulum Sihirbazı
// phpMyAdmin gerektirmez: veritabanı bağlantısı + oluşturma + şema/migration
// uygulaması + ilk yönetici şifresi tek ekrandan yapılır.
// ══════════════════════════════════════════════════════════════
error_reporting(E_ALL);
ini_set('display_errors', '1');

$envYolu = __DIR__ . '/.env';
if (file_exists($envYolu)) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Kurulum</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"></head>'
        . '<body class="d-flex align-items-center justify-content-center" style="height:100vh"><div class="alert alert-warning text-center" style="max-width:500px">'
        . '<h5>Sistem zaten kurulu</h5><p>Bir <code>.env</code> dosyası bulundu. Sihirbazı tekrar çalıştırmak için önce bu dosyayı silin.</p>'
        . '<a href="modules/auth/login.php" class="btn btn-primary btn-sm">Giriş Sayfasına Git</a></div></body></html>');
}

session_start();

// Kurulumda uygulanacak dosyalar — GERÇEK bağımlılık sırasına göre elle sıralanmıştır
// (dosya adlarının alfabetik sırası, oluşturulma/bağımlılık sırasıyla HER ZAMAN uyuşmaz —
// örn. "kategoriler" migrasyonu "urunler"den önce gelmelidir; bu yüzden burada sabit liste kullanılır).
const KURULUM_DOSYALARI = [
    'sql/regal_bayi.sql',
    'sql/migrasyon_2026-07-02.sql',
    'sql/migrasyon_2026-07-03_tedarikci.sql',
    'sql/migrasyon_2026-07-06_tedarikci_odemeleri.sql',
    'sql/migrasyon_2026-07-04_urunler.sql',
    'sql/migrasyon_2026-07-04_kategoriler.sql',
    'sql/migrasyon_2026-07-04_fiyat_grubu.sql',
    'sql/migrasyon_2026-07-05_tesir.sql',
    'sql/migrasyon_2026-07-05_sayim.sql',
    'sql/migrasyon_2026-07-05_musteriler.sql',
    'sql/migrasyon_2026-07-05_satislar.sql',
    'sql/migrasyon_2026-07-05_teslimat_servis.sql',
    'sql/migrasyon_2026-07-06_finans.sql',
    'sql/migrasyon_2026-07-06_taksit.sql',
    'sql/migrasyon_2026-07-06_ayarlar.sql',
    'sql/migrasyon_2026-07-06_sistem_kontrol.sql',
    'sql/migrasyon_2026-07-06_yedekleme.sql',
    'sql/migrasyon_2026-07-06_kullanicilar.sql',
    'sql/migrasyon_2026-07-06_git_guncelleme.sql',
];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function sihirbazBaslik(string $baslik): void {
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Kurulum — ' . h($baslik) . '</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">'
        . '<style>body{background:#f0f4f8;padding:40px 15px}.kurulum-kart{max-width:640px;margin:0 auto}</style></head><body>'
        . '<div class="kurulum-kart"><div class="text-center mb-4"><i class="bi bi-shop-window text-primary" style="font-size:2.5rem"></i>'
        . '<h4 class="fw-bold mt-2">Regal Bayi — Kurulum Sihirbazı</h4></div>'
        . '<div class="card shadow-sm"><div class="card-header bg-white fw-semibold">' . h($baslik) . '</div><div class="card-body">';
}
function sihirbazAlt(): void { echo '</div></div></div></body></html>'; }

$adim = $_POST['adim'] ?? 'baslangic';
$hata = '';

// ── Adım: Veritabanı bilgilerini al ve kurulumu uygula ────────
if ($adim === 'kurulum_yap' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = trim($_POST['db_port'] ?? '3306');
    $user = trim($_POST['db_user'] ?? 'root');
    $pass = $_POST['db_pass'] ?? '';
    $name = trim($_POST['db_name'] ?? 'regal_bayi');
    $olustur = isset($_POST['db_olustur']);

    if (!$host || !$user || !$name) {
        $hata = 'Sunucu adresi, kullanıcı adı ve veritabanı adı zorunludur.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        $hata = 'Veritabanı adı yalnızca harf, rakam ve alt çizgi içerebilir.';
    } else {
        try {
            // Önce sunucuya (DB belirtmeden) bağlan
            $pdoSunucu = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $varMi = $pdoSunucu->query("SHOW DATABASES LIKE " . $pdoSunucu->quote($name))->fetchColumn();
            if (!$varMi) {
                if (!$olustur) {
                    throw new Exception("\"$name\" adında bir veritabanı bulunamadı. Otomatik oluşturma seçeneğini işaretleyin veya veritabanını önceden oluşturun.");
                }
                $pdoSunucu->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            }
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Şema + migration dosyalarını sırayla uygula
            $uygulanan = [];
            foreach (KURULUM_DOSYALARI as $goreliYol) {
                $tamYol = __DIR__ . '/' . $goreliYol;
                if (!is_file($tamYol)) continue; // bazı kurulumlarda tüm dosyalar bulunmayabilir
                $pdo->exec(file_get_contents($tamYol));
                $uygulanan[] = basename($goreliYol);
            }

            // migration_gecmisi tablosuna (varsa) bu kurulumun bir parçası olarak işlendiğini kaydet
            try {
                $simdi = date('Y-m-d H:i:s');
                foreach ($uygulanan as $dosya) {
                    if ($dosya === 'regal_bayi.sql') continue;
                    $pdo->prepare("INSERT IGNORE INTO migration_gecmisi (dosya_adi, uygulandi_tarih) VALUES (?,?)")->execute([$dosya, $simdi]);
                }
            } catch (Exception $e) { /* tablo bu sürümde yoksa sorun değil */ }

            // .env dosyasını yaz
            $sifrelemeAnahtari = bin2hex(random_bytes(32));
            $envIcerik = "APP_ENV=production\n"
                . "DB_HOST=$host\n"
                . "DB_PORT=$port\n"
                . "DB_USER=$user\n"
                . "DB_PASS=$pass\n"
                . "DB_NAME=$name\n"
                . "SESSION_TIMEOUT=1800\n"
                . "# Boş bırakılırsa yedek şifreleme özelliği kullanılamaz.\n"
                . "# Bu anahtar kaybolursa şifrelenmiş yedekler geri yüklenemez — değiştirmeyin/paylaşmayın.\n"
                . "YEDEK_SIFRELEME_ANAHTARI=$sifrelemeAnahtari\n";
            if (file_put_contents($envYolu, $envIcerik) === false) {
                throw new Exception('.env dosyası yazılamadı — klasör izinlerini kontrol edin.');
            }

            $_SESSION['kurulum_db'] = compact('host', 'port', 'user', 'pass', 'name');
            $adim = 'admin_belirle';
        } catch (Exception $e) {
            $hata = 'Kurulum sırasında hata: ' . $e->getMessage();
            $adim = 'db_form';
        }
    }
}

// ── Adım: İlk yönetici şifresini belirle ──────────────────────
if ($adim === 'admin_kaydet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = $_SESSION['kurulum_db'] ?? null;
    $yeniSifre = $_POST['admin_sifre'] ?? '';
    $tekrar = $_POST['admin_sifre_tekrar'] ?? '';
    if (!$db) {
        $hata = 'Oturum bilgisi bulunamadı, sihirbazı baştan başlatın.';
        $adim = 'baslangic';
    } elseif (strlen($yeniSifre) < 8 || !preg_match('/[A-ZÇĞİÖŞÜ]/u', $yeniSifre) || !preg_match('/[a-zçğışöüı]/u', $yeniSifre) || !preg_match('/[0-9]/', $yeniSifre)) {
        $hata = 'Şifre en az 8 karakter olmalı ve büyük harf, küçük harf, rakam içermelidir.';
        $adim = 'admin_belirle';
    } elseif ($yeniSifre !== $tekrar) {
        $hata = 'Şifreler eşleşmiyor.';
        $adim = 'admin_belirle';
    } else {
        try {
            $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare("UPDATE kullanicilar SET sifre=?, sifre_degistirilme_tarihi=NOW() WHERE kullanici_adi='admin'")
                ->execute([password_hash($yeniSifre, PASSWORD_DEFAULT)]);
            unset($_SESSION['kurulum_db']);
            $adim = 'tamamlandi';
        } catch (Exception $e) {
            $hata = 'Şifre kaydedilemedi: ' . $e->getMessage();
            $adim = 'admin_belirle';
        }
    }
}

// ══════════════════════════════════════════════════════════════
// GÖRÜNÜM
// ══════════════════════════════════════════════════════════════

if ($adim === 'baslangic') {
    sihirbazBaslik('1/3 — Hoşgeldiniz');
    $gereksinimler = [
        'PHP 8.0+' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'PDO MySQL uzantısı' => extension_loaded('pdo_mysql'),
        'mbstring uzantısı' => extension_loaded('mbstring'),
        '.env dosyası yazılabilir (proje kökü)' => is_writable(__DIR__),
        'uploads/ klasörü yazılabilir' => is_writable(__DIR__ . '/uploads') || @mkdir(__DIR__ . '/uploads', 0777, true),
        'backups/ klasörü yazılabilir' => is_writable(__DIR__ . '/backups') || @mkdir(__DIR__ . '/backups', 0777, true),
    ];
    $tumuTamam = !in_array(false, $gereksinimler, true);
    echo '<p class="text-muted small">Bu sihirbaz veritabanını oluşturur, gerekli tabloları kurar ve ilk yönetici hesabınızı hazırlar — phpMyAdmin ile uğraşmanıza gerek kalmaz.</p>';
    echo '<ul class="list-group mb-3">';
    foreach ($gereksinimler as $ad => $ok) {
        echo '<li class="list-group-item d-flex justify-content-between align-items-center">' . h($ad)
            . '<span class="badge bg-' . ($ok ? 'success' : 'danger') . '">' . ($ok ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-x-lg"></i>') . '</span></li>';
    }
    echo '</ul>';
    if ($tumuTamam) {
        echo '<form method="post"><input type="hidden" name="adim" value="db_form"><button class="btn btn-primary w-100">Devam Et →</button></form>';
    } else {
        echo '<div class="alert alert-danger small">Yukarıdaki eksik gereksinimleri giderip sayfayı yenileyin.</div>';
    }
    sihirbazAlt();
    exit;
}

if ($adim === 'db_form') {
    sihirbazBaslik('2/3 — Veritabanı Bağlantısı');
    if ($hata) echo '<div class="alert alert-danger small">' . h($hata) . '</div>';
    echo '<form method="post">
        <input type="hidden" name="adim" value="kurulum_yap">
        <div class="mb-2"><label class="form-label small fw-semibold">Sunucu Adresi</label>
            <input type="text" name="db_host" class="form-control" value="' . h($_POST['db_host'] ?? 'localhost') . '" required></div>
        <div class="row">
            <div class="col-8 mb-2"><label class="form-label small fw-semibold">Kullanıcı Adı</label>
                <input type="text" name="db_user" class="form-control" value="' . h($_POST['db_user'] ?? 'root') . '" required></div>
            <div class="col-4 mb-2"><label class="form-label small fw-semibold">Port</label>
                <input type="text" name="db_port" class="form-control" value="' . h($_POST['db_port'] ?? '3306') . '"></div>
        </div>
        <div class="mb-2"><label class="form-label small fw-semibold">Şifre</label>
            <input type="password" name="db_pass" class="form-control" value=""></div>
        <div class="mb-2"><label class="form-label small fw-semibold">Veritabanı Adı</label>
            <input type="text" name="db_name" class="form-control" value="' . h($_POST['db_name'] ?? 'regal_bayi') . '" required></div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="db_olustur" id="olustur" checked>
            <label class="form-check-label small" for="olustur">Veritabanı mevcut değilse otomatik oluştur</label>
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-database-check"></i> Bağlan ve Kur</button>
    </form>';
    sihirbazAlt();
    exit;
}

if ($adim === 'admin_belirle') {
    sihirbazBaslik('3/3 — Yönetici Şifresi');
    if ($hata) echo '<div class="alert alert-danger small">' . h($hata) . '</div>';
    echo '<div class="alert alert-success small"><i class="bi bi-check-circle"></i> Veritabanı ve tablolar başarıyla oluşturuldu.</div>
    <p class="small text-muted">Varsayılan yönetici hesabı: <code>admin</code>. Devam etmeden önce güvenli bir şifre belirleyin.</p>
    <form method="post">
        <input type="hidden" name="adim" value="admin_kaydet">
        <div class="mb-2"><label class="form-label small fw-semibold">Yeni Şifre <small class="text-muted">(min. 8 kar., büyük+küçük harf+rakam)</small></label>
            <input type="password" name="admin_sifre" class="form-control" required minlength="8"></div>
        <div class="mb-3"><label class="form-label small fw-semibold">Şifre Tekrar</label>
            <input type="password" name="admin_sifre_tekrar" class="form-control" required></div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-shield-check"></i> Şifreyi Kaydet ve Bitir</button>
    </form>';
    sihirbazAlt();
    exit;
}

if ($adim === 'tamamlandi') {
    sihirbazBaslik('Kurulum Tamamlandı');
    echo '<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Sistem başarıyla kuruldu!</div>
    <p class="small">Kullanıcı adı: <code>admin</code> — az önce belirlediğiniz şifreyle giriş yapabilirsiniz.</p>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> Şemayla birlikte gelen <strong>örnek/demo veriler</strong> varsa (ürünler, müşteriler, satışlar vb.), gerçek kullanıma geçmeden önce <a href="ornek-veri-sil.php">Örnek Verileri Sil</a> sayfasını kullanarak temizleyin. Bu sayfa yalnızca bir kez çalışır ve kendini otomatik olarak siler.</div>
    <a href="modules/auth/login.php" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Giriş Sayfasına Git</a>';
    sihirbazAlt();
    exit;
}
