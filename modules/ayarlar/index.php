<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Sistem Ayarları';
$pdo = db();

// Varsayılan değerler (Varsayılana Sıfırla + şablonlar için)
$varsayilanlar = [
    'firma_adi'=>'Regal Bayi','site_basligi'=>'Regal Bayi Yönetim','para_birimi'=>'TRY','para_sembol'=>'₺',
    'kdv_orani'=>'20','fatura_prefix'=>'F','min_stok_uyari'=>'1','tema_renk'=>'primary','tarih_formati'=>'d.m.Y',
    'kayit_basi'=>'25','tesir_indirim'=>'10','tesir_uyari_gun'=>'90','sayim_periyot_gun'=>'30',
    'kasiyer_max_indirim'=>'0','bekleyen_satis_uyari_gun'=>'3','fatura_alt_not'=>'Bizi tercih ettiğiniz için teşekkür ederiz.',
    'kasa_min_bakiye_uyari'=>'0','gider_onay_limiti'=>'0','taksit_gecikme_cezasi_oran'=>'0',
    'taksit_erken_odeme_indirim'=>'0','taksit_takip_esik_gun'=>'30','tema_ikincil_renk'=>'#6c757d',
    'yazi_tipi'=>'sistem','fatura_kagit_boyutu'=>'A4','sidebar_duzen'=>'sabit','zaman_dilimi'=>'Europe/Istanbul',
    'etiket_genislik_mm'=>'60','etiket_yukseklik_mm'=>'35','bakim_modu'=>'0',
    'bakim_mesaji'=>'Sistem bakımdadır, kısa süre sonra tekrar deneyin.','calisma_gunleri'=>'1,2,3,4,5,6',
    'mesai_baslangic'=>'09:00','mesai_bitis'=>'19:00','resmi_tatiller'=>'',
    'dashboard_kasiyer_finans'=>'1','dashboard_depo_ciro'=>'0',
];

// Hazır profil şablonları — yalnızca belirtilen anahtarları değiştirir
$sablonlar = [
    'kucuk' => ['ad' => 'Küçük Bayi', 'ayarlar' => [
        'kayit_basi'=>'10','gider_onay_limiti'=>'0','kasa_min_bakiye_uyari'=>'0','sayim_periyot_gun'=>'60','taksit_takip_esik_gun'=>'45',
    ]],
    'buyuk' => ['ad' => 'Büyük Bayi', 'ayarlar' => [
        'kayit_basi'=>'50','gider_onay_limiti'=>'1000','kasa_min_bakiye_uyari'=>'5000','sayim_periyot_gun'=>'15','taksit_takip_esik_gun'=>'15',
    ]],
];

$izinli = [
    'firma_adi','firma_slogan','firma_telefon','firma_email',
    'firma_adres','firma_sehir','firma_vergi_no','firma_vergi_daire','firma_iban',
    'sosyal_instagram','sosyal_facebook',
    'site_basligi','para_birimi','para_sembol','kdv_orani',
    'fatura_prefix','min_stok_uyari','tema_renk','tema_ikincil_renk','yazi_tipi',
    'fatura_kagit_boyutu','sidebar_duzen','tarih_formati','kayit_basi',
    'tesir_indirim','tesir_uyari_gun','sayim_periyot_gun',
    'kasiyer_max_indirim','bekleyen_satis_uyari_gun','fatura_alt_not',
    'kasa_min_bakiye_uyari','gider_onay_limiti',
    'taksit_gecikme_cezasi_oran','taksit_erken_odeme_indirim','taksit_takip_esik_gun',
    'zaman_dilimi','etiket_genislik_mm','etiket_yukseklik_mm','bakim_mesaji',
    'mesai_baslangic','mesai_bitis','resmi_tatiller',
];
$checkboxlar = ['bakim_modu','dashboard_kasiyer_finans','dashboard_depo_ciro'];
$gorselAlanlari = ['firma_logo' => 'logo_dosya', 'favicon' => 'favicon_dosya', 'login_arkaplan' => 'arkaplan_dosya', 'kase_imza' => 'kase_dosya'];

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? 'kaydet';

    if ($aksiyon === 'kaydet') {
        // Bakım modu KAPALIYKEN açılıyorsa (kasıtlı, riskli işlem) şifre teyidi iste
        $yeniBakim = isset($_POST['bakim_modu']) ? '1' : '0';
        if ($yeniBakim === '1' && ayar('bakim_modu','0') !== '1') {
            $sifreOK = false;
            if (!empty($_POST['onay_sifre'])) {
                $kul = $pdo->prepare("SELECT sifre FROM kullanicilar WHERE id=?");
                $kul->execute([$_SESSION['kullanici_id']]);
                $sifreOK = password_verify($_POST['onay_sifre'], (string)$kul->fetchColumn());
            }
            if (!$sifreOK) {
                flash('hata', 'Bakım modunu açmak için şifrenizi doğru girmelisiniz.');
                header('Location: index.php'); exit;
            }
        }

        foreach ($izinli as $key) {
            if (isset($_POST[$key])) ayarKaydet($key, trim($_POST[$key]));
        }
        foreach ($checkboxlar as $cb) {
            ayarKaydet($cb, isset($_POST[$cb]) ? '1' : '0');
        }
        if (isset($_POST['calisma_gunleri_secim']) && is_array($_POST['calisma_gunleri_secim'])) {
            ayarKaydet('calisma_gunleri', implode(',', array_map('intval', $_POST['calisma_gunleri_secim'])));
        } else {
            ayarKaydet('calisma_gunleri', '');
        }
        foreach ($gorselAlanlari as $ayarKey => $dosyaAlan) {
            try {
                $yeni = markaGorseliYukle($_FILES[$dosyaAlan] ?? ['error' => UPLOAD_ERR_NO_FILE], ayar($ayarKey));
                if ($yeni) ayarKaydet($ayarKey, $yeni);
            } catch (Exception $e) {
                flash('hata', $e->getMessage());
            }
        }
        flash('basari', 'Ayarlar kaydedildi.');
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'gorsel_kaldir') {
        $key = $_POST['anahtar'] ?? '';
        if (isset($gorselAlanlari[$key])) {
            $eski = ayar($key);
            if ($eski && is_file(__DIR__ . '/../../uploads/marka/' . basename($eski))) @unlink(__DIR__ . '/../../uploads/marka/' . basename($eski));
            ayarKaydet($key, '', 'Görsel kaldırıldı');
            flash('basari', 'Görsel kaldırıldı.');
        }
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'sifirla') {
        $key = $_POST['anahtar'] ?? '';
        if (isset($varsayilanlar[$key])) {
            ayarKaydet($key, $varsayilanlar[$key], 'Varsayılana sıfırlandı');
            flash('basari', 'Ayar varsayılana sıfırlandı.');
        }
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'sifirla_tumu') {
        foreach ($varsayilanlar as $k => $v) ayarKaydet($k, $v, 'Tümü varsayılana sıfırlandı');
        flash('basari', 'Tüm ayarlar varsayılana sıfırlandı.');
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'sablon_uygula') {
        $sablon = $_POST['sablon'] ?? '';
        if (isset($sablonlar[$sablon])) {
            foreach ($sablonlar[$sablon]['ayarlar'] as $k => $v) ayarKaydet($k, $v, 'Şablon: ' . $sablonlar[$sablon]['ad']);
            flash('basari', '"' . $sablonlar[$sablon]['ad'] . '" şablonu uygulandı.');
        }
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'gecmis_geri_yukle') {
        $id = (int)($_POST['gecmis_id'] ?? 0);
        $g = $pdo->prepare("SELECT * FROM ayar_gecmisi WHERE id=?");
        $g->execute([$id]); $g = $g->fetch();
        if ($g) {
            ayarKaydet($g['anahtar'], (string)$g['eski_deger'], 'Geçmişten geri yüklendi (kayıt #' . $id . ')');
            flash('basari', '"' . $g['anahtar'] . '" ayarı önceki değerine geri yüklendi.');
        }
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'import_onizle') {
        unset($_SESSION['ayar_import_onizleme']);
        if (empty($_FILES['import_dosya']['tmp_name']) || $_FILES['import_dosya']['error'] !== UPLOAD_ERR_OK) {
            $hata = 'Lütfen bir JSON dosyası seçin.';
        } else {
            $icerik = file_get_contents($_FILES['import_dosya']['tmp_name']);
            $veri = json_decode($icerik, true);
            if (!is_array($veri)) {
                $hata = 'Geçersiz JSON dosyası.';
            } else {
                $onizleme = [];
                foreach ($veri as $k => $v) {
                    if (!is_string($k) || !is_scalar($v)) continue;
                    $mevcut = ayar($k, '');
                    if ((string)$v !== (string)$mevcut) {
                        $onizleme[] = ['anahtar' => $k, 'eski' => $mevcut, 'yeni' => (string)$v];
                    }
                }
                $_SESSION['ayar_import_onizleme'] = $onizleme;
            }
        }
    }

    if ($aksiyon === 'import_uygula') {
        $onizleme = $_SESSION['ayar_import_onizleme'] ?? [];
        foreach ($onizleme as $satir) {
            ayarKaydet($satir['anahtar'], $satir['yeni'], 'JSON içe aktarma');
        }
        unset($_SESSION['ayar_import_onizleme']);
        flash('basari', count($onizleme) . ' ayar içe aktarıldı.');
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'import_iptal') {
        unset($_SESSION['ayar_import_onizleme']);
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'log_temizle') {
        $gun = max(30, (int)($_POST['gun'] ?? 90));
        $sil = $pdo->prepare("DELETE FROM aktivite_loglari WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $sil->execute([$gun]);
        $adet = $sil->rowCount();
        flash('basari', $adet . ' eski log kaydı silindi (' . $gun . ' günden eski).');
        header('Location: index.php'); exit;
    }
}

// ── Dışa aktar (JSON) ──────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'json') {
    $tum = $pdo->query("SELECT anahtar, deger FROM ayarlar")->fetchAll(PDO::FETCH_KEY_PAIR);
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="regal_ayarlar_' . date('Y-m-d') . '.json"');
    echo json_encode($tum, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Doğrulama sonucu (istenirse) ────────────────────────────────
$dogrulamaSonuclari = isset($_GET['dogrula']) ? ayarlariDogrula() : null;

// Tüm ayarları çek
$ayarlar = $pdo->query("SELECT * FROM ayarlar ORDER BY grup, anahtar")->fetchAll();
$gruplar = [];
foreach ($ayarlar as $a) { $gruplar[$a['grup']][] = $a; }

$importOnizleme = $_SESSION['ayar_import_onizleme'] ?? [];
$gecmis = $pdo->query("SELECT g.*, k.ad_soyad FROM ayar_gecmisi g LEFT JOIN kullanicilar k ON g.kullanici_id=k.id ORDER BY g.id DESC LIMIT 40")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4><i class="bi bi-gear text-primary"></i> Sistem Ayarları</h4>
    <div class="position-relative" style="max-width:280px;width:100%">
        <input type="text" id="ayarAra" class="form-control form-control-sm" placeholder="Ayar ara...">
        <div id="ayarAraSonuc" class="small text-muted mt-1"></div>
    </div>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<?php if ($dogrulamaSonuclari !== null): ?>
<div class="alert <?= empty($dogrulamaSonuclari) ? 'alert-success' : 'alert-warning' ?>">
    <strong><i class="bi bi-shield-check"></i> Ayar Doğrulama Sonucu:</strong>
    <?php if (empty($dogrulamaSonuclari)): ?>
    <div>Bilinen bir çelişki/bağımlılık sorunu bulunamadı.</div>
    <?php else: ?>
    <ul class="mb-0 mt-1"><?php foreach ($dogrulamaSonuclari as $s): ?><li><?= escH($s) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($importOnizleme)): ?>
<div class="card shadow-sm mb-3 border-success">
    <div class="card-header bg-white fw-semibold text-success"><i class="bi bi-file-earmark-diff"></i> İçe Aktarma Önizlemesi — <?= count($importOnizleme) ?> değişiklik</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Ayar</th><th>Mevcut</th><th>Yeni</th></tr></thead>
            <tbody>
            <?php foreach ($importOnizleme as $s): ?>
            <tr><td><code><?= escH($s['anahtar']) ?></code></td><td class="text-muted small"><?= escH(mb_substr($s['eski'],0,60)) ?></td><td class="fw-semibold small"><?= escH(mb_substr($s['yeni'],0,60)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <form method="post"><?= csrfField() ?><input type="hidden" name="aksiyon" value="import_uygula">
            <button class="btn btn-success btn-sm" onclick="return confirm('<?= count($importOnizleme) ?> ayar güncellenecek. Onaylıyor musunuz?')"><i class="bi bi-check-circle"></i> Onayla ve Uygula</button>
        </form>
        <form method="post"><?= csrfField() ?><input type="hidden" name="aksiyon" value="import_iptal">
            <button class="btn btn-outline-secondary btn-sm">Vazgeç</button>
        </form>
    </div>
</div>
<?php endif; ?>

<form method="post" id="ayarlarForm" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="aksiyon" value="kaydet">

    <!-- Tab menü -->
    <ul class="nav nav-tabs mb-4 flex-wrap" id="ayarTabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-firma" type="button"><i class="bi bi-building"></i> Firma & Marka</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sistem" type="button"><i class="bi bi-sliders"></i> Sistem</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-gorunum" type="button"><i class="bi bi-palette"></i> Görünüm</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-satis" type="button"><i class="bi bi-receipt"></i> Satış</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-finans" type="button"><i class="bi bi-cash-stack"></i> Finans</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bildirim" type="button"><i class="bi bi-bell"></i> Bildirim Eşikleri</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-zaman" type="button"><i class="bi bi-clock-history"></i> Çalışma Zamanı</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-altyapi" type="button"><i class="bi bi-hdd-stack"></i> Sistem & Altyapı</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-araclar" type="button"><i class="bi bi-tools"></i> Araçlar & Geçmiş</button></li>
    </ul>

    <div class="tab-content">

        <!-- ── Firma & Marka ── -->
        <div class="tab-pane fade show active" id="tab-firma">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-building text-primary"></i> Firma Bilgileri</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6 ayar-satir" data-label="firma adı">
                                    <label class="form-label fw-semibold">Firma Adı <span class="text-danger">*</span></label>
                                    <input type="text" name="firma_adi" class="form-control ayar-input" value="<?= escH(ayar('firma_adi', 'Regal Bayi')) ?>" required maxlength="100">
                                    <div class="form-text">Faturalarda ve sistem başlığında görünür.</div>
                                </div>
                                <div class="col-md-6 ayar-satir" data-label="slogan açıklama">
                                    <label class="form-label fw-semibold">Slogan / Açıklama</label>
                                    <input type="text" name="firma_slogan" class="form-control ayar-input" value="<?= escH(ayar('firma_slogan')) ?>" maxlength="150">
                                </div>
                                <div class="col-md-4 ayar-satir" data-label="telefon">
                                    <label class="form-label fw-semibold">Telefon</label>
                                    <div class="input-group"><span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                        <input type="text" name="firma_telefon" class="form-control ayar-input" value="<?= escH(ayar('firma_telefon')) ?>" maxlength="20"></div>
                                </div>
                                <div class="col-md-4 ayar-satir" data-label="e-posta email">
                                    <label class="form-label fw-semibold">E-posta</label>
                                    <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" name="firma_email" class="form-control ayar-input" value="<?= escH(ayar('firma_email')) ?>" maxlength="100"></div>
                                </div>
                                <div class="col-md-4 ayar-satir" data-label="şehir">
                                    <label class="form-label fw-semibold">Şehir</label>
                                    <input type="text" name="firma_sehir" class="form-control ayar-input" value="<?= escH(ayar('firma_sehir')) ?>" maxlength="50">
                                </div>
                                <div class="col-md-12 ayar-satir" data-label="adres">
                                    <label class="form-label fw-semibold">Adres</label>
                                    <textarea name="firma_adres" class="form-control ayar-input" rows="2" maxlength="300"><?= escH(ayar('firma_adres')) ?></textarea>
                                </div>
                                <div class="col-md-4 ayar-satir" data-label="vergi no">
                                    <label class="form-label fw-semibold">Vergi No</label>
                                    <input type="text" name="firma_vergi_no" class="form-control ayar-input" value="<?= escH(ayar('firma_vergi_no')) ?>" maxlength="11">
                                </div>
                                <div class="col-md-4 ayar-satir" data-label="vergi dairesi">
                                    <label class="form-label fw-semibold">Vergi Dairesi</label>
                                    <input type="text" name="firma_vergi_daire" class="form-control ayar-input" value="<?= escH(ayar('firma_vergi_daire')) ?>" maxlength="100">
                                </div>
                                <div class="col-md-4 ayar-satir" data-label="iban">
                                    <label class="form-label fw-semibold">IBAN</label>
                                    <input type="text" name="firma_iban" class="form-control ayar-input" value="<?= escH(ayar('firma_iban')) ?>" maxlength="32" placeholder="TR00 0000 0000 0000 0000 0000 00">
                                </div>
                                <div class="col-md-6 ayar-satir" data-label="instagram sosyal medya">
                                    <label class="form-label fw-semibold"><i class="bi bi-instagram"></i> Instagram</label>
                                    <input type="text" name="sosyal_instagram" class="form-control ayar-input" value="<?= escH(ayar('sosyal_instagram')) ?>" maxlength="150" placeholder="@kullaniciadi veya link">
                                </div>
                                <div class="col-md-6 ayar-satir" data-label="facebook sosyal medya">
                                    <label class="form-label fw-semibold"><i class="bi bi-facebook"></i> Facebook</label>
                                    <input type="text" name="sosyal_facebook" class="form-control ayar-input" value="<?= escH(ayar('sosyal_facebook')) ?>" maxlength="150" placeholder="Sayfa linki">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Marka Görselleri -->
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-images text-primary"></i> Marka Görselleri</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php
                                $gorselTanim = [
                                    'firma_logo'      => ['logo_dosya', 'Firma Logosu', 'Fatura, fiş, sözleşme ve giriş sayfasında gösterilir.'],
                                    'favicon'         => ['favicon_dosya', 'Favicon', 'Tarayıcı sekmesinde görünen küçük simge.'],
                                    'login_arkaplan'  => ['arkaplan_dosya', 'Giriş Sayfası Arkaplanı', 'Login ekranının arkaplan görseli.'],
                                    'kase_imza'       => ['kase_dosya', 'Kaşe / İmza', 'Fatura ve sözleşmede onay bölümünde gösterilir.'],
                                ];
                                foreach ($gorselTanim as $ayarKey => [$dosyaAlan, $baslik, $aciklama]):
                                    $mevcut = ayar($ayarKey);
                                ?>
                                <div class="col-md-3 ayar-satir" data-label="<?= strtolower($baslik) ?> logo görsel">
                                    <label class="form-label fw-semibold"><?= escH($baslik) ?></label>
                                    <?php if ($mevcut): ?>
                                    <div class="mb-2">
                                        <img src="<?= BASE_URL ?>/uploads/marka/<?= escH($mevcut) ?>" class="border rounded" style="max-width:100%;max-height:80px;object-fit:contain">
                                        <button type="submit" form="gorselKaldirForm<?= $ayarKey ?>" class="btn btn-sm btn-outline-danger py-0 px-1 ms-1" title="Kaldır"><i class="bi bi-trash"></i></button>
                                    </div>
                                    <form id="gorselKaldirForm<?= $ayarKey ?>" method="post" class="d-none">
                                        <?= csrfField() ?><input type="hidden" name="aksiyon" value="gorsel_kaldir"><input type="hidden" name="anahtar" value="<?= $ayarKey ?>">
                                    </form>
                                    <?php endif; ?>
                                    <input type="file" name="<?= $dosyaAlan ?>" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.gif">
                                    <div class="form-text"><?= escH($aciklama) ?> (≤2MB)</div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Sistem Ayarları ── -->
        <div class="tab-pane fade" id="tab-sistem">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-display text-primary"></i> Site Başlığı</div>
                        <div class="card-body">
                            <div class="mb-3 ayar-satir" data-label="tarayıcı sekme başlığı">
                                <label class="form-label fw-semibold">Tarayıcı Sekme Başlığı</label>
                                <input type="text" name="site_basligi" class="form-control ayar-input" value="<?= escH(ayar('site_basligi', 'Regal Bayi Yönetim')) ?>" maxlength="80">
                                <div class="form-text">Her sayfanın başlık çubuğunda görünür.</div>
                            </div>
                            <div class="mb-3 ayar-satir" data-label="fatura numarası öneki">
                                <label class="form-label fw-semibold">Fatura Numarası Öneki</label>
                                <div class="input-group">
                                    <input type="text" name="fatura_prefix" class="form-control ayar-input" value="<?= escH(ayar('fatura_prefix', 'F')) ?>" maxlength="5" style="max-width:120px">
                                    <span class="input-group-text text-muted">örn: <?= escH(ayar('fatura_prefix','F')) ?>202506001</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-currency-exchange text-primary"></i> Para Birimi & Vergi</div>
                        <div class="card-body">
                            <div class="mb-3 ayar-satir" data-label="para birimi">
                                <label class="form-label fw-semibold">Para Birimi</label>
                                <select name="para_birimi" class="form-select ayar-input">
                                    <?php foreach(['TRY'=>'Türk Lirası (₺)','USD'=>'Dolar ($)','EUR'=>'Euro (€)','GBP'=>'Sterlin (£)'] as $k=>$v): ?>
                                    <option value="<?= $k ?>" <?= ayar('para_birimi','TRY')===$k?'selected':'' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3 ayar-satir" data-label="para sembolü">
                                <label class="form-label fw-semibold">Para Sembolü</label>
                                <input type="text" name="para_sembol" class="form-control ayar-input" value="<?= escH(ayar('para_sembol','₺')) ?>" maxlength="5" style="max-width:100px">
                            </div>
                            <div class="mb-3 ayar-satir" data-label="kdv oranı">
                                <label class="form-label fw-semibold">Varsayılan KDV Oranı (%)</label>
                                <select name="kdv_orani" class="form-select ayar-input" style="max-width:120px">
                                    <?php foreach(['0','1','10','20'] as $k): ?>
                                    <option value="<?= $k ?>" <?= ayar('kdv_orani','20')===$k?'selected':'' ?>>%<?= $k ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Toplu güncelleme için: <a href="kdv_toplu_guncelle.php">KDV Oranı Toplu Güncelleme Sihirbazı</a></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-archive text-primary"></i> Stok</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="minimum stok uyarı">Varsayılan Minimum Stok Uyarı Seviyesi</label>
                            <input type="number" name="min_stok_uyari" class="form-control ayar-input" value="<?= (int)ayar('min_stok_uyari','1') ?>" min="0" max="999" style="max-width:120px">
                            <div class="form-text">Yeni ürün eklenirken otomatik atanır.</div>
                            <hr>
                            <label class="form-label fw-semibold ayar-satir" data-label="teşhir indirimi">Teşhir İndirimi Önerisi (%)</label>
                            <input type="number" name="tesir_indirim" class="form-control ayar-input" value="<?= (float)ayar('tesir_indirim','10') ?>" min="0" max="90" step="0.5" style="max-width:120px">
                            <div class="form-text">Uzun süre teşhirde kalan ürünler için önerilen indirim oranı.</div>
                            <label class="form-label fw-semibold mt-2 ayar-satir" data-label="teşhir süre uyarısı">Teşhir Süre Uyarısı (gün)</label>
                            <input type="number" name="tesir_uyari_gun" class="form-control ayar-input" value="<?= (int)ayar('tesir_uyari_gun','90') ?>" min="7" max="365" style="max-width:120px">
                            <label class="form-label fw-semibold mt-2 ayar-satir" data-label="sayım periyodu">Sayım Periyodu (gün)</label>
                            <input type="number" name="sayim_periyot_gun" class="form-control ayar-input" value="<?= (int)ayar('sayim_periyot_gun','30') ?>" min="7" max="365" style="max-width:120px">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-upc text-primary"></i> Barkod Etiketi</div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-6 ayar-satir" data-label="etiket genişlik">
                                    <label class="form-label fw-semibold">Genişlik (mm)</label>
                                    <input type="number" name="etiket_genislik_mm" class="form-control ayar-input" value="<?= (int)ayar('etiket_genislik_mm','60') ?>" min="20" max="150">
                                </div>
                                <div class="col-6 ayar-satir" data-label="etiket yükseklik">
                                    <label class="form-label fw-semibold">Yükseklik (mm)</label>
                                    <input type="number" name="etiket_yukseklik_mm" class="form-control ayar-input" value="<?= (int)ayar('etiket_yukseklik_mm','35') ?>" min="15" max="100">
                                </div>
                            </div>
                            <div class="form-text">Ürünler → Etiket Yazdır ekranındaki varsayılan etiket boyutu.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Görünüm ── -->
        <div class="tab-pane fade" id="tab-gorunum">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-palette text-primary"></i> Tema Rengi</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="tema rengi navbar">Navbar ve Vurgu Rengi</label>
                            <div class="d-flex gap-3 flex-wrap mt-2">
                                <?php
                                $temalar = ['primary'=>['#0d6efd','Mavi'],'success'=>['#198754','Yeşil'],'danger'=>['#dc3545','Kırmızı'],'warning'=>['#ffc107','Sarı'],'dark'=>['#212529','Koyu'],'purple'=>['#6f42c1','Mor']];
                                $aktifTema = ayar('tema_renk','primary');
                                foreach ($temalar as $k => [$renk, $ad]):
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tema_renk" id="tema_<?= $k ?>" value="<?= $k ?>" <?= $aktifTema===$k?'checked':'' ?>>
                                    <label class="form-check-label d-flex align-items-center gap-2" for="tema_<?= $k ?>">
                                        <span style="width:24px;height:24px;border-radius:6px;background:<?= $renk ?>;display:inline-block;border:2px solid rgba(0,0,0,.1)"></span><?= $ad ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3 ayar-satir" data-label="ikincil renk">
                                <label class="form-label fw-semibold">İkincil / Vurgu Rengi</label>
                                <input type="color" name="tema_ikincil_renk" class="form-control form-control-color ayar-input" value="<?= escH(ayar('tema_ikincil_renk','#6c757d')) ?>">
                            </div>
                            <div class="mt-3">
                                <div class="small text-muted mb-1">Önizleme:</div>
                                <nav id="temaOnizleme" class="navbar navbar-dark rounded px-3 py-2" style="background-color:<?= $_tema_renkler[ayar('tema_renk','primary')]['hex'] ?>">
                                    <span class="navbar-brand mb-0 fw-bold"><i class="bi bi-shop"></i> <?= escH(ayar('firma_adi','Regal Bayi')) ?></span>
                                    <span class="badge bg-warning text-dark">Önizleme</span>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-layout-text-sidebar text-primary"></i> Görüntüleme</div>
                        <div class="card-body">
                            <div class="mb-3 ayar-satir" data-label="tarih formatı">
                                <label class="form-label fw-semibold">Tarih Formatı</label>
                                <select name="tarih_formati" class="form-select ayar-input">
                                    <?php
                                    $formatlar = ['d.m.Y'=>date('d.m.Y').' (gün.ay.yıl)','Y-m-d'=>date('Y-m-d').' (yıl-ay-gün)','d/m/Y'=>date('d/m/Y').' (gün/ay/yıl)','m/d/Y'=>date('m/d/Y').' (ay/gün/yıl)'];
                                    $aktifFormat = ayar('tarih_formati','d.m.Y');
                                    foreach ($formatlar as $k=>$v): ?>
                                    <option value="<?= $k ?>" <?= $aktifFormat===$k?'selected':'' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3 ayar-satir" data-label="sayfa başına kayıt">
                                <label class="form-label fw-semibold">Sayfa Başına Kayıt</label>
                                <select name="kayit_basi" class="form-select ayar-input" style="max-width:120px">
                                    <?php foreach(['10','15','25','50','100'] as $n): ?>
                                    <option value="<?= $n ?>" <?= ayar('kayit_basi','25')===$n?'selected':'' ?>><?= $n ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3 ayar-satir" data-label="yazı tipi font">
                                <label class="form-label fw-semibold">Yazı Tipi</label>
                                <select name="yazi_tipi" class="form-select ayar-input">
                                    <?php foreach(['sistem'=>'Sistem Varsayılanı','arial'=>'Arial','georgia'=>'Georgia (Serif)','mono'=>'Monospace'] as $k=>$v): ?>
                                    <option value="<?= $k ?>" <?= ayar('yazi_tipi','sistem')===$k?'selected':'' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3 ayar-satir" data-label="sidebar menü düzeni">
                                <label class="form-label fw-semibold">Sol Menü Düzeni</label>
                                <select name="sidebar_duzen" class="form-select ayar-input">
                                    <option value="sabit" <?= ayar('sidebar_duzen','sabit')==='sabit'?'selected':'' ?>>Sabit (geniş)</option>
                                    <option value="daraltilabilir" <?= ayar('sidebar_duzen')==='daraltilabilir'?'selected':'' ?>>Daraltılabilir (ikon)</option>
                                </select>
                            </div>
                            <div class="mb-3 ayar-satir" data-label="fatura kağıt boyutu">
                                <label class="form-label fw-semibold">Varsayılan Fatura Kağıt Boyutu</label>
                                <select name="fatura_kagit_boyutu" class="form-select ayar-input" style="max-width:150px">
                                    <?php foreach(['A4'=>'A4','A5'=>'A5','80mm'=>'80mm Termal'] as $k=>$v): ?>
                                    <option value="<?= $k ?>" <?= ayar('fatura_kagit_boyutu','A4')===$k?'selected':'' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Satış Ayarları ── -->
        <div class="tab-pane fade" id="tab-satis">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-percent text-primary"></i> Kasiyer Yetkileri</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="kasiyer indirim yetkisi">Kasiyer Maksimum İndirim Yetkisi (%)</label>
                            <input type="number" name="kasiyer_max_indirim" class="form-control ayar-input" value="<?= (float)ayar('kasiyer_max_indirim','0') ?>" min="0" max="100" step="0.5" style="max-width:140px">
                            <div class="form-text">Kasiyer rolü bir satışta toplam indirimi bu oranın üzerine çıkaramaz (0 = sınırsız). Yönetici her zaman sınırsızdır.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-hourglass-split text-primary"></i> Bekleyen Satış Uyarısı</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="bekleyen satış uyarısı gün">Kaç Gün Sonra Uyarılsın</label>
                            <input type="number" name="bekleyen_satis_uyari_gun" class="form-control ayar-input" value="<?= (int)ayar('bekleyen_satis_uyari_gun','3') ?>" min="1" max="90" style="max-width:140px">
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text text-primary"></i> Fatura / Fiş Alt Notu</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="fatura alt notu">Alt Not</label>
                            <textarea name="fatura_alt_not" class="form-control ayar-input" rows="2" maxlength="500" placeholder="Bizi tercih ettiğiniz için teşekkür ederiz."><?= escH(ayar('fatura_alt_not','')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Finans Ayarları ── -->
        <div class="tab-pane fade" id="tab-finans">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-wallet2 text-primary"></i> Düşük Kasa Bakiyesi Uyarısı</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="düşük kasa bakiyesi">Uyarı Eşiği (₺)</label>
                            <input type="number" name="kasa_min_bakiye_uyari" class="form-control ayar-input" value="<?= (float)ayar('kasa_min_bakiye_uyari','0') ?>" min="0" step="0.01" style="max-width:180px">
                            <div class="form-text">Kasa bakiyesi bu tutarın altına düşünce uyarı gösterilir (0 = kapalı).</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-check2-square text-primary"></i> Gider Onay Limiti</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="gider onay limiti">Kasiyer İçin Onaysız Maksimum Gider (₺)</label>
                            <input type="number" name="gider_onay_limiti" class="form-control ayar-input" value="<?= (float)ayar('gider_onay_limiti','0') ?>" min="0" step="0.01" style="max-width:180px">
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-x text-primary"></i> Taksit Gecikme Cezası</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="gecikme cezası oranı">Aylık Gecikme Cezası Oranı (%)</label>
                            <input type="number" id="cezaOrani" name="taksit_gecikme_cezasi_oran" class="form-control ayar-input" value="<?= (float)ayar('taksit_gecikme_cezasi_oran','0') ?>" min="0" step="0.1" style="max-width:150px">
                            <div class="form-text" id="cezaOrnek">Bilgi amaçlı gösterilir; otomatik tahsil edilmez.</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-piggy-bank text-primary"></i> Erken Ödeme İndirimi</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="erken ödeme indirimi">İndirim Oranı (%)</label>
                            <input type="number" name="taksit_erken_odeme_indirim" class="form-control ayar-input" value="<?= (float)ayar('taksit_erken_odeme_indirim','0') ?>" min="0" max="100" step="0.1" style="max-width:150px">
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-flag text-primary"></i> Takip Eşiği</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="takip eşiği gün">Gecikme Gün Eşiği</label>
                            <input type="number" name="taksit_takip_esik_gun" class="form-control ayar-input" value="<?= (int)ayar('taksit_takip_esik_gun','30') ?>" min="1" max="365" style="max-width:150px">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Bildirim Eşikleri (özet — düzenleme ilgili sekmede) ── -->
        <div class="tab-pane fade" id="tab-bildirim">
            <div class="alert alert-light border small">
                <i class="bi bi-info-circle"></i> Bu sekme yalnızca tüm uyarı eşiklerinin <strong>özetini</strong> gösterir — düzenlemek için ilgili sekmeye gidin (aşağıdaki linkler).
            </div>
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Eşik</th><th>Mevcut Değer</th><th>Sekme</th></tr></thead>
                        <tbody>
                        <?php
                        $esikler = [
                            ['Minimum Stok Uyarısı', 'min_stok_uyari', 'adet', 'tab-sistem', 'Sistem'],
                            ['Teşhir Süre Uyarısı', 'tesir_uyari_gun', 'gün', 'tab-sistem', 'Sistem'],
                            ['Sayım Periyodu', 'sayim_periyot_gun', 'gün', 'tab-sistem', 'Sistem'],
                            ['Bekleyen Satış Uyarısı', 'bekleyen_satis_uyari_gun', 'gün', 'tab-satis', 'Satış'],
                            ['Düşük Kasa Bakiyesi', 'kasa_min_bakiye_uyari', '₺', 'tab-finans', 'Finans'],
                            ['Gider Onay Limiti', 'gider_onay_limiti', '₺', 'tab-finans', 'Finans'],
                            ['Taksit Takip Eşiği', 'taksit_takip_esik_gun', 'gün', 'tab-finans', 'Finans'],
                        ];
                        foreach ($esikler as [$ad, $anahtar, $birim, $tabId, $tabAd]):
                            $deger = ayar($anahtar, '0');
                        ?>
                        <tr>
                            <td><?= escH($ad) ?></td>
                            <td class="fw-bold"><?= $deger == 0 ? '<span class="text-muted">Kapalı</span>' : escH($deger) . ' ' . $birim ?></td>
                            <td><a href="#" class="btn btn-sm btn-outline-primary py-0" onclick="document.querySelector('[data-bs-target=\'#<?= $tabId ?>\']').click(); return false;"><?= $tabAd ?> →</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Çalışma Zamanı ── -->
        <div class="tab-pane fade" id="tab-zaman">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-week text-primary"></i> Çalışma Günleri</div>
                        <div class="card-body">
                            <?php $seciliGunler = array_map('intval', array_filter(explode(',', ayar('calisma_gunleri','1,2,3,4,5,6')))); ?>
                            <div class="d-flex flex-wrap gap-2 ayar-satir" data-label="çalışma günleri">
                                <?php foreach (['1'=>'Pzt','2'=>'Sal','3'=>'Çar','4'=>'Per','5'=>'Cum','6'=>'Cmt','7'=>'Paz'] as $gn => $etiket): ?>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="checkbox" name="calisma_gunleri_secim[]" value="<?= $gn ?>" id="gun<?= $gn ?>" <?= in_array((int)$gn,$seciliGunler,true)?'checked':'' ?>>
                                    <label class="form-check-label btn btn-sm btn-outline-primary px-2 py-1" for="gun<?= $gn ?>"><?= $etiket ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text mt-2">Raporlarda ve otomasyon uyarılarında referans alınır.</div>
                            <hr>
                            <label class="form-label fw-semibold ayar-satir" data-label="mesai saatleri">Mesai Saatleri</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="time" name="mesai_baslangic" class="form-control ayar-input" value="<?= escH(ayar('mesai_baslangic','09:00')) ?>">
                                </div>
                                <div class="col-6">
                                    <input type="time" name="mesai_bitis" class="form-control ayar-input" value="<?= escH(ayar('mesai_bitis','19:00')) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-x text-primary"></i> Resmi Tatiller</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="resmi tatil günleri">Tatil Tarihleri</label>
                            <textarea name="resmi_tatiller" class="form-control ayar-input" rows="4" placeholder="2026-01-01, 2026-04-23, 2026-05-01"><?= escH(ayar('resmi_tatiller','')) ?></textarea>
                            <div class="form-text">YYYY-AA-GG formatında, virgülle ayrılmış liste.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Sistem & Altyapı ── -->
        <div class="tab-pane fade" id="tab-altyapi">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-globe text-primary"></i> Zaman Dilimi</div>
                        <div class="card-body">
                            <label class="form-label fw-semibold ayar-satir" data-label="zaman dilimi timezone">Uygulama Zaman Dilimi</label>
                            <select name="zaman_dilimi" class="form-select ayar-input">
                                <?php foreach (['Europe/Istanbul','Europe/London','Europe/Berlin','UTC'] as $tz): ?>
                                <option value="<?= $tz ?>" <?= ayar('zaman_dilimi','Europe/Istanbul')===$tz?'selected':'' ?>><?= $tz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-eye text-primary"></i> Rol Bazlı Dashboard Görünürlüğü</div>
                        <div class="card-body">
                            <div class="form-check form-switch ayar-satir" data-label="kasiyer finans dashboard">
                                <input class="form-check-input" type="checkbox" name="dashboard_kasiyer_finans" id="dkf" <?= ayar('dashboard_kasiyer_finans','1')==='1'?'checked':'' ?>>
                                <label class="form-check-label" for="dkf">Kasiyer dashboard'da kasa/ciro/tahsilat bilgilerini görsün</label>
                            </div>
                            <div class="form-check form-switch mt-2 ayar-satir" data-label="depo ciro dashboard">
                                <input class="form-check-input" type="checkbox" name="dashboard_depo_ciro" id="ddc" <?= ayar('dashboard_depo_ciro','0')==='1'?'checked':'' ?>>
                                <label class="form-check-label" for="ddc">Depo rolü dashboard'da parasal (ciro/envanter değeri) bilgi görsün</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm border-danger">
                        <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-cone-striped"></i> Bakım Modu</div>
                        <div class="card-body">
                            <div class="form-check form-switch ayar-satir" data-label="bakım modu">
                                <input class="form-check-input" type="checkbox" name="bakim_modu" id="bakimSw" <?= ayar('bakim_modu','0')==='1'?'checked':'' ?> onchange="bakimUyari()">
                                <label class="form-check-label fw-semibold" for="bakimSw">Bakım Modunu Aç</label>
                            </div>
                            <div class="form-text">Açıkken yönetici dışındaki kullanıcılar giriş yapamaz, bakım mesajı görür. <strong>Kapalıdan açığa geçirirken şifreniz istenir.</strong></div>
                            <div id="bakimSifreAlani" class="mt-2" style="display:none">
                                <label class="form-label small fw-semibold">Şifrenizi Girin (onay için)</label>
                                <input type="password" name="onay_sifre" class="form-control" style="max-width:250px" autocomplete="current-password">
                            </div>
                            <label class="form-label fw-semibold mt-3 ayar-satir" data-label="bakım mesajı">Bakım Mesajı</label>
                            <textarea name="bakim_mesaji" class="form-control ayar-input" rows="2" maxlength="255"><?= escH(ayar('bakim_mesaji','Sistem bakımdadır, kısa süre sonra tekrar deneyin.')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Araçlar & Geçmiş ── -->
        <div class="tab-pane fade" id="tab-araclar">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-shield-check fs-3 text-primary"></i>
                            <div class="fw-semibold mt-2">Ayarları Doğrula</div>
                            <div class="small text-muted mb-2">Çelişki/bağımlılık taraması</div>
                            <a href="?dogrula=1" class="btn btn-sm btn-outline-primary w-100">Doğrula</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-download fs-3 text-success"></i>
                            <div class="fw-semibold mt-2">Dışa Aktar</div>
                            <div class="small text-muted mb-2">Tüm ayarları JSON indir</div>
                            <a href="?export=json" class="btn btn-sm btn-outline-success w-100">İndir</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-upload fs-3 text-info"></i>
                            <div class="fw-semibold mt-2">İçe Aktar</div>
                            <div class="small text-muted mb-2">JSON dosyasından yükle</div>
                            <label class="btn btn-sm btn-outline-info w-100 mb-0" for="importDosyaInput">Dosya Seç</label>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm h-100 border-warning">
                        <div class="card-body text-center">
                            <i class="bi bi-arrow-counterclockwise fs-3 text-warning"></i>
                            <div class="fw-semibold mt-2">Tümünü Sıfırla</div>
                            <div class="small text-muted mb-2">Tüm ayarları varsayılana döndür</div>
                            <button type="submit" form="sifirlaTumForm" class="btn btn-sm btn-outline-warning w-100" onclick="return confirm('TÜM ayarlar varsayılana sıfırlanacak. Emin misiniz?')">Sıfırla</button>
                        </div>
                    </div>
                </div>
            </div>
            <form id="importForm" method="post" enctype="multipart/form-data" class="d-none">
                <?= csrfField() ?><input type="hidden" name="aksiyon" value="import_onizle">
                <input type="file" name="import_dosya" id="importDosyaInput" accept=".json" onchange="document.getElementById('importForm').submit()">
            </form>
            <form id="sifirlaTumForm" method="post" class="d-none"><?= csrfField() ?><input type="hidden" name="aksiyon" value="sifirla_tumu"></form>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-collection text-primary"></i> Hazır Profil Şablonları</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($sablonlar as $sKey => $s): ?>
                        <div class="col-md-6">
                            <div class="border rounded p-3">
                                <div class="fw-semibold mb-1"><?= escH($s['ad']) ?></div>
                                <ul class="small text-muted mb-2">
                                    <?php foreach ($s['ayarlar'] as $k => $v): ?><li><?= escH($k) ?>: <strong><?= escH($v) ?></strong></li><?php endforeach; ?>
                                </ul>
                                <form method="post" onsubmit="return confirm('&quot;<?= escH($s['ad']) ?>&quot; şablonu uygulanacak, ilgili ayarlar değişecek. Onaylıyor musunuz?')">
                                    <?= csrfField() ?><input type="hidden" name="aksiyon" value="sablon_uygula"><input type="hidden" name="sablon" value="<?= $sKey ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Uygula</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-trash3 text-primary"></i> Eski Aktivite Loglarını Temizle</div>
                <div class="card-body">
                    <form method="post" class="d-flex gap-2 align-items-end" onsubmit="return confirm('Belirtilen süreden eski loglar kalıcı olarak silinecek. Emin misiniz?')">
                        <?= csrfField() ?><input type="hidden" name="aksiyon" value="log_temizle">
                        <div>
                            <label class="form-label small mb-1">Kaç Günden Eski Loglar Silinsin</label>
                            <input type="number" name="gun" class="form-control form-control-sm" value="90" min="30" max="3650" style="max-width:140px">
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Temizle</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history text-primary"></i> Değişiklik Geçmişi <span class="text-muted small fw-normal">(son 40 kayıt)</span></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Tarih</th><th>Ayar</th><th>Eski</th><th>Yeni</th><th>Not</th><th>Kullanıcı</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($gecmis)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">Henüz değişiklik yok</td></tr>
                        <?php endif; ?>
                        <?php foreach ($gecmis as $g): ?>
                        <tr>
                            <td class="small"><?= tarihSaat($g['created_at']) ?></td>
                            <td><code><?= escH($g['anahtar']) ?></code></td>
                            <td class="text-muted small"><?= escH(mb_substr((string)$g['eski_deger'],0,40)) ?></td>
                            <td class="fw-semibold small"><?= escH(mb_substr((string)$g['yeni_deger'],0,40)) ?></td>
                            <td class="small text-muted"><?= escH($g['not_metni'] ?? '-') ?></td>
                            <td class="small"><?= escH($g['ad_soyad'] ?? '-') ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu ayar önceki değerine geri yüklensin mi?')">
                                    <?= csrfField() ?><input type="hidden" name="aksiyon" value="gecmis_geri_yukle"><input type="hidden" name="gecmis_id" value="<?= $g['id'] ?>">
                                    <button type="submit" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-1" title="Geri Yükle"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->

    <div class="mt-4 d-flex gap-2 position-sticky bottom-0 bg-white py-2" style="z-index:10">
        <button type="submit" class="btn btn-primary btn-lg px-4">
            <i class="bi bi-save"></i> Ayarları Kaydet <kbd class="ms-1 small">Ctrl+S</kbd>
        </button>
        <a href="<?= BASE_URL ?>/modules/dashboard/" class="btn btn-outline-secondary btn-lg" id="iptalLink">İptal</a>
    </div>

</form>

<script>
const temaRenkler = { primary:'#0d6efd', success:'#198754', danger:'#dc3545', warning:'#e08c00', dark:'#212529', purple:'#6f42c1' };
document.querySelectorAll('input[name="tema_renk"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const renk = temaRenkler[this.value];
        if (!renk) return;
        document.getElementById('temaOnizleme').style.backgroundColor = renk;
        document.querySelector('.navbar.fixed-top').style.backgroundColor = renk;
        document.documentElement.style.setProperty('--bs-primary', renk);
        document.documentElement.style.setProperty('--theme-hex', renk);
        const kutu = document.querySelector('.toplam-kutu');
        if (kutu) kutu.style.background = `linear-gradient(135deg, ${renk}, ${renk}cc)`;
    });
});

// ── Bakım modu şifre uyarısı ─────────────────────────────────
function bakimUyari() {
    document.getElementById('bakimSifreAlani').style.display = document.getElementById('bakimSw').checked ? '' : 'none';
}
bakimUyari();

// ── Gecikme cezası canlı örnek ────────────────────────────────
function cezaOrnekGuncelle() {
    const oran = parseFloat(document.getElementById('cezaOrani').value) || 0;
    const el = document.getElementById('cezaOrnek');
    if (oran <= 0) { el.textContent = 'Bilgi amaçlı gösterilir; otomatik tahsil edilmez.'; return; }
    const ornekTutar = 1000, ornekGun = 45, ay = Math.ceil(ornekGun / 30);
    const ceza = (ornekTutar * oran / 100 * ay).toFixed(2);
    el.innerHTML = `Örnek: ${ornekGun} gün geciken ${ornekTutar.toLocaleString('tr-TR')} ₺ taksitte gösterilecek ceza: <strong>${ceza} ₺</strong>. Bilgi amaçlıdır, otomatik tahsil edilmez.`;
}
document.getElementById('cezaOrani')?.addEventListener('input', cezaOrnekGuncelle);
cezaOrnekGuncelle();

// ── Ayar arama ────────────────────────────────────────────────
const araInput = document.getElementById('ayarAra');
const araSonuc = document.getElementById('ayarAraSonuc');
const tumSatirlar = Array.from(document.querySelectorAll('.ayar-satir'));
araInput?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    const aktifPane = document.querySelector('.tab-pane.active');
    if (!q) {
        tumSatirlar.forEach(s => s.style.display = '');
        araSonuc.textContent = '';
        return;
    }
    let aktifEslesme = 0;
    const digerSekmeler = {};
    tumSatirlar.forEach(satir => {
        const eslesir = (satir.dataset.label || '').includes(q);
        const buPane = satir.closest('.tab-pane');
        if (buPane === aktifPane) {
            satir.style.display = eslesir ? '' : 'none';
            if (eslesir) aktifEslesme++;
        } else if (eslesir) {
            const tabBtn = document.querySelector(`[data-bs-target="#${buPane.id}"]`);
            const ad = tabBtn ? tabBtn.textContent.trim() : buPane.id;
            digerSekmeler[ad] = (digerSekmeler[ad] || 0) + 1;
        }
    });
    let mesaj = aktifEslesme ? `Bu sekmede ${aktifEslesme} eşleşme.` : 'Bu sekmede eşleşme yok.';
    const digerListe = Object.entries(digerSekmeler).map(([ad, adet]) => `${ad} (${adet})`).join(', ');
    if (digerListe) mesaj += ' Diğer sekmelerde: ' + digerListe;
    araSonuc.textContent = mesaj;
});

// ── Kaydedilmemiş değişiklik uyarısı + Ctrl+S ────────────────
let formDegisti = false;
const form = document.getElementById('ayarlarForm');
form.addEventListener('input', () => formDegisti = true);
form.addEventListener('change', () => formDegisti = true);
form.addEventListener('submit', () => formDegisti = false);
window.addEventListener('beforeunload', function (e) {
    if (!formDegisti) return;
    e.preventDefault(); e.returnValue = '';
});
document.getElementById('iptalLink').addEventListener('click', function (e) {
    if (formDegisti && !confirm('Kaydedilmemiş değişiklikleriniz var. Yine de çıkmak istiyor musunuz?')) e.preventDefault();
});
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); form.requestSubmit(); }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
