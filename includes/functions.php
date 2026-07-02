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
        // Yalnızca kendi hostumuza yönlendir (open redirect önlemi)
        $hedef   = BASE_URL . '/modules/dashboard/';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
        if ($referer && $host && (parse_url($referer, PHP_URL_HOST) ?? '') === $host) {
            $hedef = $referer;
        }
        header('Location: ' . $hedef);
        exit;
    }
}

// ── Brute-force koruması (login için) ────────────────────────
// Sayaç DB'de tutulur (aktivite_loglari); session/cookie atılarak aşılamaz.
const BF_MAX     = 5;
const BF_PENCERE = 900; // 15 dakika

function bruteForceKontrol(string $kullanici_adi): bool {
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM aktivite_loglari
             WHERE aksiyon='giris_basarisiz' AND ip_adresi=? AND detay=?
               AND created_at > DATE_SUB(NOW(), INTERVAL " . BF_PENCERE . " SECOND)"
        );
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $kullanici_adi]);
        return (int)$stmt->fetchColumn() < BF_MAX;
    } catch (Exception $e) {
        return true; // log tablosu yoksa girişi engelleme
    }
}

function bruteForceArtir(string $kullanici_adi): void {
    logla('giris_basarisiz', 'auth', 0, $kullanici_adi);
}

function bruteForceSifirla(string $kullanici_adi): void {
    try {
        db()->prepare("DELETE FROM aktivite_loglari WHERE aksiyon='giris_basarisiz' AND ip_adresi=? AND detay=?")
            ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $kullanici_adi]);
    } catch (Exception $e) {}
}

function bruteForceKalanSure(string $kullanici_adi): int {
    try {
        // Penceredeki en eski başarısız deneme süresi dolunca blokaj kalkar
        $stmt = db()->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) FROM aktivite_loglari
             WHERE aksiyon='giris_basarisiz' AND ip_adresi=? AND detay=?
               AND created_at > DATE_SUB(NOW(), INTERVAL " . BF_PENCERE . " SECOND)"
        );
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $kullanici_adi]);
        $gecen = (int)$stmt->fetchColumn();
        return max(0, BF_PENCERE - $gecen);
    } catch (Exception $e) { return 0; }
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
    return number_format((float)$tutar, 2, ',', '.') . ' ' . ayar('para_sembol', '₺');
}

function tarih($str) {
    if (!$str) return '-';
    $ts = strtotime($str);
    if ($ts === false) return '-';
    return date(ayar('tarih_formati', 'd.m.Y'), $ts);
}

function tarihSaat($str) {
    if (!$str) return '-';
    $ts = strtotime($str);
    if ($ts === false) return '-';
    return date(ayar('tarih_formati', 'd.m.Y') . ' H:i', $ts);
}

function escH($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// ── Fatura no ─────────────────────────────────────────────────
// Ay bazında MAX numara + 1; eşzamanlılık için satislar.fatura_no UNIQUE
// kısıtına güvenilir — çakışırsa çağıran taraf yeniden dener.
function yeniFaturaNo() {
    $prefix = ayar('fatura_prefix', 'F') . date('Y') . date('m');
    $stmt = db()->prepare("SELECT MAX(CAST(SUBSTRING(fatura_no, ?) AS UNSIGNED)) FROM satislar WHERE fatura_no LIKE ?");
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $sayi = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad($sayi, 4, '0', STR_PAD_LEFT);
}

// ── Stok güncelle ─────────────────────────────────────────────
// Satır FOR UPDATE ile kilitlenir; aktif bir transaction içinden
// çağrılmalıdır, aksi halde kilit anlamsızdır.
function stokGuncelle($urun_id, $fark, $tip, $belge_no = '', $aciklama = '', $tedarikci_id = null, $birim_maliyet = null) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT stok_adedi FROM urunler WHERE id=? FOR UPDATE");
    $stmt->execute([$urun_id]);
    $onceki        = (int)$stmt->fetchColumn();
    $sonraki       = $onceki + $fark;
    $toplam_maliyet = ($birim_maliyet !== null) ? round(abs($fark) * $birim_maliyet, 2) : null;
    $pdo->prepare("UPDATE urunler SET stok_adedi = stok_adedi + ? WHERE id=?")->execute([$fark, $urun_id]);
    $pdo->prepare("INSERT INTO stok_hareketleri (urun_id,hareket_tipi,miktar,onceki_stok,sonraki_stok,belge_no,aciklama,birim_maliyet,toplam_maliyet,tedarikci_id,kullanici_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$urun_id, $tip, abs($fark), $onceki, $sonraki, $belge_no, $aciklama, $birim_maliyet, $toplam_maliyet, $tedarikci_id, $_SESSION['kullanici_id'] ?? null]);
}

// ── Müşteri borcu ─────────────────────────────────────────────
// toplam_borc türetilmiş veridir; artır/azalt yerine her değişiklikte
// açık satışların kalanından yeniden hesaplanır (senkron kaybı önlenir).
function musteriBorcuYenile(?int $musteri_id): void {
    if (!$musteri_id) return;
    db()->prepare(
        "UPDATE musteriler SET toplam_borc =
            (SELECT COALESCE(SUM(kalan_tutar),0) FROM satislar
             WHERE musteri_id=? AND durum='bekliyor')
         WHERE id=?"
    )->execute([$musteri_id, $musteri_id]);
}

// ── Ayarlar ───────────────────────────────────────────────────
function ayar(string $anahtar, string $varsayilan = ''): string {
    if (!isset($GLOBALS['__ayar_cache'])) {
        try {
            $stmt = db()->query("SELECT anahtar, deger FROM ayarlar");
            $GLOBALS['__ayar_cache'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $GLOBALS['__ayar_cache'] = [];
        }
    }
    return $GLOBALS['__ayar_cache'][$anahtar] ?? $varsayilan;
}

function ayarKaydet(string $anahtar, string $deger): void {
    db()->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES (?,?) ON DUPLICATE KEY UPDATE deger=?, updated_at=NOW()")
        ->execute([$anahtar, $deger, $deger]);
    if (isset($GLOBALS['__ayar_cache'])) $GLOBALS['__ayar_cache'][$anahtar] = $deger;
}

// ── Aktivite logu ────────────────────────────────────────────
function logla(string $aksiyon, string $modul = '', int $hedef_id = 0, string $detay = ''): void {
    try {
        db()->prepare("INSERT INTO aktivite_loglari (kullanici_id,aksiyon,modul,hedef_id,detay,ip_adresi) VALUES (?,?,?,?,?,?)")
            ->execute([
                $_SESSION['kullanici_id'] ?? null,
                $aksiyon,
                $modul ?: null,
                $hedef_id ?: null,
                $detay ?: null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Exception $e) {}
}

// ── Yedekleme yardımcıları ───────────────────────────────────
function bugunYedekVarMi(): bool {
    $yedekDir = __DIR__ . '/../backups/';
    $bugun    = date('Y-m-d');
    foreach (glob($yedekDir . '*.sql') ?: [] as $dosya) {
        if (str_contains(basename($dosya), $bugun)) return true;
    }
    return false;
}

function otomatikYedekAl(): bool {
    $yedekDir = __DIR__ . '/../backups/';
    if (!is_dir($yedekDir)) mkdir($yedekDir, 0777, true);
    $dosyaAdi = 'regal_bayi_oto_' . date('Y-m-d_H-i-s') . '.sql';
    $hedef    = $yedekDir . $dosyaAdi;
    $cmd = escapeshellcmd('/opt/lampp/bin/mysqldump')
         . ' -u ' . escapeshellarg(DB_USER)
         . (DB_PASS !== '' ? ' -p' . escapeshellarg(DB_PASS) : '')
         . ' '    . escapeshellarg(DB_NAME)
         . ' > '  . escapeshellarg($hedef)
         . ' 2>/dev/null';
    exec($cmd, $cikti, $kod);
    if ($kod === 0 && file_exists($hedef)) {
        ayarKaydet('son_oto_yedek', date('Y-m-d H:i:s'));
        return true;
    }
    return false;
}

// ── Bildirim sayaçları (tek sorguda) ─────────────────────────
function bildirimSayaclari(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $row = db()->query("
            SELECT
              (SELECT COUNT(*) FROM urunler  WHERE stok_adedi <= min_stok AND aktif=1) AS dusuk_stok,
              (SELECT COUNT(*) FROM satislar WHERE kalan_tutar > 0 AND durum='bekliyor') AS bekleyen_odeme,
              (SELECT COUNT(*) FROM taksit_plani tp JOIN satislar s ON tp.satis_id=s.id
                WHERE tp.odendi=0 AND tp.vade_tarihi < CURDATE() AND s.durum != 'iptal') AS gecikmis_taksit
        ")->fetch();
        $cache = [
            'dusuk_stok'      => (int)($row['dusuk_stok'] ?? 0),
            'bekleyen_odeme'  => (int)($row['bekleyen_odeme'] ?? 0),
            'gecikmis_taksit' => (int)($row['gecikmis_taksit'] ?? 0),
        ];
    } catch (Exception $e) {
        $cache = ['dusuk_stok' => 0, 'bekleyen_odeme' => 0, 'gecikmis_taksit' => 0];
    }
    return $cache;
}

function minStokUyarilari(): int   { return bildirimSayaclari()['dusuk_stok']; }
function bekleyenTahsilat(): int   { return bildirimSayaclari()['bekleyen_odeme']; }
function gecikmisTaksitSayisi(): int { return bildirimSayaclari()['gecikmis_taksit']; }
