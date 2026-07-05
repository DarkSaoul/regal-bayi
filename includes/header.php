<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Aktif menü: önce dosya bazlı özel sayfalar, yoksa modül klasörü.
// (Yalnızca klasöre bakmak, örn. stok/tesir.php'de menüyü "Stok Takibi"nde kilitliyordu.)
$_nav_script = basename($_SERVER['PHP_SELF']);
$_nav_modul  = basename(dirname($_SERVER['PHP_SELF']));
$_nav_ozel = [
    'stok'         => ['tesir.php' => 'tesir', 'sayim.php' => 'sayim'],
    'urunler'      => ['toplu_fiyat.php' => 'toplu_fiyat'],
    'finans'       => ['taksit_takvimi.php' => 'taksit_takvimi', 'kapanis.php' => 'kapanis'],
    'raporlar'     => ['kar_zarar.php' => 'kar_zarar'],
    'kullanicilar' => ['aktivite.php' => 'aktivite'],
];
$mevcut_sayfa = $_nav_ozel[$_nav_modul][$_nav_script] ?? $_nav_modul;
$_sayaclar       = bildirimSayaclari(); // 3 sayaç tek sorguda
$uyari_stok      = $_sayaclar['dusuk_stok'];
$bekleyen_odeme  = $_sayaclar['bekleyen_odeme'];
$geckmis_taksit  = $_sayaclar['gecikmis_taksit'];

// Tema rengi
$_tema = ayar('tema_renk', 'primary');
$_tema_renkler = [
    'primary' => ['hex' => '#0d6efd', 'rgb' => '13,110,253',  'nav_class' => 'bg-primary'],
    'success' => ['hex' => '#198754', 'rgb' => '25,135,84',   'nav_class' => 'bg-success'],
    'danger'  => ['hex' => '#dc3545', 'rgb' => '220,53,69',   'nav_class' => 'bg-danger'],
    'warning' => ['hex' => '#e08c00', 'rgb' => '224,140,0',   'nav_class' => 'bg-warning'],
    'dark'    => ['hex' => '#212529', 'rgb' => '33,37,41',    'nav_class' => 'bg-dark'],
    'purple'  => ['hex' => '#6f42c1', 'rgb' => '111,66,193',  'nav_class' => 'bg-purple'],
];
$_t = $_tema_renkler[$_tema] ?? $_tema_renkler['primary'];

// Aktif menü tespiti
$nav = [
    'dashboard'   => BASE_URL . '/modules/dashboard/',
    'urunler'     => BASE_URL . '/modules/urunler/',
    'stok'        => BASE_URL . '/modules/stok/',
    'tedarikciler'=> BASE_URL . '/modules/tedarikciler/',
    'musteriler'  => BASE_URL . '/modules/musteriler/',
    'satislar'    => BASE_URL . '/modules/satislar/',
    'finans'      => BASE_URL . '/modules/finans/',
    'raporlar'    => BASE_URL . '/modules/raporlar/',
    'kullanicilar'=> BASE_URL . '/modules/kullanicilar/',
];
function navAktif($sayfa) {
    global $mevcut_sayfa;
    return $mevcut_sayfa === $sayfa ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d6efd">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= escH($sayfa_basligi ?? '') ?><?= $sayfa_basligi ? ' | ' : '' ?><?= escH(ayar('site_basligi','Regal Bayi Yönetim')) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
  /* Dinamik tema rengi — ayarlardan gelir */
  :root {
    --bs-primary:        <?= $_t['hex'] ?>;
    --bs-primary-rgb:    <?= $_t['rgb'] ?>;
    --theme-hex:         <?= $_t['hex'] ?>;
    --theme-rgb:         <?= $_t['rgb'] ?>;
  }
  .bg-purple { background-color: #6f42c1 !important; }
  .text-purple { color: #6f42c1 !important; }
  /* Sidebar aktif çizgisi */
  .sidebar-nav .nav-link.active { border-left-color: var(--theme-hex); }
  /* Butonlar ve linkler */
  .btn-primary  { --bs-btn-bg: var(--theme-hex); --bs-btn-border-color: var(--theme-hex);
                  --bs-btn-hover-bg: color-mix(in srgb, var(--theme-hex) 85%, black); }
  .toplam-kutu  { background: linear-gradient(135deg, var(--theme-hex), color-mix(in srgb, var(--theme-hex) 80%, black)) !important; }
  a { color: var(--bs-primary); }
</style>
</head>
<body>

<!-- ══ NAVBAR ══════════════════════════════════════════════ -->
<nav class="navbar navbar-dark fixed-top px-2 px-md-3" style="background-color: <?= $_t['hex'] ?> !important">
    <!-- Mobil: off-canvas toggle -->
    <button class="btn btn-link text-white d-md-none p-1 me-1"
            type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar"
            aria-label="Menü">
        <i class="bi bi-list fs-4"></i>
    </button>

    <a class="navbar-brand fw-bold me-auto" href="<?= BASE_URL ?>/modules/dashboard/">
        <i class="bi bi-shop"></i>
        <span class="d-none d-sm-inline"><?= escH(ayar('firma_adi','Regal Bayi')) ?></span>
        <span class="d-inline d-sm-none"><?= escH(mb_substr(ayar('firma_adi','Regal'),0,8)) ?></span>
    </a>

    <!-- Uyarı ikonları -->
    <div class="d-flex align-items-center gap-1 me-1">
        <?php if ($uyari_stok > 0): ?>
        <a href="<?= BASE_URL ?>/modules/stok/dusuk.php"
           class="btn btn-sm btn-warning py-0 px-2 d-flex align-items-center gap-1"
           title="Düşük Stok">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span class="d-none d-md-inline"><?= $uyari_stok ?></span>
        </a>
        <?php endif; ?>
        <?php if ($bekleyen_odeme > 0): ?>
        <a href="<?= BASE_URL ?>/modules/finans/"
           class="btn btn-sm btn-warning py-0 px-2 d-flex align-items-center gap-1"
           title="Bekleyen Ödeme">
            <i class="bi bi-cash-coin"></i>
            <span class="d-none d-md-inline"><?= $bekleyen_odeme ?></span>
        </a>
        <?php endif; ?>
        <?php if ($geckmis_taksit > 0): ?>
        <a href="<?= BASE_URL ?>/modules/finans/taksit_takvimi.php?filtre=gecmis"
           class="btn btn-sm btn-danger py-0 px-2 d-flex align-items-center gap-1"
           title="Gecikmiş Taksit">
            <i class="bi bi-calendar-x"></i>
            <span class="d-none d-md-inline"><?= $geckmis_taksit ?></span>
        </a>
        <?php endif; ?>
    </div>

    <!-- Kullanıcı dropdown -->
    <div class="dropdown">
        <button class="btn btn-link text-white dropdown-toggle p-1 d-flex align-items-center gap-1"
                type="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle fs-5"></i>
            <span class="d-none d-lg-inline small"><?= escH($_SESSION['ad_soyad'] ?? '') ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text fw-semibold"><?= escH($_SESSION['ad_soyad'] ?? '') ?></span></li>
            <li><span class="dropdown-item-text text-muted small"><?= escH($_SESSION['rol'] ?? '') ?></span></li>
            <li><hr class="dropdown-divider my-1"></li>
            <li>
                <a class="dropdown-item" href="<?= BASE_URL ?>/modules/kullanicilar/profil.php">
                    <i class="bi bi-person-gear me-1"></i> Profilim
                </a>
            </li>
            <li>
                <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/modules/auth/logout.php">
                    <i class="bi bi-box-arrow-right me-1"></i> Çıkış Yap
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- ══ MOBİL OFF-CANVAS SIDEBAR ════════════════════════════ -->
<div class="offcanvas offcanvas-start offcanvas-sidebar" tabindex="-1" id="mobileSidebar">
    <div class="offcanvas-header py-2">
        <h6 class="offcanvas-title"><i class="bi bi-shop me-2"></i>Regal Bayi</h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <?php include __DIR__ . '/sidebar_nav.php'; ?>
    </div>
</div>

<!-- ══ LAYOUT ───────────────────────────────────────────── -->
<div id="layout-wrapper">

<!-- Desktop Sidebar -->
<nav id="desktop-sidebar" class="d-none d-md-flex flex-column">
    <?php include __DIR__ . '/sidebar_nav.php'; ?>
</nav>

<!-- Ana İçerik -->
<main id="main-content" class="flex-grow-1">
    <?php showFlash(); ?>
    <?php if (!empty($_SESSION['yedek_gerekli'])): ?>
    <div class="alert alert-warning border-0 rounded-0 mb-0 d-flex align-items-center gap-2 px-3 py-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
        <span><strong>Bugün yedek alınmamış!</strong> Devam etmek için lütfen veritabanı yedeği alın.</span>
        <a href="<?= BASE_URL ?>/modules/yedekleme/" class="btn btn-sm btn-warning ms-auto fw-semibold">
            <i class="bi bi-cloud-arrow-down"></i> Şimdi Yedek Al
        </a>
    </div>
    <?php endif; ?>
