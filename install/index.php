<?php
/**
 * Regal Bayi Yönetim Sistemi — Kurulum Sihirbazı
 */
session_start();
define('LOCK_FILE', __DIR__ . '/installed.lock');
define('ENV_FILE',  dirname(__DIR__) . '/.env');
define('STEPS', 6);

// ── Kurulu kontrol ────────────────────────────────────────────
if (file_exists(LOCK_FILE)) {
    renderAlreadyInstalled(); exit;
}

// ── Adım yönetimi ─────────────────────────────────────────────
$step   = (int)($_SESSION['install_step'] ?? 1);
$errors = [];
$info   = [];

// ── POST İşleyici ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ─── ADIM 1: Gereksinimler ─────────────────────────────── */
    if ($step === 1) {
        $reqs = checkRequirements();
        if ($reqs['all_ok']) {
            $_SESSION['install_step'] = 2;
            redirect();
        } else {
            $errors[] = 'Lütfen önce gereksinim hatalarını giderin.';
        }
    }

    /* ─── ADIM 2: Veritabanı ────────────────────────────────── */
    elseif ($step === 2) {
        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_port = trim($_POST['db_port'] ?? '3306');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = $_POST['db_pass'] ?? '';
        $db_name = trim($_POST['db_name'] ?? 'regal_bayi');

        if (!$db_user || !$db_name) {
            $errors[] = 'Kullanıcı adı ve veritabanı adı zorunludur.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $db_name)) {
            $errors[] = 'Veritabanı adı yalnızca harf, rakam ve alt çizgi içerebilir.';
        } else {
            try {
                // Önce DB olmadan bağlan
                $dsn = "mysql:host=$db_host;port=$db_port;charset=utf8mb4";
                $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                // Veritabanı oluştur
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci");
                $pdo->exec("USE `$db_name`");
                // Tabloları oluştur
                createTables($pdo);
                // .env yaz
                writeEnv($db_host, $db_port, $db_user, $db_pass, $db_name);
                // Session'a kaydet
                $_SESSION['db'] = compact('db_host','db_port','db_user','db_pass','db_name');
                $_SESSION['install_step'] = 3;
                redirect();
            } catch (PDOException $e) {
                $errors[] = 'Veritabanı bağlantısı başarısız: ' . $e->getMessage();
            }
        }
    }

    /* ─── ADIM 3: Yönetici Hesabı ──────────────────────────── */
    elseif ($step === 3) {
        $ad_soyad     = trim($_POST['ad_soyad'] ?? '');
        $kullanici_adi = trim($_POST['kullanici_adi'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $sifre        = $_POST['sifre'] ?? '';
        $sifre2       = $_POST['sifre2'] ?? '';

        if (!$ad_soyad || !$kullanici_adi || !$sifre) {
            $errors[] = 'Tüm zorunlu alanları doldurun.';
        } elseif (!preg_match('/^[a-z0-9_]{3,50}$/i', $kullanici_adi)) {
            $errors[] = 'Kullanıcı adı 3-50 karakter, harf/rakam/alt çizgi içerebilir.';
        } elseif ($sifre !== $sifre2) {
            $errors[] = 'Şifreler eşleşmiyor.';
        } elseif (strlen($sifre) < 8) {
            $errors[] = 'Şifre en az 8 karakter olmalıdır.';
        } elseif (!preg_match('/[A-Z]/', $sifre) || !preg_match('/[0-9]/', $sifre)) {
            $errors[] = 'Şifre en az bir büyük harf ve bir rakam içermelidir.';
        } else {
            try {
                $pdo = getInstallerPdo();
                $hash = password_hash($sifre, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO kullanicilar (kullanici_adi, sifre, ad_soyad, email, rol) VALUES (?,?,?,?,'yonetici')")
                    ->execute([$kullanici_adi, $hash, $ad_soyad, $email]);
                $_SESSION['admin_id'] = $pdo->lastInsertId();
                $_SESSION['install_step'] = 4;
                redirect();
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    $errors[] = "\"$kullanici_adi\" kullanıcı adı zaten kullanımda.";
                } else {
                    $errors[] = 'Hesap oluşturulamadı: ' . $e->getMessage();
                }
            }
        }
    }

    /* ─── ADIM 4: Firma Bilgileri ──────────────────────────── */
    elseif ($step === 4) {
        $ayarlar = [
            'firma_adi'       => trim($_POST['firma_adi'] ?? 'Regal Bayi'),
            'firma_slogan'    => trim($_POST['firma_slogan'] ?? ''),
            'firma_telefon'   => trim($_POST['firma_telefon'] ?? ''),
            'firma_email'     => trim($_POST['firma_email'] ?? ''),
            'firma_adres'     => trim($_POST['firma_adres'] ?? ''),
            'firma_sehir'     => trim($_POST['firma_sehir'] ?? ''),
            'firma_vergi_no'  => trim($_POST['firma_vergi_no'] ?? ''),
            'firma_vergi_daire' => trim($_POST['firma_vergi_daire'] ?? ''),
            'firma_iban'      => trim($_POST['firma_iban'] ?? ''),
            'site_basligi'    => trim($_POST['site_basligi'] ?? 'Regal Bayi Yönetim'),
            'para_birimi'     => $_POST['para_birimi'] ?? 'TRY',
            'para_sembol'     => $_POST['para_sembol'] ?? '₺',
            'kdv_orani'       => $_POST['kdv_orani'] ?? '20',
            'fatura_prefix'   => trim($_POST['fatura_prefix'] ?? 'F'),
            'min_stok_uyari'  => '1',
            'tema_renk'       => $_POST['tema_renk'] ?? 'primary',
            'tarih_formati'   => 'd.m.Y',
            'kayit_basi'      => '25',
        ];
        if (!$ayarlar['firma_adi']) {
            $errors[] = 'Firma adı zorunludur.';
        } else {
            try {
                $pdo = getInstallerPdo();
                $stmt = $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES (?,?) ON DUPLICATE KEY UPDATE deger=?");
                foreach ($ayarlar as $k => $v) { $stmt->execute([$k, $v, $v]); }
                // Kategorileri ekle
                insertKategoriler($pdo);
                $_SESSION['install_step'] = 5;
                redirect();
            } catch (PDOException $e) {
                $errors[] = 'Ayarlar kaydedilemedi: ' . $e->getMessage();
            }
        }
    }

    /* ─── ADIM 5: Örnek Veri ───────────────────────────────── */
    elseif ($step === 5) {
        if (isset($_POST['ornek_veri'])) {
            try {
                $pdo = getInstallerPdo();
                insertOrnek($pdo);
                $info[] = 'Örnek veriler başarıyla yüklendi.';
            } catch (Exception $e) {
                $errors[] = 'Örnek veri yüklenemedi: ' . $e->getMessage();
            }
        }
        // Lock dosyası oluştur
        file_put_contents(LOCK_FILE, date('Y-m-d H:i:s'));
        $_SESSION['install_step'] = 6;
        redirect();
    }
}

// ── Render ────────────────────────────────────────────────────
$reqs = ($step === 1) ? checkRequirements() : null;
renderPage($step, $errors, $info, $reqs);

// ═══════════════════════════════════════════════════════════════
// YARDIMCI FONKSİYONLAR
// ═══════════════════════════════════════════════════════════════

function redirect() {
    header('Location: index.php'); exit;
}

function getInstallerPdo(): PDO {
    $d = $_SESSION['db'];
    return new PDO(
        "mysql:host={$d['db_host']};port={$d['db_port']};dbname={$d['db_name']};charset=utf8mb4",
        $d['db_user'], $d['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function writeEnv($host, $port, $user, $pass, $name) {
    $port_str = ($port && $port !== '3306') ? ";port=$port" : '';
    $content = "APP_ENV=production\nDB_HOST=$host\nDB_USER=$user\nDB_PASS=$pass\nDB_NAME=$name\nSESSION_TIMEOUT=1800\n";
    file_put_contents(ENV_FILE, $content);
}

function checkRequirements(): array {
    $checks = [];
    $all_ok = true;

    $php_ok = version_compare(PHP_VERSION, '8.0.0', '>=');
    $checks[] = ['ad' => 'PHP >= 8.0', 'ok' => $php_ok, 'detay' => 'Mevcut: ' . PHP_VERSION];
    if (!$php_ok) $all_ok = false;

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl'] as $ext) {
        $ok = extension_loaded($ext);
        $checks[] = ['ad' => "PHP ext: $ext", 'ok' => $ok, 'detay' => $ok ? 'Yüklü' : 'Eksik — php.ini\'de etkinleştirin'];
        if (!$ok) $all_ok = false;
    }

    $env_dir = dirname(ENV_FILE);
    $dir_ok = is_writable($env_dir);
    $checks[] = ['ad' => '.env dizini yazılabilir', 'ok' => $dir_ok, 'detay' => $env_dir];
    if (!$dir_ok) $all_ok = false;

    $backup_dir = dirname(__DIR__) . '/backups';
    if (!is_dir($backup_dir)) @mkdir($backup_dir, 0777, true);
    $bk_ok = is_writable($backup_dir) || @mkdir($backup_dir, 0777, true);
    $checks[] = ['ad' => 'backups/ dizini yazılabilir', 'ok' => $bk_ok, 'detay' => $backup_dir];
    if (!$bk_ok) $all_ok = false;

    return ['checks' => $checks, 'all_ok' => $all_ok];
}

function createTables(PDO $pdo): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $sqls = [
"CREATE TABLE IF NOT EXISTS kullanicilar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kullanici_adi VARCHAR(50) UNIQUE NOT NULL,
    sifre VARCHAR(255) NOT NULL,
    ad_soyad VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    rol ENUM('yonetici','kasiyer','depo') DEFAULT 'kasiyer',
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS kategoriler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(100) NOT NULL,
    ust_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ust_id) REFERENCES kategoriler(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS tedarikciler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    yetkili VARCHAR(100),
    telefon VARCHAR(20),
    email VARCHAR(100),
    adres TEXT,
    vergi_no VARCHAR(20),
    notlar TEXT,
    toplam_borc DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS urunler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(50) UNIQUE NOT NULL,
    barkod VARCHAR(50),
    ad VARCHAR(200) NOT NULL,
    kategori_id INT,
    marka VARCHAR(100) DEFAULT 'Regal',
    model VARCHAR(100),
    renk VARCHAR(50),
    aciklama TEXT,
    alis_fiyati DECIMAL(10,2) DEFAULT 0,
    satis_fiyati DECIMAL(10,2) DEFAULT 0,
    kdv_orani DECIMAL(5,2) DEFAULT 20,
    garanti_suresi INT DEFAULT 24,
    stok_adedi INT DEFAULT 0,
    min_stok INT DEFAULT 1,
    seri_no_takip TINYINT(1) DEFAULT 0,
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kategori_id) REFERENCES kategoriler(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS seri_numaralari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_id INT NOT NULL,
    seri_no VARCHAR(100) NOT NULL,
    durum ENUM('stokta','satildi','ariza','iade') DEFAULT 'stokta',
    satis_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (urun_id) REFERENCES urunler(id)
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS stok_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_id INT NOT NULL,
    hareket_tipi ENUM('giris','cikis','iade_giris','fire','sayim_duzeltme') NOT NULL,
    miktar INT NOT NULL,
    onceki_stok INT DEFAULT 0,
    sonraki_stok INT DEFAULT 0,
    belge_no VARCHAR(50),
    aciklama VARCHAR(255),
    birim_maliyet DECIMAL(10,2) DEFAULT NULL,
    toplam_maliyet DECIMAL(12,2) DEFAULT NULL,
    tedarikci_id INT DEFAULT NULL,
    kullanici_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (urun_id) REFERENCES urunler(id),
    FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS musteriler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tip ENUM('bireysel','kurumsal') DEFAULT 'bireysel',
    ad VARCHAR(100) NOT NULL,
    soyad VARCHAR(100),
    firma_adi VARCHAR(150),
    tc_no VARCHAR(11),
    vergi_no VARCHAR(11),
    telefon VARCHAR(20),
    telefon2 VARCHAR(20),
    email VARCHAR(100),
    adres TEXT,
    sehir VARCHAR(50),
    notlar TEXT,
    toplam_borc DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS satislar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fatura_no VARCHAR(30) UNIQUE NOT NULL,
    musteri_id INT,
    kullanici_id INT,
    tarih DATE NOT NULL,
    ara_toplam DECIMAL(12,2) DEFAULT 0,
    kdv_toplam DECIMAL(12,2) DEFAULT 0,
    indirim_toplam DECIMAL(12,2) DEFAULT 0,
    genel_toplam DECIMAL(12,2) DEFAULT 0,
    odeme_tipi ENUM('nakit','kredi_karti','havale','taksitli','karisik') DEFAULT 'nakit',
    taksit_sayisi INT DEFAULT 1,
    odenen_tutar DECIMAL(12,2) DEFAULT 0,
    kalan_tutar DECIMAL(12,2) DEFAULT 0,
    durum ENUM('tamamlandi','bekliyor','iptal') DEFAULT 'tamamlandi',
    notlar TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS satis_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    satis_id INT NOT NULL,
    urun_id INT NOT NULL,
    seri_no_id INT DEFAULT NULL,
    miktar INT DEFAULT 1,
    birim_fiyat DECIMAL(10,2) NOT NULL,
    kdv_orani DECIMAL(5,2) DEFAULT 20,
    kdv_tutar DECIMAL(10,2) DEFAULT 0,
    indirim DECIMAL(10,2) DEFAULT 0,
    toplam DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (satis_id) REFERENCES satislar(id) ON DELETE CASCADE,
    FOREIGN KEY (urun_id) REFERENCES urunler(id),
    FOREIGN KEY (seri_no_id) REFERENCES seri_numaralari(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS odemeler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    satis_id INT,
    musteri_id INT,
    tarih DATE NOT NULL,
    tutar DECIMAL(12,2) NOT NULL,
    odeme_tipi ENUM('nakit','kredi_karti','havale','eft') DEFAULT 'nakit',
    taksit_no INT DEFAULT NULL,
    aciklama VARCHAR(255),
    kullanici_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (satis_id) REFERENCES satislar(id) ON DELETE SET NULL,
    FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS taksit_plani (
    id INT AUTO_INCREMENT PRIMARY KEY,
    satis_id INT NOT NULL,
    taksit_no INT NOT NULL,
    tutar DECIMAL(10,2) NOT NULL,
    vade_tarihi DATE NOT NULL,
    odendi TINYINT(1) DEFAULT 0,
    odeme_tarihi DATE DEFAULT NULL,
    odeme_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (satis_id) REFERENCES satislar(id) ON DELETE CASCADE,
    FOREIGN KEY (odeme_id) REFERENCES odemeler(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS kasa_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tarih DATE NOT NULL,
    tip ENUM('giris','cikis') NOT NULL,
    tutar DECIMAL(12,2) NOT NULL,
    aciklama VARCHAR(255),
    kategori VARCHAR(100),
    odeme_id INT DEFAULT NULL,
    kullanici_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (odeme_id) REFERENCES odemeler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS ayarlar (
    anahtar VARCHAR(100) PRIMARY KEY,
    deger TEXT,
    grup VARCHAR(50) DEFAULT 'genel',
    aciklama VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS aktivite_loglari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kullanici_id INT DEFAULT NULL,
    aksiyon VARCHAR(100) NOT NULL,
    modul VARCHAR(50),
    hedef_id INT DEFAULT NULL,
    detay VARCHAR(500),
    ip_adresi VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB",

"CREATE TABLE IF NOT EXISTS tedarikci_odemeleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tedarikci_id INT NOT NULL,
    tarih DATE NOT NULL,
    tutar DECIMAL(12,2) NOT NULL,
    odeme_tipi ENUM('nakit','kredi_karti','havale') DEFAULT 'nakit',
    aciklama VARCHAR(255),
    kullanici_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE CASCADE,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB",
    ];
    foreach ($sqls as $sql) { $pdo->exec($sql); }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

function insertKategoriler(PDO $pdo): void {
    $pdo->exec("DELETE FROM kategoriler");
    $pdo->exec("ALTER TABLE kategoriler AUTO_INCREMENT=1");
    $rows = [
        [1,'Büyük Beyaz Eşya',null],[2,'Küçük Ev Aletleri',null],
        [3,'Buzdolabı',1],[4,'Çamaşır Makinesi',1],[5,'Bulaşık Makinesi',1],
        [6,'Fırın & Ocak',1],[7,'Klima',1],
        [8,'Kettle & Çaydanlık',2],[9,'Tost Makinesi & Izgara',2],
        [10,'Blender & Mutfak Robotu',2],[11,'Süpürge',2],[12,'Ütü',2],
    ];
    $stmt = $pdo->prepare("INSERT INTO kategoriler (id,ad,ust_id) VALUES (?,?,?)");
    foreach ($rows as $r) { $stmt->execute($r); }
}

function insertOrnek(PDO $pdo): void {
    $sql = file_get_contents(dirname(__DIR__) . '/sql/ornek_veri.sql');
    // USE ve SET satırlarını kaldır
    $sql = preg_replace('/^USE\s+\S+;/im', '', $sql);
    $sql = preg_replace('/^SET\s+NAMES\s+\S+;/im', '', $sql);
    // Tek tek çalıştır
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt) { try { $pdo->exec($stmt); } catch (Exception $e) {} }
    }
}

// ═══════════════════════════════════════════════════════════════
// HTML RENDER
// ═══════════════════════════════════════════════════════════════

function renderAlreadyInstalled(): void {
    ?><!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Zaten Kurulu</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    </head><body class="bg-light d-flex align-items-center" style="min-height:100vh">
    <div class="container"><div class="row justify-content-center"><div class="col-md-6">
    <div class="card shadow border-0 text-center p-5">
        <div class="text-success fs-1 mb-3">&#10003;</div>
        <h3 class="fw-bold">Sistem Zaten Kurulu</h3>
        <p class="text-muted">Bu kurulum sihirbazı daha önce çalıştırılmış ve sistem kurulmuş.</p>
        <div class="alert alert-warning mt-3 text-start small">
            <strong>Güvenlik:</strong> <code>install/</code> klasörünü sunucudan silin.
        </div>
        <a href="../modules/auth/login.php" class="btn btn-primary mt-2">Giriş Sayfasına Git</a>
    </div></div></div></div></body></html>
    <?php
}

function renderPage(int $step, array $errors, array $info, ?array $reqs): void {
    $stepLabels = ['','Gereksinimler','Veritabanı','Yönetici Hesabı','Firma Bilgileri','Örnek Veri','Tamamlandı'];
    $stepIcons  = ['','bi-check-circle','bi-database','bi-person-gear','bi-building','bi-archive','bi-trophy'];
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Regal Bayi — Kurulum Sihirbazı</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
:root { --primary: #0d6efd; }
body { background: #f0f4ff; min-height: 100vh; }
.wizard-header { background: linear-gradient(135deg, #0d6efd, #0a58ca); }
.step-badge { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem; transition:all .3s; }
.step-done   { background:#198754; color:#fff; }
.step-active { background:#fff; color:#0d6efd; box-shadow:0 0 0 3px rgba(255,255,255,.4); }
.step-todo   { background:rgba(255,255,255,.2); color:rgba(255,255,255,.6); }
.step-line   { flex:1; height:2px; background:rgba(255,255,255,.2); margin:0 4px; }
.step-line.done { background:#198754; }
.card-wizard { border:none; border-radius:16px; box-shadow:0 8px 40px rgba(0,0,0,.1); }
.req-ok   { color:#198754; }
.req-fail { color:#dc3545; }
.form-label { font-weight:600; font-size:.9rem; }
.btn-primary { background:var(--primary); border-color:var(--primary); }
.progress-step { font-size:.75rem; color:rgba(255,255,255,.75); margin-top:4px; }
</style>
</head>
<body>

<!-- Başlık -->
<div class="wizard-header text-white py-4 px-3 mb-0">
    <div class="container" style="max-width:800px">
        <div class="d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-shop fs-2"></i>
            <div>
                <h4 class="mb-0 fw-bold">Regal Bayi Yönetim Sistemi</h4>
                <small class="opacity-75">Kurulum Sihirbazı</small>
            </div>
        </div>
        <!-- Adım göstergesi -->
        <div class="d-flex align-items-center">
        <?php for ($i = 1; $i <= STEPS; $i++): ?>
            <div class="text-center">
                <div class="step-badge mx-auto <?= $i < $step ? 'step-done' : ($i === $step ? 'step-active' : 'step-todo') ?>">
                    <?= $i < $step ? '<i class="bi bi-check-lg"></i>' : $i ?>
                </div>
                <div class="progress-step d-none d-md-block"><?= $stepLabels[$i] ?></div>
            </div>
            <?php if ($i < STEPS): ?>
            <div class="step-line <?= $i < $step ? 'done' : '' ?>"></div>
            <?php endif; ?>
        <?php endfor; ?>
        </div>
    </div>
</div>

<!-- İçerik -->
<div class="container py-4" style="max-width:800px">

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($info): ?>
<div class="alert alert-success">
    <?php foreach ($info as $i): ?><div><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($i) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card card-wizard p-4">
<h5 class="fw-bold mb-4 d-flex align-items-center gap-2">
    <i class="bi <?= $stepIcons[$step] ?> text-primary fs-4"></i>
    <?= "Adım $step / " . STEPS . ": " . $stepLabels[$step] ?>
</h5>

<?php

// ── ADIM 1: Gereksinimler ─────────────────────────────────────
if ($step === 1): ?>
<p class="text-muted">Kuruluma başlamadan önce sunucu gereksinimleri kontrol ediliyor.</p>
<div class="table-responsive mb-4">
<table class="table table-bordered table-sm">
    <thead class="table-light"><tr><th>Gereksinim</th><th>Durum</th><th>Detay</th></tr></thead>
    <tbody>
    <?php foreach ($reqs['checks'] as $c): ?>
    <tr>
        <td><?= htmlspecialchars($c['ad']) ?></td>
        <td>
            <?php if ($c['ok']): ?>
            <span class="req-ok fw-bold"><i class="bi bi-check-circle-fill"></i> Tamam</span>
            <?php else: ?>
            <span class="req-fail fw-bold"><i class="bi bi-x-circle-fill"></i> Hata</span>
            <?php endif; ?>
        </td>
        <td class="small text-muted"><?= htmlspecialchars($c['detay']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($reqs['all_ok']): ?>
<div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>Tüm gereksinimler karşılanıyor. Devam edebilirsiniz.</div>
<?php else: ?>
<div class="alert alert-danger"><i class="bi bi-x-circle-fill me-2"></i>Bazı gereksinimler karşılanmıyor. Sunucunuzu yapılandırın.</div>
<?php endif; ?>
<form method="post">
    <button type="submit" class="btn btn-primary px-4" <?= $reqs['all_ok'] ? '' : 'disabled' ?>>
        Devam Et <i class="bi bi-arrow-right"></i>
    </button>
</form>

<?php
// ── ADIM 2: Veritabanı ───────────────────────────────────────
elseif ($step === 2): ?>
<p class="text-muted">Veritabanı sunucusuna bağlantı bilgilerini girin. Veritabanı yoksa otomatik oluşturulur.</p>
<form method="post">
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Sunucu Adresi <span class="text-danger">*</span></label>
            <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Port</label>
            <input type="text" name="db_port" class="form-control" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
            <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Şifre</label>
            <input type="password" name="db_pass" class="form-control" autocomplete="off">
            <div class="form-text">Şifresiz kullanıcılar için boş bırakın.</div>
        </div>
        <div class="col-md-12">
            <label class="form-label">Veritabanı Adı <span class="text-danger">*</span></label>
            <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? 'regal_bayi') ?>" required>
            <div class="form-text">Veritabanı yoksa otomatik oluşturulur. Var olan bir veritabanı seçerseniz mevcut tablolar korunur.</div>
        </div>
    </div>
    <div class="alert alert-info mt-3 small">
        <i class="bi bi-info-circle me-1"></i>
        Bu bilgiler <code>.env</code> dosyasına kaydedilecek. Bağlantı bilgilerinizi güvenli tutun.
    </div>
    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary px-4">Bağlan ve Tabloları Oluştur <i class="bi bi-arrow-right"></i></button>
    </div>
</form>

<?php
// ── ADIM 3: Yönetici Hesabı ──────────────────────────────────
elseif ($step === 3): ?>
<p class="text-muted">Sisteme giriş yapacak ilk yönetici hesabını oluşturun.</p>
<form method="post" autocomplete="off">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Ad Soyad <span class="text-danger">*</span></label>
            <input type="text" name="ad_soyad" class="form-control" placeholder="Ahmet Yılmaz" required maxlength="100">
        </div>
        <div class="col-md-6">
            <label class="form-label">E-posta</label>
            <input type="email" name="email" class="form-control" placeholder="admin@firma.com" maxlength="100">
        </div>
        <div class="col-md-4">
            <label class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
            <input type="text" name="kullanici_adi" class="form-control" placeholder="admin" required maxlength="50" autocomplete="username">
            <div class="form-text">Giriş yaparken kullanılacak.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Şifre <span class="text-danger">*</span></label>
            <input type="password" name="sifre" id="sifre" class="form-control" required minlength="8" autocomplete="new-password">
            <div class="form-text">Min 8 karakter, büyük harf + rakam.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Şifre Tekrar <span class="text-danger">*</span></label>
            <input type="password" name="sifre2" id="sifre2" class="form-control" required autocomplete="new-password" oninput="checkMatch()">
            <div id="matchMsg" class="form-text"></div>
        </div>
    </div>
    <div class="alert alert-warning mt-3 small">
        <i class="bi bi-shield-lock me-1"></i>
        Bu hesap <strong>tam yönetici yetkisine</strong> sahip olacak. Güçlü bir şifre seçin.
    </div>
    <button type="submit" class="btn btn-primary px-4 mt-2">Hesabı Oluştur <i class="bi bi-arrow-right"></i></button>
</form>
<script>
function checkMatch() {
    const s = document.getElementById('sifre').value;
    const s2 = document.getElementById('sifre2').value;
    const el = document.getElementById('matchMsg');
    if (!s2) { el.textContent = ''; return; }
    el.innerHTML = s === s2
        ? '<span class="text-success">✓ Eşleşiyor</span>'
        : '<span class="text-danger">✗ Eşleşmiyor</span>';
}
</script>

<?php
// ── ADIM 4: Firma Bilgileri ──────────────────────────────────
elseif ($step === 4): ?>
<p class="text-muted">Firma bilgilerinizi girin. Faturalarda, raporlarda ve sistem başlığında kullanılır. Sonradan <strong>Ayarlar</strong> menüsünden değiştirilebilir.</p>
<form method="post">
    <ul class="nav nav-tabs mb-3" id="firmaTabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-firma" type="button"><i class="bi bi-building"></i> Firma</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sistem" type="button"><i class="bi bi-sliders"></i> Sistem</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tema" type="button"><i class="bi bi-palette"></i> Tema</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-firma">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Firma Adı <span class="text-danger">*</span></label>
                    <input type="text" name="firma_adi" class="form-control" value="Regal Bayi" required maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Slogan</label>
                    <input type="text" name="firma_slogan" class="form-control" placeholder="Beyaz Eşya ve Küçük Ev Aletleri" maxlength="150">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Telefon</label>
                    <input type="text" name="firma_telefon" class="form-control" placeholder="0312 000 00 00" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label class="form-label">E-posta</label>
                    <input type="email" name="firma_email" class="form-control" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Şehir</label>
                    <input type="text" name="firma_sehir" class="form-control" maxlength="50">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Adres</label>
                    <textarea name="firma_adres" class="form-control" rows="2" maxlength="300"></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vergi No</label>
                    <input type="text" name="firma_vergi_no" class="form-control" maxlength="11">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vergi Dairesi</label>
                    <input type="text" name="firma_vergi_daire" class="form-control" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label">IBAN</label>
                    <input type="text" name="firma_iban" class="form-control" maxlength="32" placeholder="TR00 0000...">
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-sistem">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Site Başlığı</label>
                    <input type="text" name="site_basligi" class="form-control" value="Regal Bayi Yönetim" maxlength="80">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fatura Öneki</label>
                    <input type="text" name="fatura_prefix" class="form-control" value="F" maxlength="5">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Para Birimi</label>
                    <select name="para_birimi" class="form-select">
                        <option value="TRY" selected>Türk Lirası (₺)</option>
                        <option value="USD">Dolar ($)</option>
                        <option value="EUR">Euro (€)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Para Sembolü</label>
                    <input type="text" name="para_sembol" class="form-control" value="₺" maxlength="5">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Varsayılan KDV %</label>
                    <select name="kdv_orani" class="form-select">
                        <option value="0">%0</option>
                        <option value="1">%1</option>
                        <option value="10">%10</option>
                        <option value="20" selected>%20</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="tab-tema">
            <label class="form-label d-block">Tema Rengi</label>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach ([
                    'primary'=>['#0d6efd','Mavi'],'success'=>['#198754','Yeşil'],
                    'danger'=>['#dc3545','Kırmızı'],'dark'=>['#212529','Koyu'],
                    'purple'=>['#6f42c1','Mor'],'warning'=>['#e08c00','Sarı'],
                ] as $k=>[$renk,$ad]): ?>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="tema_renk" id="t_<?= $k ?>" value="<?= $k ?>" <?= $k==='primary'?'checked':'' ?>>
                    <label class="form-check-label d-flex align-items-center gap-2" for="t_<?= $k ?>">
                        <span style="width:22px;height:22px;border-radius:5px;background:<?= $renk ?>;display:inline-block;border:2px solid rgba(0,0,0,.1)"></span>
                        <?= $ad ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary px-4 mt-4">Kaydet ve Devam Et <i class="bi bi-arrow-right"></i></button>
</form>

<?php
// ── ADIM 5: Örnek Veri ───────────────────────────────────────
elseif ($step === 5): ?>
<p class="text-muted">Sistemi hemen deneyebilmek için örnek veriler yükleyebilirsiniz. Üretim ortamında bunu atlamanız önerilir.</p>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-2 border-success h-100">
            <div class="card-body">
                <h6 class="fw-bold text-success"><i class="bi bi-database-fill-check me-2"></i>Örnek Verilerle Başla</h6>
                <ul class="small text-muted mb-0">
                    <li>4 tedarikçi, 20 ürün (12 kategori)</li>
                    <li>15 müşteri (bireysel + kurumsal)</li>
                    <li>15 satış (nakit, kart, taksitli)</li>
                    <li>Bekleyen tahsilatlar ve gider kayıtları</li>
                    <li>3 kritik stok ürünü</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-2 border-secondary h-100">
            <div class="card-body">
                <h6 class="fw-bold"><i class="bi bi-database-slash me-2"></i>Boş Sistemle Başla</h6>
                <ul class="small text-muted mb-0">
                    <li>Sadece 12 ürün kategorisi yüklenir</li>
                    <li>Ürün, müşteri, satış verileri boş</li>
                    <li>Gerçek verilerinizi kendiniz girersiniz</li>
                    <li><strong>Üretim ortamı için önerilir</strong></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<form method="post" class="d-flex gap-3">
    <button type="submit" name="ornek_veri" value="1" class="btn btn-success px-4">
        <i class="bi bi-database-fill-check"></i> Örnek Verilerle Devam Et
    </button>
    <button type="submit" class="btn btn-outline-secondary px-4">
        <i class="bi bi-database-slash"></i> Boş Sistemle Devam Et
    </button>
</form>

<?php
// ── ADIM 6: Tamamlandı ───────────────────────────────────────
elseif ($step === 6): ?>
<div class="text-center py-3">
    <div class="text-success mb-3" style="font-size:4rem"><i class="bi bi-check-circle-fill"></i></div>
    <h3 class="fw-bold">Kurulum Tamamlandı!</h3>
    <p class="text-muted">Regal Bayi Yönetim Sistemi başarıyla kuruldu ve kullanıma hazır.</p>
</div>

<div class="row g-3 mb-4">
    <?php $d = $_SESSION['db'] ?? []; ?>
    <div class="col-md-6">
        <div class="card bg-light border-0">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Sistem Bilgileri</h6>
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Veritabanı</td><td class="fw-semibold"><?= htmlspecialchars($d['db_name'] ?? '') ?></td></tr>
                    <tr><td class="text-muted">Sunucu</td><td><?= htmlspecialchars($d['db_host'] ?? '') ?></td></tr>
                    <tr><td class="text-muted">PHP</td><td><?= PHP_VERSION ?></td></tr>
                    <tr><td class="text-muted">Kurulum</td><td><?= date('d.m.Y H:i') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card bg-light border-0">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-link-45deg text-primary me-2"></i>Giriş Bilgileri</h6>
                <div class="alert alert-warning small mb-0">
                    <strong>Önemli:</strong> Güvenlik için kurulum sihirbazının bulunduğu <code>install/</code> klasörünü sunucudan <strong>silin</strong>.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-3 justify-content-center">
    <a href="../modules/auth/login.php" class="btn btn-primary btn-lg px-5">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sisteme Giriş Yap
    </a>
</div>

<?php
// Session temizle
unset($_SESSION['install_step'], $_SESSION['db'], $_SESSION['admin_id']);
endif; ?>

</div><!-- /card -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php } // renderPage sonu
