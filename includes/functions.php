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

// Aynı IP'den tüm hesaplara toplam deneme sınırı (password spraying önlemi)
const BF_IP_MAX = 20;

function bruteForceKontrol(string $kullanici_adi): bool {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        // 1) Hesap+IP bazlı sayaç
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM aktivite_loglari
             WHERE aksiyon='giris_basarisiz' AND ip_adresi=? AND detay=?
               AND created_at > DATE_SUB(NOW(), INTERVAL " . BF_PENCERE . " SECOND)"
        );
        $stmt->execute([$ip, $kullanici_adi]);
        if ((int)$stmt->fetchColumn() >= BF_MAX) return false;

        // 2) IP bazlı toplam sayaç — farklı kullanıcı adlarıyla spraying'i sınırlar
        $ipStmt = db()->prepare(
            "SELECT COUNT(*) FROM aktivite_loglari
             WHERE aksiyon='giris_basarisiz' AND ip_adresi=?
               AND created_at > DATE_SUB(NOW(), INTERVAL " . BF_PENCERE . " SECOND)"
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
    if (file_exists($cache) && (time() - filemtime($cache)) < 3600) {
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

// mysqldump'ı çalıştırır. Şifre komut satırı yerine geçici, 0600 izinli bir
// defaults dosyasından okunur; böylece `ps` çıktısında (process list) görünmez.
// $hataCikti referansına stderr yazılır (arayüzde mesaj için).
function mysqldumpCalistir(string $hedef, ?array &$hataCikti = null): bool {
    $cnf = tempnam(sys_get_temp_dir(), 'regal_my');
    if ($cnf === false) { $hataCikti = ['Geçici yapılandırma dosyası oluşturulamadı.']; return false; }
    chmod($cnf, 0600);
    file_put_contents($cnf,
        "[client]\nuser=\"" . str_replace('"', '\"', DB_USER) . "\"\n"
        . "password=\"" . str_replace('"', '\"', DB_PASS) . "\"\n");

    $cmd = escapeshellcmd('/opt/lampp/bin/mysqldump')
         . ' --defaults-extra-file=' . escapeshellarg($cnf)
         . ' ' . escapeshellarg(DB_NAME)
         . ' > ' . escapeshellarg($hedef) . ' 2>&1';
    exec($cmd, $hataCikti, $kod);
    unlink($cnf);
    return $kod === 0 && file_exists($hedef);
}

function otomatikYedekAl(): bool {
    $yedekDir = __DIR__ . '/../backups/';
    if (!is_dir($yedekDir)) mkdir($yedekDir, 0777, true);
    $dosyaAdi = 'regal_bayi_oto_' . date('Y-m-d_H-i-s') . '.sql';
    $hedef    = $yedekDir . $dosyaAdi;
    if (mysqldumpCalistir($hedef)) {
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
