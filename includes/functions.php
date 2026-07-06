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

    // Session timeout — Ayarlar → Sistem Geneli'nden yönetilir (dakika), .env'deki
    // SESSION_TIMEOUT yalnızca ayar hiç tanımlanmamışsa devreye giren geriye dönük varsayılandır.
    $zamanAsimiSaniye = (int)ayar('oturum_zaman_asimi_dakika', (string)(SESSION_TIMEOUT / 60)) * 60;
    if (!empty($_SESSION['son_aktivite']) && (time() - $_SESSION['son_aktivite']) > $zamanAsimiSaniye) {
        session_unset();
        session_destroy();
        startSecureSession();
        $_SESSION['flash'] = ['tip' => 'uyari', 'mesaj' => 'Oturum süreniz doldu. Lütfen tekrar giriş yapın.'];
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit;
    }
    $_SESSION['son_aktivite'] = time();

    // Tek oturum zorunluluğu: başka bir cihazdan giriş yapılınca bu oturum geçersiz olur
    if (ayar('tek_oturum_zorunlu','0') === '1' && !empty($_SESSION['oturum_token'])) {
        $guncelToken = db()->prepare("SELECT aktif_oturum_token FROM kullanicilar WHERE id=?");
        $guncelToken->execute([$_SESSION['kullanici_id']]);
        $guncelToken = $guncelToken->fetchColumn();
        if ($guncelToken && $guncelToken !== $_SESSION['oturum_token']) {
            session_unset();
            session_destroy();
            startSecureSession();
            $_SESSION['flash'] = ['tip' => 'uyari', 'mesaj' => 'Hesabınızla başka bir cihazdan giriş yapıldığı için bu oturum kapatıldı.'];
            header('Location: ' . BASE_URL . '/modules/auth/login.php');
            exit;
        }
    }

    // Şifre geçerlilik süresi doldu mu — profil sayfası hariç her yerde zorla yönlendir
    $gecerlilikGun = (int)ayar('sifre_gecerlilik_gun', '0');
    if ($gecerlilikGun > 0 && !str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/modules/kullanicilar/profil.php')) {
        $degisimTarihi = db()->prepare("SELECT sifre_degistirilme_tarihi FROM kullanicilar WHERE id=?");
        $degisimTarihi->execute([$_SESSION['kullanici_id']]);
        $degisimTarihi = $degisimTarihi->fetchColumn();
        if ($degisimTarihi && (time() - strtotime($degisimTarihi)) > $gecerlilikGun * 86400) {
            flash('uyari', 'Şifrenizin süresi doldu, devam etmeden önce lütfen şifrenizi değiştirin.');
            header('Location: ' . BASE_URL . '/modules/kullanicilar/profil.php');
            exit;
        }
    }

    // Bakım modu: yönetici dışındaki roller bakım sayfasına yönlendirilir
    if (bakimModuAktifMi() && ($_SESSION['rol'] ?? '') !== 'yonetici') {
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Bakımdayız</title>'
            . '<style>body{font-family:Arial,sans-serif;background:#f8f9fa;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}'
            . '.kutu{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:420px}'
            . 'h1{font-size:1.4rem;color:#212529}p{color:#6c757d}</style></head><body>'
            . '<div class="kutu"><h1>🛠 Sistem Bakımda</h1><p>' . htmlspecialchars(ayar('bakim_mesaji','Kısa süre sonra tekrar deneyin.')) . '</p></div>'
            . '</body></html>';
        exit;
    }
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

// Salt okunur modda yazma engellenmez sayfalar: ayarlar (kapatabilmek için),
// kendi şifresini değiştirme (güvenlik nedeniyle), giriş/çıkış.
const SALT_OKUNUR_ISTISNA = ['/modules/ayarlar/index.php', '/modules/kullanicilar/profil.php', '/modules/auth/login.php', '/modules/auth/logout.php'];

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

    // Salt okunur mod: birkaç istisna dışında tüm yazma işlemleri engellenir
    if (ayar('salt_okunur_mod','0') === '1') {
        $sayfa = $_SERVER['SCRIPT_NAME'] ?? '';
        $istisna = false;
        foreach (SALT_OKUNUR_ISTISNA as $yol) { if (str_ends_with($sayfa, $yol)) { $istisna = true; break; } }
        if (!$istisna) {
            flash('uyari', 'Sistem şu anda salt okunur modda — veri değiştirme işlemleri geçici olarak kapalı.');
            $hedef2   = BASE_URL . '/modules/dashboard/';
            $referer2 = $_SERVER['HTTP_REFERER'] ?? '';
            $host2 = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
            if ($referer2 && $host2 && (parse_url($referer2, PHP_URL_HOST) ?? '') === $host2) $hedef2 = $referer2;
            header('Location: ' . $hedef2);
            exit;
        }
    }
}

// ── Brute-force koruması (login için) ────────────────────────
// Sayaç DB'de tutulur (aktivite_loglari); session/cookie atılarak aşılamaz.
// Limit/pencere Ayarlar → Sistem Geneli'nden yönetilir (bf_max_deneme, bf_kilit_dakika).
function bfPencereSaniye(): int { return max(60, (int)ayar('bf_kilit_dakika','15') * 60); }
function bfMaxDeneme(): int { return max(1, (int)ayar('bf_max_deneme','5')); }

// Aynı IP'den tüm hesaplara toplam deneme sınırı (password spraying önlemi)
const BF_IP_MAX = 20;

function bruteForceKontrol(string $kullanici_adi): bool {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pencere = bfPencereSaniye();
        // 1) Hesap+IP bazlı sayaç
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM aktivite_loglari
             WHERE aksiyon='giris_basarisiz' AND ip_adresi=? AND detay=?
               AND created_at > DATE_SUB(NOW(), INTERVAL $pencere SECOND)"
        );
        $stmt->execute([$ip, $kullanici_adi]);
        if ((int)$stmt->fetchColumn() >= bfMaxDeneme()) return false;

        // 2) IP bazlı toplam sayaç — farklı kullanıcı adlarıyla spraying'i sınırlar
        $ipStmt = db()->prepare(
            "SELECT COUNT(*) FROM aktivite_loglari
             WHERE aksiyon='giris_basarisiz' AND ip_adresi=?
               AND created_at > DATE_SUB(NOW(), INTERVAL $pencere SECOND)"
        );
        $ipStmt->execute([$ip]);
        return (int)$ipStmt->fetchColumn() < BF_IP_MAX;
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
        $pencere = bfPencereSaniye();
        // Penceredeki en eski başarısız deneme süresi dolunca blokaj kalkar
        $stmt = db()->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) FROM aktivite_loglari
             WHERE aksiyon='giris_basarisiz' AND ip_adresi=? AND detay=?
               AND created_at > DATE_SUB(NOW(), INTERVAL $pencere SECOND)"
        );
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $kullanici_adi]);
        $gecen = (int)$stmt->fetchColumn();
        return max(0, $pencere - $gecen);
    } catch (Exception $e) { return 0; }
}

// ── Şifre doğrulama politikası (Ayarlar → Sistem Geneli'nden yönetilir) ──
function sifreDogrula(string $sifre): ?string {
    $minUzunluk = max(6, (int)ayar('sifre_min_uzunluk','8'));
    if (strlen($sifre) < $minUzunluk) return "Şifre en az $minUzunluk karakter olmalıdır.";
    if (ayar('sifre_buyuk_harf_zorunlu','1') === '1' && !preg_match('/[A-ZÇĞİÖŞÜ]/u', $sifre)) return 'Şifre en az bir büyük harf içermelidir.';
    if (ayar('sifre_kucuk_harf_zorunlu','1') === '1' && !preg_match('/[a-zçğışöüı]/u', $sifre)) return 'Şifre en az bir küçük harf içermelidir.';
    if (ayar('sifre_rakam_zorunlu','1') === '1' && !preg_match('/[0-9]/', $sifre)) return 'Şifre en az bir rakam içermelidir.';
    return null; // geçerli
}

// ── LIKE wildcard escape ──────────────────────────────────────
function likeParam(string $value): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

// ── Tarih girişi doğrulama (XSS + geçersiz format önlemi) ──────
// GET/POST'tan gelen tarih parametreleri hem SQL'e hem HTML'e gider;
// Y-m-d dışındaki her şeyi varsayılana düşürerek attribute injection'ı
// baştan keser (echo tarafında ayrıca escH gerekmez).
function gecerliTarih(?string $str, ?string $varsayilan = null): string {
    $varsayilan = $varsayilan ?? date('Y-m-d');
    if (!$str || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) || strtotime($str) === false) {
        return $varsayilan;
    }
    return $str;
}

// ── CSV hücre güvenliği (formül enjeksiyonu önlemi) ───────────
// =,+,-,@ ile başlayan hücreler Excel/Sheets'te formül olarak çalışır;
// başına tek tırnak eklenerek metin olmaya zorlanır.
function csvHucre($v): string {
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
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

// ── Sipariş no ────────────────────────────────────────────────
// Ay bazında MAX numara + 1; tedarikci_siparisleri.siparis_no UNIQUE.
function yeniSiparisNo() {
    $prefix = 'SIP' . date('Y') . date('m');
    $stmt = db()->prepare("SELECT MAX(CAST(SUBSTRING(siparis_no, ?) AS UNSIGNED)) FROM tedarikci_siparisleri WHERE siparis_no LIKE ?");
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

// ── Fiyat geçmişi ─────────────────────────────────────────────
// Alış/satış değişmediyse kayıt atmaz; hata uygulamayı kırmaz.
// $grup: aynı toplu işlemdeki kayıtları bağlar (geri alma için).
function fiyatGecmisiKaydet(int $urun_id, $eskiAlis, $yeniAlis, $eskiSatis, $yeniSatis, string $kaynak = 'duzenleme', ?string $grup = null): void {
    if ((float)$eskiAlis === (float)$yeniAlis && (float)$eskiSatis === (float)$yeniSatis) return;
    try {
        db()->prepare("INSERT INTO fiyat_gecmisi (urun_id,eski_alis,yeni_alis,eski_satis,yeni_satis,kaynak,islem_grubu,kullanici_id) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$urun_id, $eskiAlis, $yeniAlis, $eskiSatis, $yeniSatis, $kaynak, $grup, $_SESSION['kullanici_id'] ?? null]);
    } catch (Exception $e) {}
}

// ── Son gerçek alış maliyetleri ──────────────────────────────
// Her ürünün son maliyetli stok girişindeki birim maliyeti: [urun_id => maliyet]
function sonAlisMaliyetleri(): array {
    return db()->query("SELECT h.urun_id, h.birim_maliyet FROM stok_hareketleri h
        JOIN (SELECT urun_id, MAX(id) AS mid FROM stok_hareketleri
              WHERE hareket_tipi='giris' AND birim_maliyet IS NOT NULL GROUP BY urun_id) x ON x.mid=h.id")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ── Döviz kurları (TCMB — 1 saatlik cache) ───────────────────
function tcmbKurlari(): array {
    $cache = sys_get_temp_dir() . '/regal_tcmb.json';
    $cacheSaniye = max(60, (int)ayar('tcmb_kur_cache_dakika', '60') * 60);
    if (file_exists($cache) && (time() - filemtime($cache)) < $cacheSaniye) {
        return json_decode(file_get_contents($cache), true) ?: [];
    }
    if (!function_exists('curl_init')) return [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://www.tcmb.gov.tr/kurlar/today.xml',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'RegalBayi/1.0',
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    if (!$data) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($data);
    if (!$xml) return [];
    $hedef = ['USD' => 'ABD Doları', 'EUR' => 'Euro', 'GBP' => 'İngiliz Sterlini', 'CHF' => 'İsviçre Frangı'];
    $kurlar = [];
    foreach ($xml->Currency as $c) {
        $kod = (string)($c->attributes()['CurrencyCode'] ?? '');
        if (!isset($hedef[$kod])) continue;
        $alis  = (float)str_replace(',', '.', (string)$c->ForexBuying);
        $satis = (float)str_replace(',', '.', (string)$c->ForexSelling);
        if ($alis > 0 && $satis > 0) {
            $kurlar[$kod] = ['alis' => $alis, 'satis' => $satis, 'isim' => $hedef[$kod]];
        }
    }
    if ($kurlar) file_put_contents($cache, json_encode($kurlar));
    return $kurlar;
}

// ── Ürün görseli yükleme ─────────────────────────────────────
// Başarıda dosya adını (uploads/urunler/ altına göre), yüklenmediyse null döner;
// doğrulama hatasında Exception fırlatır. jpg/png/webp, en fazla 2 MB.
function urunResmiYukle(array $dosya, ?string $eskiResim = null): ?string {
    if (($dosya['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($dosya['error'] !== UPLOAD_ERR_OK) throw new Exception('Görsel yüklenemedi (hata kodu: ' . $dosya['error'] . ').');
    if ($dosya['size'] > 2 * 1024 * 1024) throw new Exception('Görsel en fazla 2 MB olabilir.');
    $bilgi = @getimagesize($dosya['tmp_name']);
    $izinli = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$bilgi || !isset($izinli[$bilgi[2]])) throw new Exception('Yalnızca JPG, PNG veya WEBP görsel yüklenebilir.');
    $dir = __DIR__ . '/../uploads/urunler/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $ad = 'urun_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $izinli[$bilgi[2]];
    if (!move_uploaded_file($dosya['tmp_name'], $dir . $ad)) throw new Exception('Görsel kaydedilemedi (dizin yazma izni).');
    if ($eskiResim && is_file($dir . basename($eskiResim))) @unlink($dir . basename($eskiResim));
    return $ad;
}

// ── Müşteri yardımcıları ─────────────────────────────────────
// Telefonu tek biçime indirger: 05XXXXXXXXX (+90/90/0 önekleri temizlenir)
function telefonNormalize(string $t): string {
    $t = preg_replace('/\D+/', '', $t);
    if (str_starts_with($t, '90') && strlen($t) === 12) $t = substr($t, 2);
    if (strlen($t) === 10 && $t[0] === '5') $t = '0' . $t;
    return $t;
}

// wa.me linki (mesaj opsiyonel); geçersiz telefonda null
function whatsappLink(string $telefon, string $mesaj = ''): ?string {
    $t = telefonNormalize($telefon);
    if (!preg_match('/^05\d{9}$/', $t)) return null;
    return 'https://wa.me/9' . $t . ($mesaj !== '' ? '?text=' . rawurlencode($mesaj) : '');
}

// TC Kimlik No algoritma doğrulaması
function tcKimlikGecerli(string $tc): bool {
    if (!preg_match('/^[1-9]\d{10}$/', $tc)) return false;
    $d = array_map('intval', str_split($tc));
    $t10 = ((($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7) - ($d[1] + $d[3] + $d[5] + $d[7])) % 10;
    if ($t10 < 0) $t10 += 10;
    return $t10 === $d[9] && (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
}

// Vergi Kimlik No algoritma doğrulaması (10 hane)
function vergiNoGecerli(string $v): bool {
    if (!preg_match('/^\d{10}$/', $v)) return false;
    $toplam = 0;
    for ($i = 0; $i < 9; $i++) {
        $d = ((int)$v[$i] + (9 - $i)) % 10;
        $toplam += ($d === 9) ? 9 : ($d * (2 ** (9 - $i))) % 9;
    }
    return ((10 - $toplam % 10) % 10) === (int)$v[9];
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

// ── Gider belgesi (fiş/fatura) yükleme ───────────────────────
function giderBelgesiYukle(array $dosya): ?string {
    if (($dosya['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($dosya['error'] !== UPLOAD_ERR_OK) throw new Exception('Belge yüklenemedi (hata kodu: ' . $dosya['error'] . ').');
    if ($dosya['size'] > 5 * 1024 * 1024) throw new Exception('Belge en fazla 5 MB olabilir.');
    $ext = strtolower(pathinfo($dosya['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp'], true)) {
        $bilgi = @getimagesize($dosya['tmp_name']);
        if (!$bilgi) throw new Exception('Geçersiz görsel dosyası.');
    } elseif ($ext !== 'pdf') {
        throw new Exception('Yalnızca JPG, PNG, WEBP veya PDF yüklenebilir.');
    }
    $dir = __DIR__ . '/../uploads/kasa/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $ad = 'gider_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($dosya['tmp_name'], $dir . $ad)) throw new Exception('Belge kaydedilemedi (dizin yazma izni).');
    return $ad;
}

// ── Kasa / Finans ─────────────────────────────────────────────
// Yalnızca onaylanmış hareketler bakiyeye dahil edilir (bekleyen/reddedilen giderler hariç).
function kasaBakiyesi(string $hesap = 'kasa'): float {
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE -tutar END),0)
        FROM kasa_hareketleri WHERE hesap=? AND onay_durumu='onaylandi'");
    $stmt->execute([$hesap]);
    return (float)$stmt->fetchColumn();
}

// ── Tekrarlayan gider şablonları ──────────────────────────────
// Vadesi gelmiş (bu dönem için henüz oluşturulmamış) aktif şablonları döner.
function giderSablonlariVadesiGelenler(): array {
    if (ayar('otomasyon_aktif','1') !== '1') return [];
    $sablonlar = db()->query("SELECT gs.*, kk.ad AS kategori_adi FROM gider_sablonlari gs
        JOIN kasa_kategoriler kk ON gs.kategori_id=kk.id WHERE gs.aktif=1")->fetchAll();
    $bugun = new DateTime();
    $sonuc = [];
    foreach ($sablonlar as $s) {
        $gun = max(1, (int)$s['gun']);
        if ($s['periyot'] === 'aylik') {
            $gun = min($gun, (int)$bugun->format('t'));
            $hedef = new DateTime($bugun->format('Y-m-') . str_pad($gun, 2, '0', STR_PAD_LEFT));
        } else { // haftalik — gun: 1=Pazartesi..7=Pazar (ISO)
            $hedef = clone $bugun;
            $fark = $gun - (int)$bugun->format('N');
            $hedef->modify(($fark >= 0 ? '+' : '') . $fark . ' days');
        }
        if ($hedef > $bugun) continue; // bu dönemin vadesi henüz gelmedi
        $sonOlusturma = $s['son_olusturma'] ? new DateTime($s['son_olusturma']) : null;
        if ($sonOlusturma && $sonOlusturma >= $hedef) continue; // bu dönem için zaten oluşturulmuş
        $s['hedef_tarih'] = $hedef->format('Y-m-d');
        $sonuc[] = $s;
    }
    return $sonuc;
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

// Değer gerçekten değiştiyse ayar_gecmisi'ne kaydeder (gürültü olmasın diye).
function ayarKaydet(string $anahtar, string $deger, ?string $not = null): void {
    $eski = ayar($anahtar, '');
    db()->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES (?,?) ON DUPLICATE KEY UPDATE deger=?, updated_at=NOW()")
        ->execute([$anahtar, $deger, $deger]);
    if (isset($GLOBALS['__ayar_cache'])) $GLOBALS['__ayar_cache'][$anahtar] = $deger;
    if ($eski !== $deger) {
        try {
            db()->prepare("INSERT INTO ayar_gecmisi (anahtar,eski_deger,yeni_deger,not_metni,kullanici_id) VALUES (?,?,?,?,?)")
                ->execute([$anahtar, $eski, $deger, $not, $_SESSION['kullanici_id'] ?? null]);
        } catch (Exception $e) {}
    }
}

// ── Marka görseli yükleme (logo/favicon/arkaplan/kaşe) ───────
function markaGorseliYukle(array $dosya, ?string $eskiDosya = null): ?string {
    if (($dosya['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($dosya['error'] !== UPLOAD_ERR_OK) throw new Exception('Görsel yüklenemedi (hata kodu: ' . $dosya['error'] . ').');
    if ($dosya['size'] > 2 * 1024 * 1024) throw new Exception('Görsel en fazla 2 MB olabilir.');
    $bilgi = @getimagesize($dosya['tmp_name']);
    $izinli = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$bilgi || !isset($izinli[$bilgi[2]])) throw new Exception('Yalnızca JPG, PNG, WEBP veya GIF yüklenebilir.');
    $dir = __DIR__ . '/../uploads/marka/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $ad = 'marka_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $izinli[$bilgi[2]];
    if (!move_uploaded_file($dosya['tmp_name'], $dir . $ad)) throw new Exception('Görsel kaydedilemedi (dizin yazma izni).');
    if ($eskiDosya && is_file($dir . basename($eskiDosya))) @unlink($dir . basename($eskiDosya));
    return $ad;
}

// ── Bakım modu ────────────────────────────────────────────────
function bakimModuAktifMi(): bool {
    return ayar('bakim_modu', '0') === '1';
}

function saltOkunurMuAktif(): bool {
    return ayar('salt_okunur_mod', '0') === '1';
}

// ── Modül açma/kapama ─────────────────────────────────────────
// Yalnızca opsiyonel/ek modüller kapatılabilir; çekirdek satış/stok/müşteri/kasa her zaman aktiftir.
function moduleAktifMi(string $modul): bool {
    return ayar('modul_' . $modul . '_aktif', '1') === '1';
}

// Modül kapalıysa kullanıcıyı bilgilendirip dashboard'a yönlendirir.
function moduleKontrol(string $modul, string $ad): void {
    if (!moduleAktifMi($modul)) {
        flash('uyari', "$ad modülü şu anda devre dışı bırakılmış (Ayarlar → Sistem Geneli).");
        header('Location: ' . BASE_URL . '/modules/dashboard/');
        exit;
    }
}

// ── Veri maskeleme (demo/eğitim modu) ─────────────────────────
function veriMaskele(?string $deger, int $sonKarakter = 2): string {
    if (!$deger) return '';
    if (ayar('veri_maskeleme_aktif','0') !== '1') return $deger;
    $uzunluk = mb_strlen($deger);
    if ($uzunluk <= $sonKarakter) return str_repeat('•', $uzunluk);
    return str_repeat('•', $uzunluk - $sonKarakter) . mb_substr($deger, -$sonKarakter);
}

// ── Ayarları doğrula: bilinen mantıksal çelişki/bağımlılıkları tarar ──
function ayarlariDogrula(): array {
    $sorunlar = [];
    if ((float)ayar('kasiyer_max_indirim','0') > 100) $sorunlar[] = 'Kasiyer maksimum indirim %100\'den büyük olamaz.';
    if ((float)ayar('taksit_erken_odeme_indirim','0') > 100) $sorunlar[] = 'Erken ödeme indirimi %100\'den büyük olamaz.';
    if (ayar('gider_onay_limiti','0') === '0') $sorunlar[] = 'Gider onay limiti kapalı (0) — kasiyerler sınırsız gider girebilir, onay bekleyen kayıt oluşmaz.';
    if (ayar('kasa_min_bakiye_uyari','0') === '0') $sorunlar[] = 'Düşük kasa bakiyesi uyarısı kapalı (0) — kasa boşalsa da dashboard uyarı vermez.';
    $mesaiB = ayar('mesai_baslangic','09:00'); $mesaiS = ayar('mesai_bitis','19:00');
    if ($mesaiB >= $mesaiS) $sorunlar[] = 'Mesai başlangıç saati bitiş saatinden önce olmalı.';
    if (!in_array(ayar('zaman_dilimi','Europe/Istanbul'), timezone_identifiers_list(), true)) $sorunlar[] = 'Geçersiz zaman dilimi tanımlı.';
    $gunler = array_filter(explode(',', ayar('calisma_gunleri','')));
    if (empty($gunler)) $sorunlar[] = 'Hiçbir çalışma günü seçilmemiş.';
    if (bakimModuAktifMi()) $sorunlar[] = 'Bakım modu şu anda AÇIK — yönetici dışındaki kullanıcılar sisteme giremiyor.';
    if (saltOkunurMuAktif()) $sorunlar[] = 'Salt okunur mod şu anda AÇIK — hiç kimse veri değiştiremiyor.';
    if ((int)ayar('sifre_min_uzunluk','8') < 6) $sorunlar[] = 'Şifre minimum uzunluğu çok düşük (6 karakterin altında).';
    if ((int)ayar('bf_max_deneme','5') > 15) $sorunlar[] = 'Brute-force deneme limiti çok yüksek (15\'in üzerinde) — kaba kuvvet saldırılarına karşı zayıf koruma.';
    if ((int)ayar('oturum_zaman_asimi_dakika','30') > 240) $sorunlar[] = 'Oturum zaman aşımı çok uzun (4 saatin üzerinde) — bilgisayar başında bırakılan oturumlar risk oluşturabilir.';
    try {
        $diskEsik = (float)ayar('disk_uyari_esik_gb','1');
        $diskBos = @disk_free_space(__DIR__ . '/..');
        if ($diskEsik > 0 && $diskBos !== false && ($diskBos / 1073741824) < $diskEsik) {
            $sorunlar[] = 'Sunucu disk boş alanı kritik seviyede (' . number_format($diskBos / 1073741824, 2) . ' GB).';
        }
    } catch (Exception $e) {}
    return $sorunlar;
}

// Uygulama genelinde zaman dilimini ayarlardan uygula (her sayfa yüklemesinde bir kez)
try { date_default_timezone_set(ayar('zaman_dilimi', 'Europe/Istanbul')); } catch (Exception $e) {}

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

// mysqldump'ı çalıştırır. Şifre komut satırı yerine geçici, 0600 izinli bir
// defaults dosyasından okunur; böylece `ps` çıktısında (process list) görünmez.
// $hataCikti referansına stderr yazılır (arayüzde mesaj için).
function mysqldumpCalistir(string $hedef, ?array &$hataCikti = null, array $opts = []): bool {
    $cnf = tempnam(sys_get_temp_dir(), 'regal_my');
    if ($cnf === false) { $hataCikti = ['Geçici yapılandırma dosyası oluşturulamadı.']; return false; }
    chmod($cnf, 0600);
    file_put_contents($cnf,
        "[client]\nuser=\"" . str_replace('"', '\"', DB_USER) . "\"\n"
        . "password=\"" . str_replace('"', '\"', DB_PASS) . "\"\n");

    $ekParam = '';
    if (!empty($opts['sadece_sema'])) {
        $ekParam .= ' --no-data';
    }
    foreach ($opts['haric_tablolar'] ?? [] as $tablo) {
        $tablo = preg_replace('/[^a-zA-Z0-9_]/', '', $tablo);
        if ($tablo !== '') $ekParam .= ' --ignore-table=' . escapeshellarg(DB_NAME . '.' . $tablo);
    }

    $cmd = escapeshellcmd('/opt/lampp/bin/mysqldump')
         . ' --defaults-extra-file=' . escapeshellarg($cnf)
         . $ekParam
         . ' ' . escapeshellarg(DB_NAME)
         . ' > ' . escapeshellarg($hedef) . ' 2>&1';
    exec($cmd, $hataCikti, $kod);
    unlink($cnf);
    return $kod === 0 && file_exists($hedef);
}

// .sql dosyasını mevcut veritabanına geri yükler (DESTRUCTIVE — çağıran taraf onay almalı)
function mysqlRestoreCalistir(string $dosya, ?array &$hataCikti = null, ?string $hedefDb = null): bool {
    $cnf = tempnam(sys_get_temp_dir(), 'regal_my');
    if ($cnf === false) { $hataCikti = ['Geçici yapılandırma dosyası oluşturulamadı.']; return false; }
    chmod($cnf, 0600);
    file_put_contents($cnf,
        "[client]\nuser=\"" . str_replace('"', '\"', DB_USER) . "\"\n"
        . "password=\"" . str_replace('"', '\"', DB_PASS) . "\"\n");

    $cmd = escapeshellcmd('/opt/lampp/bin/mysql')
         . ' --defaults-extra-file=' . escapeshellarg($cnf)
         . ' ' . escapeshellarg($hedefDb ?? DB_NAME)
         . ' < ' . escapeshellarg($dosya) . ' 2>&1';
    exec($cmd, $hataCikti, $kod);
    unlink($cnf);
    return $kod === 0;
}

// Yedek sıklığı ayarına göre gün aralığı (gunluk/haftalik/aylik)
function yedekSiklikGun(): int {
    return ['gunluk' => 1, 'haftalik' => 7, 'aylik' => 30][ayar('yedek_sikligi', 'haftalik')] ?? 7;
}

function otomatikYedekAl(): bool {
    $yedekDir = __DIR__ . '/../backups/';
    if (!is_dir($yedekDir)) mkdir($yedekDir, 0777, true);
    $dosyaAdi = 'regal_bayi_oto_' . date('Y-m-d_H-i-s') . '.sql';
    $hedef    = $yedekDir . $dosyaAdi;
    $basarili = mysqldumpCalistir($hedef);
    if ($basarili) {
        ayarKaydet('son_oto_yedek', date('Y-m-d H:i:s'));
        yedekIslemSonrasi($hedef, 'otomatik', 'tam', false);
    }
    return $basarili;
}

// ── Yedek dosyası işleme: sıkıştırma / şifreleme / ikinci konum / geçmiş kaydı ──
function yedekSikistir(string $dosya): string {
    $hedef = $dosya . '.gz';
    $in = fopen($dosya, 'rb'); $out = gzopen($hedef, 'wb9');
    if (!$in || !$out) return $dosya;
    while (!feof($in)) gzwrite($out, fread($in, 1024 * 512));
    fclose($in); gzclose($out);
    unlink($dosya);
    return $hedef;
}

function yedekAcSikistirma(string $dosyaGz): string {
    $hedef = tempnam(sys_get_temp_dir(), 'regal_acik') . '.sql';
    $in = gzopen($dosyaGz, 'rb'); $out = fopen($hedef, 'wb');
    while (!gzeof($in)) fwrite($out, gzread($in, 1024 * 512));
    gzclose($in); fclose($out);
    return $hedef;
}

function yedekSifrele(string $dosya): string {
    if (!BACKUP_ENCRYPTION_KEY) return $dosya;
    $hedef = $dosya . '.enc';
    $iv = random_bytes(16);
    $veri = file_get_contents($dosya);
    $sifreli = openssl_encrypt($veri, 'aes-256-cbc', hex2bin(BACKUP_ENCRYPTION_KEY), OPENSSL_RAW_DATA, $iv);
    if ($sifreli === false) return $dosya;
    file_put_contents($hedef, $iv . $sifreli);
    unlink($dosya);
    return $hedef;
}

function yedekSifreCoz(string $dosyaEnc): ?string {
    if (!BACKUP_ENCRYPTION_KEY) return null;
    $icerik = file_get_contents($dosyaEnc);
    if ($icerik === false || strlen($icerik) < 17) return null;
    $iv = substr($icerik, 0, 16);
    $sifreli = substr($icerik, 16);
    $cozulmus = openssl_decrypt($sifreli, 'aes-256-cbc', hex2bin(BACKUP_ENCRYPTION_KEY), OPENSSL_RAW_DATA, $iv);
    if ($cozulmus === false) return null;
    $hedef = tempnam(sys_get_temp_dir(), 'regal_coz');
    file_put_contents($hedef, $cozulmus);
    return $hedef;
}

// uploads/ klasörünü (ve istenirse ek dosyaları, örn. DB dump'ını) tek bir zip'e paketler
function uploadsYedekle(string $zipHedef, array $ekDosyalar = []): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipHedef, ZipArchive::CREATE) !== true) return false;
    foreach ($ekDosyalar as $goreliAd => $gercekYol) {
        if (is_file($gercekYol)) $zip->addFile($gercekYol, $goreliAd);
    }
    $kaynak = realpath(__DIR__ . '/../uploads');
    if ($kaynak !== false) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kaynak, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $dosya) {
            $goreliYol = 'uploads/' . substr($dosya->getPathname(), strlen($kaynak) + 1);
            $zip->addFile($dosya->getPathname(), $goreliYol);
        }
    }
    $zip->close();
    return true;
}

function yedekGecmisiKaydet(array $veri): int {
    db()->prepare(
        "INSERT INTO yedekleme_gecmisi (dosya_adi,tip,kapsam,dosyalar_dahil,sikistirilmis,sifreli,boyut_bayt,sha256,not_metni,basarili,hata_metni,ikinci_konum_kopyalandi,kullanici_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $veri['dosya_adi'], $veri['tip'] ?? 'manuel', $veri['kapsam'] ?? 'tam',
        (int)($veri['dosyalar_dahil'] ?? 0), (int)($veri['sikistirilmis'] ?? 0), (int)($veri['sifreli'] ?? 0),
        $veri['boyut_bayt'] ?? 0, $veri['sha256'] ?? null, $veri['not_metni'] ?? null,
        (int)($veri['basarili'] ?? 1), $veri['hata_metni'] ?? null, (int)($veri['ikinci_konum_kopyalandi'] ?? 0),
        $veri['kullanici_id'] ?? ($_SESSION['kullanici_id'] ?? null),
    ]);
    return (int)db()->lastInsertId();
}

// Yedek alındıktan sonra ortak akış: checksum, ikinci konuma kopyalama, geçmiş kaydı, anormal boyut kontrolü
function yedekIslemSonrasi(string $dosyaYolu, string $tip, string $kapsam, bool $dosyalarDahil, ?string $not = null): array {
    $boyut = filesize($dosyaYolu);
    $sha256 = hash_file('sha256', $dosyaYolu);
    $ikinciKonumOk = yedekIkinciKonumaKopyala($dosyaYolu);

    $uyari = null;
    $ortalama = db()->query("SELECT AVG(boyut_bayt) FROM (SELECT boyut_bayt FROM yedekleme_gecmisi WHERE basarili=1 ORDER BY id DESC LIMIT 5) t")->fetchColumn();
    if ($ortalama && $ortalama > 0 && $boyut < $ortalama * 0.5) {
        $uyari = 'Bu yedek, önceki yedeklerin ortalama boyutunun (' . round($ortalama / 1024, 1) . ' KB) belirgin şekilde altında (' . round($boyut / 1024, 1) . ' KB) — dump yarım kalmış olabilir.';
    }

    $id = yedekGecmisiKaydet([
        'dosya_adi' => basename($dosyaYolu), 'tip' => $tip, 'kapsam' => $kapsam,
        'dosyalar_dahil' => $dosyalarDahil, 'sikistirilmis' => str_ends_with($dosyaYolu, '.gz') || str_contains($dosyaYolu, '.gz.'),
        'sifreli' => str_ends_with($dosyaYolu, '.enc'), 'boyut_bayt' => $boyut, 'sha256' => $sha256,
        'not_metni' => $not, 'basarili' => 1, 'ikinci_konum_kopyalandi' => $ikinciKonumOk,
    ]);
    logla('yedek_alindi', 'yedekleme', $id, basename($dosyaYolu) . ' (' . round($boyut / 1024, 1) . ' KB)');
    return ['id' => $id, 'uyari' => $uyari, 'sha256' => $sha256, 'boyut' => $boyut];
}

function yedekIkinciKonumaKopyala(string $dosyaYolu): bool {
    $konum = trim(ayar('yedek_ikinci_konum', ''));
    if ($konum === '') return false;
    if (!is_dir($konum) || !is_writable($konum)) return false;
    return @copy($dosyaYolu, rtrim($konum, '/') . '/' . basename($dosyaYolu));
}

// Saklama politikası: gün ve/veya adet sınırına göre eski yedekleri siler.
// Her ayın ilk günü alınan yedekler (dosya adındaki tarihe göre) uzun vadeli arşiv sayılıp korunur.
function yedekTemizle(): int {
    $yedekDir = __DIR__ . '/../backups/';
    $gun = (int)ayar('yedek_saklama_gun', '90');
    $adet = (int)ayar('yedek_saklama_adet', '0');
    if ($gun <= 0 && $adet <= 0) return 0;

    $dosyalar = [];
    foreach (glob($yedekDir . '*.{sql,sql.gz,sql.gz.enc,sql.enc}', GLOB_BRACE) ?: [] as $d) {
        $dosyalar[] = ['yol' => $d, 'zaman' => filemtime($d)];
    }
    usort($dosyalar, fn($a, $b) => $b['zaman'] - $a['zaman']);

    $silinen = 0;
    foreach ($dosyalar as $i => $d) {
        $ayinIlkGunu = date('d', $d['zaman']) === '01';
        if ($ayinIlkGunu) continue; // arşiv niteliğinde, korunur
        $eski = $gun > 0 && (time() - $d['zaman']) > $gun * 86400;
        $fazlaAdet = $adet > 0 && $i >= $adet;
        if ($eski || $fazlaAdet) {
            @unlink($d['yol']);
            db()->prepare("DELETE FROM yedekleme_gecmisi WHERE dosya_adi=?")->execute([basename($d['yol'])]);
            $silinen++;
        }
    }
    return $silinen;
}

// Yedek dosyasını (gerekirse şifre çöz + aç) geçici bir veritabanına deneme yükler, gerçek veriye dokunmaz.
function yedekTestGeriYukle(string $dosyaYolu): array {
    $calismaDosyasi = yedekDuzMetneCevir($dosyaYolu);
    if (!$calismaDosyasi) return ['basarili' => false, 'mesaj' => 'Dosya çözülemedi (şifreleme anahtarı eksik/uyuşmuyor ya da format tanınmadı).'];
    try {
        $testDb = 'regal_test_restore_' . bin2hex(random_bytes(4));
        db()->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4");
        $hata = [];
        $basarili = mysqlRestoreCalistir($calismaDosyasi, $hata, $testDb);
        db()->exec("DROP DATABASE `$testDb`");

        return $basarili
            ? ['basarili' => true, 'mesaj' => 'Test geri yükleme başarılı — dosya bütünlüğü doğrulandı (gerçek veriye dokunulmadı).']
            : ['basarili' => false, 'mesaj' => 'Test geri yükleme başarısız: ' . implode(' ', array_slice($hata, -3))];
    } catch (Exception $e) {
        return ['basarili' => false, 'mesaj' => 'Hata: ' . $e->getMessage()];
    } finally {
        @unlink($calismaDosyasi);
    }
}

// Bir .sql dump içeriğini tarayıp tablo başına yaklaşık satır sayısı çıkarır
// (mysqldump --extended-insert formatındaki "),(" ayraçlarını sayar; tam kesin değildir ama yeterince yakındır).
function yedekTabloSatirTahmini(string $sqlIcerik): array {
    $sonuc = [];
    if (preg_match_all('/INSERT INTO `([a-zA-Z0-9_]+)`[^;]*VALUES\s*(.+?);/is', $sqlIcerik, $eslesmeler, PREG_SET_ORDER)) {
        foreach ($eslesmeler as $e) {
            $tablo = $e[1];
            $adet = substr_count($e[2], '),(') + 1;
            $sonuc[$tablo] = ($sonuc[$tablo] ?? 0) + $adet;
        }
    }
    return $sonuc;
}

// "Dosyaları dahil et" ile alınmış birleşik yedek zip'inden veritabanı dump'ını çıkarır
function yedekZipIcindenSqlCikar(string $zipYolu): ?string {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($zipYolu) !== true) return null;
    $icerik = $zip->getFromName('regal_bayi_dump.sql');
    $zip->close();
    if ($icerik === false) return null;
    $hedef = tempnam(sys_get_temp_dir(), 'regal_zipsql') . '.sql';
    file_put_contents($hedef, $icerik);
    return $hedef;
}

// Yedek dosyasını (şifreliyse çözer, sıkıştırılmışsa açar, zip ise dump'ı çıkarır) geçici düz .sql olarak döndürür.
// Çağıran taraf işi bitince dönen yolu silmelidir. Çözülemezse null döner.
function yedekDuzMetneCevir(string $dosyaYolu): ?string {
    // Not: her adımın çıktısı tempnam() ile isimlendirildiğinden dosya adı orijinal
    // uzantıyı taşımaz — bu yüzden hangi dönüşümün uygulanacağına ORİJİNAL dosya
    // adındaki uzantı zincirine bakarak karar veriyoruz, ara dosyanın adına değil.
    $ad = strtolower($dosyaYolu);
    $calisma = $dosyaYolu; $gecici = null;
    if (str_ends_with($ad, '.enc')) {
        $calisma = yedekSifreCoz($calisma);
        if (!$calisma) return null;
        $gecici = $calisma;
        $ad = substr($ad, 0, -4);
    }
    if (str_ends_with($ad, '.gz')) {
        $acilan = yedekAcSikistirma($calisma);
        if ($gecici) @unlink($gecici);
        $calisma = $acilan; $gecici = $acilan;
        $ad = substr($ad, 0, -3);
    }
    if (str_ends_with($ad, '.zip')) {
        $cikan = yedekZipIcindenSqlCikar($calisma);
        if ($gecici) @unlink($gecici);
        if (!$cikan) return null;
        $calisma = $cikan; $gecici = $cikan;
    }
    if ($calisma === $dosyaYolu) {
        // Ne şifreli ne sıkıştırılmış ne zip — orijinali kopyala ki çağıran taraf güvenle silebilsin
        $kopya = tempnam(sys_get_temp_dir(), 'regal_duz');
        copy($dosyaYolu, $kopya);
        return $kopya;
    }
    return $calisma;
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
                WHERE tp.odendi=0 AND tp.vade_tarihi < CURDATE() AND s.durum != 'iptal') AS gecikmis_taksit,
              (SELECT COUNT(*) FROM kasa_hareketleri WHERE onay_durumu='bekliyor') AS onay_bekleyen
        ")->fetch();
        $cache = [
            'dusuk_stok'      => (int)($row['dusuk_stok'] ?? 0),
            'bekleyen_odeme'  => (int)($row['bekleyen_odeme'] ?? 0),
            'gecikmis_taksit' => (int)($row['gecikmis_taksit'] ?? 0),
            'onay_bekleyen'   => (int)($row['onay_bekleyen'] ?? 0),
        ];
    } catch (Exception $e) {
        $cache = ['dusuk_stok' => 0, 'bekleyen_odeme' => 0, 'gecikmis_taksit' => 0, 'onay_bekleyen' => 0];
    }
    return $cache;
}

function minStokUyarilari(): int   { return bildirimSayaclari()['dusuk_stok']; }
function bekleyenTahsilat(): int   { return bildirimSayaclari()['bekleyen_odeme']; }
function gecikmisTaksitSayisi(): int { return bildirimSayaclari()['gecikmis_taksit']; }
function onayBekleyenGiderSayisi(): int { return bildirimSayaclari()['onay_bekleyen']; }
