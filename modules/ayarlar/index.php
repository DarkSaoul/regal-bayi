<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Sistem Ayarları';
$pdo = db();

// Kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $izinli = [
        'firma_adi','firma_slogan','firma_telefon','firma_email',
        'firma_adres','firma_sehir','firma_vergi_no','firma_vergi_daire','firma_iban',
        'site_basligi','para_birimi','para_sembol','kdv_orani',
        'fatura_prefix','min_stok_uyari','tema_renk','tarih_formati','kayit_basi'
    ];
    foreach ($izinli as $key) {
        if (isset($_POST[$key])) {
            ayarKaydet($key, trim($_POST[$key]));
        }
    }
    flash('basari', 'Ayarlar kaydedildi.');
    header('Location: index.php'); exit;
}

// Tüm ayarları çek
$ayarlar = $pdo->query("SELECT * FROM ayarlar ORDER BY grup, anahtar")->fetchAll();
$gruplar = [];
foreach ($ayarlar as $a) {
    $gruplar[$a['grup']][] = $a;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-gear text-primary"></i> Sistem Ayarları</h4>
</div>

<form method="post" id="ayarlarForm">
    <?= csrfField() ?>

    <!-- Tab menü -->
    <ul class="nav nav-tabs mb-4" id="ayarTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-firma" type="button">
                <i class="bi bi-building"></i> Firma Bilgileri
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sistem" type="button">
                <i class="bi bi-sliders"></i> Sistem
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-gorunum" type="button">
                <i class="bi bi-palette"></i> Görünüm
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ── Firma Bilgileri ── -->
        <div class="tab-pane fade show active" id="tab-firma">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-building text-primary"></i> Firma Bilgileri
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Firma Adı <span class="text-danger">*</span></label>
                                    <input type="text" name="firma_adi" class="form-control"
                                           value="<?= escH(ayar('firma_adi', 'Regal Bayi')) ?>" required maxlength="100">
                                    <div class="form-text">Faturalarda ve sistem başlığında görünür.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Slogan / Açıklama</label>
                                    <input type="text" name="firma_slogan" class="form-control"
                                           value="<?= escH(ayar('firma_slogan')) ?>" maxlength="150">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Telefon</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                        <input type="text" name="firma_telefon" class="form-control"
                                               value="<?= escH(ayar('firma_telefon')) ?>" maxlength="20">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">E-posta</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" name="firma_email" class="form-control"
                                               value="<?= escH(ayar('firma_email')) ?>" maxlength="100">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Şehir</label>
                                    <input type="text" name="firma_sehir" class="form-control"
                                           value="<?= escH(ayar('firma_sehir')) ?>" maxlength="50">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold">Adres</label>
                                    <textarea name="firma_adres" class="form-control" rows="2" maxlength="300"><?= escH(ayar('firma_adres')) ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Vergi No</label>
                                    <input type="text" name="firma_vergi_no" class="form-control"
                                           value="<?= escH(ayar('firma_vergi_no')) ?>" maxlength="11">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Vergi Dairesi</label>
                                    <input type="text" name="firma_vergi_daire" class="form-control"
                                           value="<?= escH(ayar('firma_vergi_daire')) ?>" maxlength="100">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">IBAN</label>
                                    <input type="text" name="firma_iban" class="form-control"
                                           value="<?= escH(ayar('firma_iban')) ?>" maxlength="32" placeholder="TR00 0000 0000 0000 0000 0000 00">
                                </div>
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
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-display text-primary"></i> Site Başlığı
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Tarayıcı Sekme Başlığı</label>
                                <input type="text" name="site_basligi" class="form-control"
                                       value="<?= escH(ayar('site_basligi', 'Regal Bayi Yönetim')) ?>" maxlength="80">
                                <div class="form-text">Her sayfanın başlık çubuğunda görünür.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Fatura Numarası Öneki</label>
                                <div class="input-group">
                                    <input type="text" name="fatura_prefix" class="form-control"
                                           value="<?= escH(ayar('fatura_prefix', 'F')) ?>" maxlength="5" style="max-width:120px">
                                    <span class="input-group-text text-muted">örn: <?= escH(ayar('fatura_prefix','F')) ?>202506001</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-currency-exchange text-primary"></i> Para Birimi & Vergi
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Para Birimi</label>
                                <select name="para_birimi" class="form-select">
                                    <?php foreach(['TRY'=>'Türk Lirası (₺)','USD'=>'Dolar ($)','EUR'=>'Euro (€)','GBP'=>'Sterlin (£)'] as $k=>$v): ?>
                                    <option value="<?= $k ?>" <?= ayar('para_birimi','TRY')===$k?'selected':'' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Para Sembolü</label>
                                <input type="text" name="para_sembol" class="form-control"
                                       value="<?= escH(ayar('para_sembol','₺')) ?>" maxlength="5" style="max-width:100px">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Varsayılan KDV Oranı (%)</label>
                                <select name="kdv_orani" class="form-select" style="max-width:120px">
                                    <?php foreach(['0','1','10','20'] as $k): ?>
                                    <option value="<?= $k ?>" <?= ayar('kdv_orani','20')===$k?'selected':'' ?>>%<?= $k ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-archive text-primary"></i> Stok
                        </div>
                        <div class="card-body">
                            <label class="form-label fw-semibold">Varsayılan Minimum Stok Uyarı Seviyesi</label>
                            <input type="number" name="min_stok_uyari" class="form-control"
                                   value="<?= (int)ayar('min_stok_uyari','1') ?>" min="0" max="999" style="max-width:120px">
                            <div class="form-text">Yeni ürün eklenirken otomatik atanır.</div>
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
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-palette text-primary"></i> Tema Rengi
                        </div>
                        <div class="card-body">
                            <label class="form-label fw-semibold">Navbar ve Vurgu Rengi</label>
                            <div class="d-flex gap-3 flex-wrap mt-2">
                                <?php
                                $temalar = [
                                    'primary' => ['#0d6efd','Mavi'],
                                    'success' => ['#198754','Yeşil'],
                                    'danger'  => ['#dc3545','Kırmızı'],
                                    'warning' => ['#ffc107','Sarı'],
                                    'dark'    => ['#212529','Koyu'],
                                    'purple'  => ['#6f42c1','Mor'],
                                ];
                                $aktifTema = ayar('tema_renk','primary');
                                foreach ($temalar as $k => [$renk, $ad]):
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tema_renk"
                                           id="tema_<?= $k ?>" value="<?= $k ?>"
                                           <?= $aktifTema===$k?'checked':'' ?>>
                                    <label class="form-check-label d-flex align-items-center gap-2" for="tema_<?= $k ?>">
                                        <span style="width:24px;height:24px;border-radius:6px;background:<?= $renk ?>;display:inline-block;border:2px solid rgba(0,0,0,.1)"></span>
                                        <?= $ad ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Canlı önizleme -->
                            <div class="mt-3">
                                <div class="small text-muted mb-1">Önizleme:</div>
                                <nav id="temaOnizleme" class="navbar navbar-dark rounded px-3 py-2"
                                     style="background-color:<?= $_tema_renkler[ayar('tema_renk','primary')]['hex'] ?>">
                                    <span class="navbar-brand mb-0 fw-bold">
                                        <i class="bi bi-shop"></i> <?= escH(ayar('firma_adi','Regal Bayi')) ?>
                                    </span>
                                    <span class="badge bg-warning text-dark">Önizleme</span>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white fw-semibold">
                            <i class="bi bi-layout-text-sidebar text-primary"></i> Görüntüleme
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Tarih Formatı</label>
                                <select name="tarih_formati" class="form-select">
                                    <?php
                                    $formatlar = [
                                        'd.m.Y' => date('d.m.Y').' (gün.ay.yıl)',
                                        'Y-m-d' => date('Y-m-d').' (yıl-ay-gün)',
                                        'd/m/Y' => date('d/m/Y').' (gün/ay/yıl)',
                                        'm/d/Y' => date('m/d/Y').' (ay/gün/yıl)',
                                    ];
                                    $aktifFormat = ayar('tarih_formati','d.m.Y');
                                    foreach ($formatlar as $k=>$v):
                                    ?>
                                    <option value="<?= $k ?>" <?= $aktifFormat===$k?'selected':'' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Sayfa Başına Kayıt</label>
                                <select name="kayit_basi" class="form-select" style="max-width:120px">
                                    <?php foreach(['10','15','25','50','100'] as $n): ?>
                                    <option value="<?= $n ?>" <?= ayar('kayit_basi','25')===$n?'selected':'' ?>><?= $n ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-lg px-4">
            <i class="bi bi-save"></i> Ayarları Kaydet
        </button>
        <a href="<?= BASE_URL ?>/modules/dashboard/" class="btn btn-outline-secondary btn-lg">İptal</a>
    </div>

</form>

<script>
const temaRenkler = {
    primary: '#0d6efd',
    success: '#198754',
    danger:  '#dc3545',
    warning: '#e08c00',
    dark:    '#212529',
    purple:  '#6f42c1'
};

document.querySelectorAll('input[name="tema_renk"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const renk = temaRenkler[this.value];
        if (!renk) return;
        // Önizleme güncelle
        document.getElementById('temaOnizleme').style.backgroundColor = renk;
        // Sayfa navbar'ını da anında değiştir
        document.querySelector('.navbar.fixed-top').style.backgroundColor = renk;
        // CSS değişkenlerini güncelle
        document.documentElement.style.setProperty('--bs-primary', renk);
        document.documentElement.style.setProperty('--theme-hex', renk);
        // Toplam kutusu
        const kutu = document.querySelector('.toplam-kutu');
        if (kutu) kutu.style.background = `linear-gradient(135deg, ${renk}, ${renk}cc)`;
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
