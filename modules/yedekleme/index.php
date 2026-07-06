<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Yedekleme';
$pdo = db();

$yedekDir = __DIR__ . '/../../backups/';
if (!is_dir($yedekDir)) mkdir($yedekDir, 0755, true);

$hata = '';
$restoreOnizleme = $_SESSION['restore_onizleme'] ?? null;
$restoreRapor = $_SESSION['restore_rapor'] ?? null;
unset($_SESSION['restore_rapor']);
$karsilastirmaSonuc = $_SESSION['yedek_karsilastirma'] ?? null;
unset($_SESSION['yedek_karsilastirma']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    // ── Yedek al ──────────────────────────────────────────────
    if ($aksiyon === 'yedek_al') {
        $kapsam = in_array($_POST['kapsam'] ?? '', ['tam','hizli','sadece_sema'], true) ? $_POST['kapsam'] : 'tam';
        $dosyalarDahil = isset($_POST['dosyalar_dahil']);
        $sikistir = isset($_POST['sikistir']);
        $sifrele = isset($_POST['sifrele']) && BACKUP_ENCRYPTION_KEY;
        $not = trim($_POST['not'] ?? '') ?: null;

        $opts = [];
        if ($kapsam === 'sadece_sema') $opts['sadece_sema'] = true;
        if ($kapsam === 'hizli') $opts['haric_tablolar'] = array_filter(array_map('trim', explode(',', ayar('yedek_haric_tablolar', ''))));

        $dosyaAdi = 'regal_bayi_' . date('Y-m-d_H-i-s') . '.sql';
        $hedef = $yedekDir . $dosyaAdi;
        $cikti = [];
        if (!mysqldumpCalistir($hedef, $cikti, $opts)) {
            yedekGecmisiKaydet(['dosya_adi' => $dosyaAdi, 'kapsam' => $kapsam, 'basarili' => 0, 'hata_metni' => implode(' ', $cikti)]);
            flash('hata', 'Yedek alınamadı: ' . implode(' ', $cikti));
            header('Location: index.php'); exit;
        }

        if ($dosyalarDahil) {
            $zipHedef = $yedekDir . 'regal_bayi_' . date('Y-m-d_H-i-s') . '.zip';
            uploadsYedekle($zipHedef, ['regal_bayi_dump.sql' => $hedef]);
            unlink($hedef);
            $hedef = $zipHedef;
        } elseif ($sikistir) {
            $hedef = yedekSikistir($hedef);
        }
        if ($sifrele) {
            $hedef = yedekSifrele($hedef);
        }

        $sonuc = yedekIslemSonrasi($hedef, 'manuel', $kapsam, $dosyalarDahil, $not);
        flash('basari', 'Yedek alındı: ' . basename($hedef) . ' (' . round($sonuc['boyut'] / 1024, 1) . ' KB). İndirmek için listeden "İndir" butonunu kullanın.');
        if ($sonuc['uyari']) flash('uyari', $sonuc['uyari']);
        header('Location: index.php'); exit;
    }

    // ── Yedeği sil ────────────────────────────────────────────
    if ($aksiyon === 'sil') {
        $dosya = basename($_POST['dosya'] ?? '');
        $yol = $yedekDir . $dosya;
        if ($dosya && file_exists($yol) && preg_match('/\.(sql|sql\.gz|sql\.gz\.enc|sql\.enc|zip)$/', $dosya)) {
            unlink($yol);
            $pdo->prepare("DELETE FROM yedekleme_gecmisi WHERE dosya_adi=?")->execute([$dosya]);
            logla('yedek_silindi', 'yedekleme', 0, $dosya);
            flash('basari', 'Yedek silindi.');
        }
        header('Location: index.php'); exit;
    }

    // ── Not güncelle ──────────────────────────────────────────
    if ($aksiyon === 'not_guncelle') {
        $id = (int)($_POST['gecmis_id'] ?? 0);
        $not = trim($_POST['not'] ?? '');
        $pdo->prepare("UPDATE yedekleme_gecmisi SET not_metni=? WHERE id=?")->execute([$not ?: null, $id]);
        flash('basari', 'Not güncellendi.');
        header('Location: index.php'); exit;
    }

    // ── Şimdi temizle (saklama politikası) ───────────────────
    if ($aksiyon === 'temizle_simdi') {
        $adet = yedekTemizle();
        flash('basari', $adet > 0 ? "$adet eski yedek saklama politikasına göre silindi." : 'Silinecek eski yedek yok.');
        header('Location: index.php'); exit;
    }

    // ── Yedekleme ayarlarını kaydet ──────────────────────────
    if ($aksiyon === 'ayarlar_kaydet') {
        ayarKaydet('yedek_sikligi', in_array($_POST['yedek_sikligi'] ?? '', ['gunluk','haftalik','aylik'], true) ? $_POST['yedek_sikligi'] : 'haftalik');
        ayarKaydet('yedek_saklama_gun', (string)max(0, (int)($_POST['yedek_saklama_gun'] ?? 90)));
        ayarKaydet('yedek_saklama_adet', (string)max(0, (int)($_POST['yedek_saklama_adet'] ?? 0)));
        ayarKaydet('yedek_ikinci_konum', trim($_POST['yedek_ikinci_konum'] ?? ''));
        ayarKaydet('yedek_dosyalari_dahil', isset($_POST['yedek_dosyalari_dahil']) ? '1' : '0');
        ayarKaydet('yedek_sifrele', isset($_POST['yedek_sifrele']) ? '1' : '0');
        ayarKaydet('yedek_haric_tablolar', trim($_POST['yedek_haric_tablolar'] ?? ''));
        flash('basari', 'Yedekleme ayarları kaydedildi.');
        header('Location: index.php'); exit;
    }

    // ── Test geri yükleme ────────────────────────────────────
    if ($aksiyon === 'test_geri_yukle') {
        $dosya = basename($_POST['dosya'] ?? '');
        $yol = $yedekDir . $dosya;
        if (!file_exists($yol)) {
            flash('hata', 'Dosya bulunamadı.');
        } else {
            $sonuc = yedekTestGeriYukle($yol);
            flash($sonuc['basarili'] ? 'basari' : 'hata', $sonuc['mesaj']);
        }
        header('Location: index.php'); exit;
    }

    // ── Geri yükleme önizleme ─────────────────────────────────
    if ($aksiyon === 'restore_onizle') {
        $dosya = basename($_POST['dosya'] ?? '');
        $yol = $yedekDir . $dosya;
        if (!file_exists($yol)) {
            flash('hata', 'Dosya bulunamadı.');
        } else {
            $duz = yedekDuzMetneCevir($yol);
            $tablolar = [];
            if ($duz) {
                $tablolar = yedekTabloSatirTahmini(file_get_contents($duz));
                @unlink($duz);
            }
            $kayit = $pdo->prepare("SELECT * FROM yedekleme_gecmisi WHERE dosya_adi=?");
            $kayit->execute([$dosya]);
            $kayit = $kayit->fetch();
            $_SESSION['restore_onizleme'] = [
                'dosya' => $dosya, 'tablolar' => $tablolar, 'kayit' => $kayit,
                'boyut' => filesize($yol), 'tarih' => filemtime($yol),
            ];
        }
        header('Location: index.php#restore'); exit;
    }

    if ($aksiyon === 'restore_iptal') {
        unset($_SESSION['restore_onizleme']);
        header('Location: index.php'); exit;
    }

    // ── Geri yükleme uygula (DESTRUCTIVE) ────────────────────
    if ($aksiyon === 'restore_uygula') {
        $dosya = basename($_POST['dosya'] ?? '');
        $yol = $yedekDir . $dosya;
        $sifreOK = false;
        if (!empty($_POST['onay_sifre_restore'])) {
            $kul = $pdo->prepare("SELECT sifre FROM kullanicilar WHERE id=?");
            $kul->execute([$_SESSION['kullanici_id']]);
            $sifreOK = password_verify($_POST['onay_sifre_restore'], (string)$kul->fetchColumn());
        }
        if (!$sifreOK) {
            flash('hata', 'Geri yükleme için şifrenizi doğru girmelisiniz.');
            header('Location: index.php#restore'); exit;
        }
        if (!file_exists($yol)) {
            flash('hata', 'Dosya bulunamadı.');
            header('Location: index.php'); exit;
        }

        // 1) Otomatik güvenlik yedeği (restore öncesi mevcut durumu koru)
        $guvenlikDosya = $yedekDir . 'regal_bayi_restore_oncesi_' . date('Y-m-d_H-i-s') . '.sql';
        $guvenlikCikti = [];
        $guvenlikBasarili = mysqldumpCalistir($guvenlikDosya, $guvenlikCikti);
        if ($guvenlikBasarili) yedekIslemSonrasi($guvenlikDosya, 'otomatik', 'tam', false, 'Geri yükleme öncesi otomatik güvenlik yedeği');

        // 2) Geri yüklenecek dosyayı düz .sql haline getir
        $duzYol = yedekDuzMetneCevir($yol);
        if (!$duzYol) {
            flash('hata', 'Yedek dosyası çözülemedi (şifreleme anahtarı eksik/uyuşmuyor ya da format tanınmadı). Geri yükleme iptal edildi.');
            header('Location: index.php'); exit;
        }

        // 3) Öncesi satır sayıları
        $oncesiSatirlar = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_KEY_PAIR);

        // 4) Bakım modunu geçici olarak aç (öncekine göre geri alınacak)
        $bakimOncekiDurum = ayar('bakim_modu', '0');
        if ($bakimOncekiDurum !== '1') ayarKaydet('bakim_modu', '1', 'Restore işlemi için otomatik açıldı');

        // 5) Geri yükle
        $restoreHata = [];
        $restoreBasarili = mysqlRestoreCalistir($duzYol, $restoreHata);
        @unlink($duzYol);

        // 6) Bakım modunu eski durumuna döndür
        if ($bakimOncekiDurum !== '1') ayarKaydet('bakim_modu', '0', 'Restore işlemi tamamlandı, otomatik kapatıldı');

        // 7) Sonrası satır sayıları + rapor
        $sonrasiSatirlar = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_KEY_PAIR);
        $karsilastirma = [];
        $tumTablolar = array_unique(array_merge(array_keys($oncesiSatirlar), array_keys($sonrasiSatirlar)));
        sort($tumTablolar);
        foreach ($tumTablolar as $t) {
            $onceki = (int)($oncesiSatirlar[$t] ?? 0);
            $sonraki = (int)($sonrasiSatirlar[$t] ?? 0);
            if ($onceki !== $sonraki) $karsilastirma[] = ['tablo' => $t, 'onceki' => $onceki, 'sonraki' => $sonraki];
        }

        logla('yedek_geri_yuklendi', 'yedekleme', 0, $dosya . ' — ' . ($restoreBasarili ? 'başarılı' : 'BAŞARISIZ: ' . implode(' ', $restoreHata)));
        $_SESSION['restore_rapor'] = [
            'basarili' => $restoreBasarili, 'dosya' => $dosya, 'hata' => implode(' ', $restoreHata),
            'karsilastirma' => $karsilastirma, 'guvenlikYedegi' => $guvenlikBasarili ? basename($guvenlikDosya) : null,
        ];
        unset($_SESSION['restore_onizleme']);
        flash($restoreBasarili ? 'basari' : 'hata', $restoreBasarili ? 'Geri yükleme tamamlandı.' : 'Geri yükleme BAŞARISIZ oldu — güvenlik yedeğinden devam edebilirsiniz.');
        header('Location: index.php#restore-rapor'); exit;
    }

    // ── İki yedeği karşılaştır ────────────────────────────────
    if ($aksiyon === 'karsilastir') {
        $d1 = basename($_POST['dosya1'] ?? ''); $d2 = basename($_POST['dosya2'] ?? '');
        $y1 = $yedekDir . $d1; $y2 = $yedekDir . $d2;
        if (!file_exists($y1) || !file_exists($y2) || $d1 === $d2) {
            flash('hata', 'Lütfen iki farklı geçerli yedek seçin.');
        } else {
            $duz1 = yedekDuzMetneCevir($y1); $duz2 = yedekDuzMetneCevir($y2);
            $t1 = $duz1 ? yedekTabloSatirTahmini(file_get_contents($duz1)) : [];
            $t2 = $duz2 ? yedekTabloSatirTahmini(file_get_contents($duz2)) : [];
            if ($duz1) @unlink($duz1); if ($duz2) @unlink($duz2);
            $tumTablolar = array_unique(array_merge(array_keys($t1), array_keys($t2)));
            sort($tumTablolar);
            $satirlar = [];
            foreach ($tumTablolar as $t) $satirlar[] = ['tablo' => $t, 'birinci' => $t1[$t] ?? 0, 'ikinci' => $t2[$t] ?? 0];
            $_SESSION['yedek_karsilastirma'] = ['dosya1' => $d1, 'dosya2' => $d2, 'satirlar' => $satirlar];
        }
        header('Location: index.php#karsilastir'); exit;
    }
}

// ── Yedekleri listele (geçmiş tablosuyla birleştir) ──────────
$yedekler = [];
foreach (glob($yedekDir . '*.{sql,sql.gz,sql.gz.enc,sql.enc,zip}', GLOB_BRACE) ?: [] as $dosya) {
    $yedekler[] = ['ad' => basename($dosya), 'boyut' => filesize($dosya), 'tarih' => filemtime($dosya)];
}
usort($yedekler, fn($a, $b) => $b['tarih'] - $a['tarih']);

$gecmisMap = [];
foreach ($pdo->query("SELECT g.*, k.ad_soyad FROM yedekleme_gecmisi g LEFT JOIN kullanicilar k ON g.kullanici_id=k.id WHERE g.basarili=1")->fetchAll() as $g) {
    $gecmisMap[$g['dosya_adi']] = $g;
}

// DB istatistikleri
$tablolar    = $pdo->query("SHOW TABLE STATUS")->fetchAll();
$toplamSatir = array_sum(array_column($tablolar, 'Rows'));
$dbBoyut     = array_sum(array_map(fn($t) => ($t['Data_length'] ?? 0) + ($t['Index_length'] ?? 0), $tablolar));

// Son 30 gün yedekleme sağlığı
$basarisizSonYedek = $pdo->query("SELECT COUNT(*) FROM yedekleme_gecmisi WHERE basarili=0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4><i class="bi bi-cloud-arrow-down text-primary"></i> Yedekleme</h4>
</div>

<?php if ($basarisizSonYedek > 0): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Son 7 günde <?= $basarisizSonYedek ?> başarısız yedekleme denemesi kaydedildi.</div>
<?php endif; ?>

<?php if ($restoreRapor): ?>
<div id="restore-rapor" class="card shadow-sm mb-3 <?= $restoreRapor['basarili'] ? 'border-success' : 'border-danger' ?>">
    <div class="card-header bg-white fw-semibold <?= $restoreRapor['basarili'] ? 'text-success' : 'text-danger' ?>">
        <i class="bi bi-<?= $restoreRapor['basarili'] ? 'check-circle' : 'x-circle' ?>"></i>
        Geri Yükleme Raporu — <?= escH($restoreRapor['dosya']) ?>
    </div>
    <div class="card-body">
        <?php if (!$restoreRapor['basarili']): ?>
        <div class="alert alert-danger small mb-2">Hata: <?= escH($restoreRapor['hata']) ?></div>
        <?php endif; ?>
        <?php if ($restoreRapor['guvenlikYedegi']): ?>
        <div class="small text-muted mb-2"><i class="bi bi-shield-check"></i> Restore öncesi otomatik güvenlik yedeği alındı: <code><?= escH($restoreRapor['guvenlikYedegi']) ?></code></div>
        <?php endif; ?>
        <?php if ($restoreRapor['karsilastirma']): ?>
        <div class="fw-semibold small mb-1">Değişen Tablolar (satır sayısı)</div>
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tablo</th><th>Öncesi</th><th>Sonrası</th></tr></thead>
            <tbody>
            <?php foreach ($restoreRapor['karsilastirma'] as $k): ?>
            <tr><td><code><?= escH($k['tablo']) ?></code></td><td><?= number_format($k['onceki']) ?></td><td class="fw-bold"><?= number_format($k['sonraki']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="text-muted small">Tablo satır sayılarında değişiklik tespit edilmedi (MySQL `information_schema` tahmini değerdir, küçük farklar normaldir).</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($karsilastirmaSonuc): ?>
<div id="karsilastir" class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right text-primary"></i> Karşılaştırma: <?= escH($karsilastirmaSonuc['dosya1']) ?> ↔ <?= escH($karsilastirmaSonuc['dosya2']) ?></div>
    <div class="card-body p-0" style="max-height:400px;overflow-y:auto">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tablo</th><th><?= escH($karsilastirmaSonuc['dosya1']) ?></th><th><?= escH($karsilastirmaSonuc['dosya2']) ?></th><th>Fark</th></tr></thead>
            <tbody>
            <?php foreach ($karsilastirmaSonuc['satirlar'] as $s): $fark = $s['ikinci'] - $s['birinci']; ?>
            <tr>
                <td><code><?= escH($s['tablo']) ?></code></td><td><?= number_format($s['birinci']) ?></td><td><?= number_format($s['ikinci']) ?></td>
                <td class="<?= $fark > 0 ? 'text-success' : ($fark < 0 ? 'text-danger' : 'text-muted') ?>"><?= $fark > 0 ? '+' : '' ?><?= number_format($fark) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">Satır sayıları mysqldump çıktısındaki INSERT ifadelerinden yaklaşık olarak hesaplanmıştır (kesin değer için gerçek veritabanı sorgulanmalıdır).</div>
</div>
<?php endif; ?>

<?php if ($restoreOnizleme): ?>
<div id="restore" class="card shadow-sm mb-3 border-danger">
    <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-exclamation-triangle"></i> Geri Yükleme Onayı — <?= escH($restoreOnizleme['dosya']) ?></div>
    <div class="card-body">
        <div class="alert alert-danger small">
            <strong>Bu işlem geri alınamaz bir şekilde mevcut veritabanının üzerine yazar.</strong>
            İşlemden hemen önce otomatik bir güvenlik yedeği alınacak ve geri yükleme süresince bakım modu otomatik açılacaktır.
        </div>
        <div class="row small mb-3">
            <div class="col-md-4"><strong>Dosya Tarihi:</strong> <?= date('d.m.Y H:i', $restoreOnizleme['tarih']) ?></div>
            <div class="col-md-4"><strong>Boyut:</strong> <?= round($restoreOnizleme['boyut']/1024,1) ?> KB</div>
            <div class="col-md-4"><strong>Kapsam:</strong> <?= escH($restoreOnizleme['kayit']['kapsam'] ?? 'bilinmiyor') ?></div>
        </div>
        <?php if ($restoreOnizleme['tablolar']): ?>
        <div class="fw-semibold small mb-1">Dosyadaki Tablolar (yaklaşık satır sayısı)</div>
        <div style="max-height:200px;overflow-y:auto" class="mb-3">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tablo</th><th>Yaklaşık Satır</th></tr></thead>
            <tbody>
            <?php foreach ($restoreOnizleme['tablolar'] as $t => $adet): ?>
            <tr><td><code><?= escH($t) ?></code></td><td><?= number_format($adet) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div class="text-muted small mb-3">Bu format için tablo içeriği önizlenemiyor (şifreli/zip dosyalarda içerik önizlemesi sınırlıdır).</div>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('SON UYARI: Veritabanı bu yedeğin içeriğiyle değiştirilecek. Emin misiniz?')">
            <?= csrfField() ?>
            <input type="hidden" name="aksiyon" value="restore_uygula">
            <input type="hidden" name="dosya" value="<?= escH($restoreOnizleme['dosya']) ?>">
            <label class="form-label small fw-semibold">Şifrenizi Girin (onay için)</label>
            <input type="password" name="onay_sifre_restore" class="form-control mb-2" style="max-width:280px" required autocomplete="current-password">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-counterclockwise"></i> Geri Yüklemeyi Onayla</button>
            </div>
        </form>
        <form method="post" class="d-inline">
            <?= csrfField() ?><input type="hidden" name="aksiyon" value="restore_iptal">
            <button type="submit" class="btn btn-outline-secondary mt-2">Vazgeç</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Yedek Alma -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-download text-success"></i> Yeni Yedek Al</div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="aksiyon" value="yedek_al">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Kapsam</label>
                    <select name="kapsam" class="form-select form-select-sm">
                        <option value="tam">Tam Yedek</option>
                        <option value="hizli">Hızlı Yedek (log tabloları hariç)</option>
                        <option value="sadece_sema">Yalnızca Şema (veri hariç)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="dosyalar_dahil" id="dosyalarDahil" <?= ayar('yedek_dosyalari_dahil','0')==='1'?'checked':'' ?>>
                        <label class="form-check-label small" for="dosyalarDahil">uploads/ dosyalarını dahil et (zip)</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="sikistir" id="sikistirCb" checked>
                        <label class="form-check-label small" for="sikistirCb">Sıkıştır (gzip)</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="sifrele" id="sifreleCb" <?= ayar('yedek_sifrele','0')==='1'?'checked':'' ?> <?= !BACKUP_ENCRYPTION_KEY ? 'disabled' : '' ?>>
                        <label class="form-check-label small" for="sifreleCb">Şifrele<?= !BACKUP_ENCRYPTION_KEY ? ' (anahtar tanımsız)' : '' ?></label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-download"></i> Yedek Al</button>
                </div>
                <div class="col-12">
                    <input type="text" name="not" class="form-control form-control-sm" placeholder="Not (opsiyonel — örn. 'Güncelleme öncesi')" maxlength="255">
                </div>
            </div>
        </form>
    </div>
</div>

<!-- DB Durum Kartları -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <div class="small opacity-75">Veritabanı</div>
                <div class="fw-bold fs-5"><?= DB_NAME ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body">
                <div class="small opacity-75">Toplam Kayıt</div>
                <div class="fw-bold fs-5"><?= number_format($toplamSatir) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 bg-info text-white">
            <div class="card-body">
                <div class="small opacity-75">Veritabanı Boyutu</div>
                <div class="fw-bold fs-5"><?= round($dbBoyut / 1024, 1) ?> KB</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-table text-primary"></i> Tablo Durumu</div>
            <div class="card-body p-0" style="max-height:350px;overflow-y:auto">
            <table class="table table-sm mb-0">
                <thead><tr><th>Tablo</th><th class="text-end">Kayıt</th><th class="text-end">Boyut</th></tr></thead>
                <tbody>
                <?php foreach ($tablolar as $t): ?>
                <tr>
                    <td><?= escH($t['Name']) ?></td>
                    <td class="text-end"><?= number_format($t['Rows']) ?></td>
                    <td class="text-end"><?= round(($t['Data_length'] + $t['Index_length']) / 1024, 1) ?> KB</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-archive text-primary"></i> Alınan Yedekler (<?= count($yedekler) ?>)</span>
                <form method="post" onsubmit="return confirm('Saklama politikasına göre eski yedekler silinecek. Emin misiniz?')">
                    <?= csrfField() ?><input type="hidden" name="aksiyon" value="temizle_simdi">
                    <button class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-trash3"></i> Politikaya Göre Temizle</button>
                </form>
            </div>
            <div class="card-body p-0" style="max-height:500px;overflow-y:auto">
            <?php if (empty($yedekler)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                    Henüz yedek alınmamış
                </div>
            <?php else: ?>
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Dosya</th><th>Tarih</th><th>Boyut</th><th>Bilgi</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($yedekler as $y): $g = $gecmisMap[$y['ad']] ?? null; ?>
                <tr>
                    <td><code class="small"><?= escH($y['ad']) ?></code>
                        <?php if ($g && $g['not_metni']): ?><div class="small text-muted"><?= escH($g['not_metni']) ?></div><?php endif; ?>
                    </td>
                    <td class="small"><?= date('d.m.Y H:i', $y['tarih']) ?></td>
                    <td class="small"><?= round($y['boyut'] / 1024, 1) ?> KB</td>
                    <td class="small">
                        <?php if ($g): ?>
                        <span class="badge bg-<?= $g['tip']==='otomatik'?'secondary':'primary' ?>"><?= $g['tip'] ?></span>
                        <?php if ($g['sifreli']): ?><i class="bi bi-lock-fill text-warning" title="Şifreli"></i><?php endif; ?>
                        <?php if ($g['dosyalar_dahil']): ?><i class="bi bi-file-earmark-zip text-info" title="Dosyalar dahil"></i><?php endif; ?>
                        <?php if ($g['ikinci_konum_kopyalandi']): ?><i class="bi bi-hdd-network text-success" title="İkinci konuma kopyalandı"></i><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                        <a href="indir.php?dosya=<?= urlencode($y['ad']) ?>" class="btn btn-sm btn-outline-success py-0 px-2" title="İndir"><i class="bi bi-download"></i></a>
                        <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="aksiyon" value="restore_onizle"><input type="hidden" name="dosya" value="<?= escH($y['ad']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Geri Yükle"><i class="bi bi-arrow-counterclockwise"></i></button>
                        </form>
                        <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="aksiyon" value="test_geri_yukle"><input type="hidden" name="dosya" value="<?= escH($y['ad']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-info py-0 px-2" title="Test Geri Yükle (gerçek veriye dokunmaz)"><i class="bi bi-clipboard-check"></i></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Bu yedeği silmek istediğinize emin misiniz?')">
                            <?= csrfField() ?><input type="hidden" name="aksiyon" value="sil"><input type="hidden" name="dosya" value="<?= escH($y['ad']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Sil"><i class="bi bi-trash"></i></button>
                        </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Karşılaştırma -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right text-primary"></i> İki Yedeği Karşılaştır</div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?= csrfField() ?><input type="hidden" name="aksiyon" value="karsilastir">
            <div class="col-md-5">
                <select name="dosya1" class="form-select form-select-sm" required>
                    <option value="">Birinci yedek...</option>
                    <?php foreach ($yedekler as $y): ?><option value="<?= escH($y['ad']) ?>"><?= escH($y['ad']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <select name="dosya2" class="form-select form-select-sm" required>
                    <option value="">İkinci yedek...</option>
                    <?php foreach ($yedekler as $y): ?><option value="<?= escH($y['ad']) ?>"><?= escH($y['ad']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Karşılaştır</button></div>
        </form>
    </div>
</div>

<!-- Yedekleme Ayarları -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-gear text-primary"></i> Yedekleme Ayarları</div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="aksiyon" value="ayarlar_kaydet">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Otomatik Yedekleme Sıklığı</label>
                    <select name="yedek_sikligi" class="form-select form-select-sm">
                        <?php foreach (['gunluk'=>'Günlük','haftalik'=>'Haftalık','aylik'=>'Aylık'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ayar('yedek_sikligi','haftalik')===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Yöneticinin ilk günlük girişinde tetiklenir.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Saklama Süresi (gün, 0=sınırsız)</label>
                    <input type="number" name="yedek_saklama_gun" class="form-control form-control-sm" value="<?= (int)ayar('yedek_saklama_gun','90') ?>" min="0" max="3650">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Saklama Adedi (0=sınırsız)</label>
                    <input type="number" name="yedek_saklama_adet" class="form-control form-control-sm" value="<?= (int)ayar('yedek_saklama_adet','0') ?>" min="0" max="1000">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">İkinci Konum (yerel/ağ yolu)</label>
                    <input type="text" name="yedek_ikinci_konum" class="form-control form-control-sm" value="<?= escH(ayar('yedek_ikinci_konum','')) ?>" placeholder="/mnt/yedek2 (boş=kapalı)">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold mb-1">Hızlı Yedekte Hariç Tutulacak Tablolar</label>
                    <input type="text" name="yedek_haric_tablolar" class="form-control form-control-sm" value="<?= escH(ayar('yedek_haric_tablolar','')) ?>" placeholder="aktivite_loglari,ayar_gecmisi">
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="yedek_dosyalari_dahil" id="ydd" <?= ayar('yedek_dosyalari_dahil','0')==='1'?'checked':'' ?>>
                        <label class="form-check-label small" for="ydd">Otomatik yedeklere dosyaları dahil et</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="yedek_sifrele" id="ysf" <?= ayar('yedek_sifrele','0')==='1'?'checked':'' ?> <?= !BACKUP_ENCRYPTION_KEY ? 'disabled' : '' ?>>
                        <label class="form-check-label small" for="ysf">Otomatik yedekleri şifrele</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3"><i class="bi bi-save"></i> Ayarları Kaydet</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
