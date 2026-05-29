<?php
require_once __DIR__ . '/../config/database.php';

// ── Güvenlik HTTP başlıkları ──────────────────────────────────
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ── Session güvenli başlat ────────────────────────────────────
function startSecureSession() {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Kimlik doğrulama + session timeout ───────────────────────
function auth() {
    startSecureSession();

    if (empty($_SESSION['kullanici_id'])) {
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit;
    }

    // Session timeout (SESSION_TIMEOUT saniye hareketsizlik)
    if (!empty($_SESSION['son_aktivite']) && (time() - $_SESSION['son_aktivite']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        startSecureSession();
        $_SESSION['flash'] = ['tip' => 'uyari', 'mesaj' => 'Oturum süreniz doldu. Lütfen tekrar giriş yapın.'];
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit;
    }
    $_SESSION['son_aktivite'] = time();
}

// ── Rol bazlı yetki ───────────────────────────────────────────
function yetki($roller = []) {
    if (empty($roller)) return;
    if (!in_array($_SESSION['rol'] ?? '', $roller)) {
        http_response_code(403);
        flash('hata', 'Bu işlem için yetkiniz bulunmuyor.');
        header('Location: ' . BASE_URL . '/modules/dashboard/');
        exit;
    }
}

// ── CSRF Token ────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function csrfVerify(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        flash('hata', 'Güvenlik doğrulaması başarısız. Lütfen sayfayı yenileyip tekrar deneyin.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/modules/dashboard/'));
        exit;
    }
}

// ── Brute-force koruması (login için) ────────────────────────
function bruteForceKontrol(string $kullanici_adi): bool {
    $key     = 'bf_' . md5($kullanici_adi . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $max     = 5;
    $pencere = 900; // 15 dakika

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['sayi' => 0, 'zaman' => time()];
    }

    $veri = &$_SESSION[$key];

    if ((time() - $veri['zaman']) > $pencere) {
        $veri = ['sayi' => 0, 'zaman' => time()];
    }

    if ($veri['sayi'] >= $max) {
        $kalan = $pencere - (time() - $veri['zaman']);
        return false; // engellendi
    }
    return true;
}

function bruteForceArtir(string $kullanici_adi): void {
    $key = 'bf_' . md5($kullanici_adi . ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['sayi' => 0, 'zaman' => time()];
    }
    $_SESSION[$key]['sayi']++;
}

function bruteForceSifirla(string $kullanici_adi): void {
    $key = 'bf_' . md5($kullanici_adi . ($_SERVER['REMOTE_ADDR'] ?? ''));
    unset($_SESSION[$key]);
}

function bruteForceKalanSure(string $kullanici_adi): int {
    $key     = 'bf_' . md5($kullanici_adi . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $pencere = 900;
    if (!isset($_SESSION[$key])) return 0;
    $kalan = $pencere - (time() - $_SESSION[$key]['zaman']);
    return max(0, $kalan);
}

// ── Şifre doğrulama politikası ───────────────────────────────
function sifreDogrula(string $sifre): ?string {
    if (strlen($sifre) < 8)                        return 'Şifre en az 8 karakter olmalıdır.';
    if (!preg_match('/[A-ZÇĞİÖŞÜ]/u', $sifre))    return 'Şifre en az bir büyük harf içermelidir.';
    if (!preg_match('/[a-zçğışöüı]/u', $sifre))   return 'Şifre en az bir küçük harf içermelidir.';
    if (!preg_match('/[0-9]/', $sifre))             return 'Şifre en az bir rakam içermelidir.';
    return null; // geçerli
}

// ── LIKE wildcard escape ──────────────────────────────────────
function likeParam(string $value): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

// ── Flash mesajlar ────────────────────────────────────────────
function flash($tip, $mesaj) {
    startSecureSession();
    $_SESSION['flash'] = ['tip' => $tip, 'mesaj' => $mesaj];
}

function showFlash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $renk = match($f['tip']) {
            'basari' => 'success',
            'hata'   => 'danger',
            default  => 'warning',
        };
        echo '<div class="alert alert-' . $renk . ' alert-dismissible fade show" role="alert">'
            . htmlspecialchars($f['mesaj'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// ── Yardımcı formatlama ───────────────────────────────────────
function para($tutar) {
    return number_format((float)$tutar, 2, ',', '.') . ' ₺';
}

function tarih($str) {
    if (!$str) return '-';
    return date('d.m.Y', strtotime($str));
}

function tarihSaat($str) {
    if (!$str) return '-';
    return date('d.m.Y H:i', strtotime($str));
}

function escH($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// ── Fatura no (prepared) ──────────────────────────────────────
function yeniFaturaNo() {
    $pdo  = db();
    $yil  = date('Y');
    $ay   = date('m');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM satislar WHERE YEAR(tarih)=? AND MONTH(tarih)=?");
    $stmt->execute([$yil, $ay]);
    $sayi = (int)$stmt->fetchColumn() + 1;
    return 'F' . $yil . $ay . str_pad($sayi, 4, '0', STR_PAD_LEFT);
}

// ── Stok güncelle ─────────────────────────────────────────────
function stokGuncelle($urun_id, $fark, $tip, $belge_no = '', $aciklama = '', $tedarikci_id = null) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT stok_adedi FROM urunler WHERE id=?");
    $stmt->execute([$urun_id]);
    $onceki  = (int)$stmt->fetchColumn();
    $sonraki = $onceki + $fark;
    $pdo->prepare("UPDATE urunler SET stok_adedi=? WHERE id=?")->execute([$sonraki, $urun_id]);
    $pdo->prepare("INSERT INTO stok_hareketleri (urun_id,hareket_tipi,miktar,onceki_stok,sonraki_stok,belge_no,aciklama,tedarikci_id,kullanici_id) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$urun_id, $tip, abs($fark), $onceki, $sonraki, $belge_no, $aciklama, $tedarikci_id, $_SESSION['kullanici_id'] ?? null]);
}

// ── Ayarlar ───────────────────────────────────────────────────
function ayar(string $anahtar, string $varsayilan = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $stmt = db()->query("SELECT anahtar, deger FROM ayarlar");
            $cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $cache = [];
        }
    }
    return $cache[$anahtar] ?? $varsayilan;
}

function ayarKaydet(string $anahtar, string $deger): void {
    db()->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES (?,?) ON DUPLICATE KEY UPDATE deger=?, updated_at=NOW()")
        ->execute([$anahtar, $deger, $deger]);
}

// ── Bildirim sayaçları ────────────────────────────────────────
function minStokUyarilari() {
    $pdo  = db();
    $stmt = $pdo->query("SELECT COUNT(*) FROM urunler WHERE stok_adedi <= min_stok AND aktif=1");
    return (int)$stmt->fetchColumn();
}

function bekleyenTahsilat() {
    $pdo  = db();
    $stmt = $pdo->query("SELECT COUNT(*) FROM satislar WHERE kalan_tutar > 0 AND durum='bekliyor'");
    return (int)$stmt->fetchColumn();
}
