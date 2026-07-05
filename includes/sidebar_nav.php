<ul class="sidebar-nav nav flex-column py-2 w-100">
    <li class="nav-item">
        <a class="nav-link <?= navAktif('dashboard') ?>"
           href="<?= BASE_URL ?>/modules/dashboard/">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
    </li>

    <li class="sidebar-section-title">Ürün & Stok</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('tedarikciler') ?>"
           href="<?= BASE_URL ?>/modules/tedarikciler/">
            <i class="bi bi-truck"></i><span>Tedarikçiler</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('urunler') ?>"
           href="<?= BASE_URL ?>/modules/urunler/">
            <i class="bi bi-box-seam"></i><span>Ürünler</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('kategoriler') ?>"
           href="<?= BASE_URL ?>/modules/kategoriler/">
            <i class="bi bi-tags"></i><span>Kategoriler</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('toplu_fiyat') ?>"
           href="<?= BASE_URL ?>/modules/urunler/toplu_fiyat.php">
            <i class="bi bi-percent"></i><span>Toplu Fiyat</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('stok') ?>"
           href="<?= BASE_URL ?>/modules/stok/">
            <i class="bi bi-archive"></i><span>Stok Takibi</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('tesir') ?>"
           href="<?= BASE_URL ?>/modules/stok/tesir.php">
            <i class="bi bi-shop-window"></i><span>Teşhir Yönetimi</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('sayim') ?>"
           href="<?= BASE_URL ?>/modules/stok/sayim.php">
            <i class="bi bi-clipboard-check"></i><span>Stok Sayımı</span>
        </a>
    </li>

    <li class="sidebar-section-title">Satış</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('musteriler') ?>"
           href="<?= BASE_URL ?>/modules/musteriler/">
            <i class="bi bi-people"></i><span>Müşteriler</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('satislar') ?>"
           href="<?= BASE_URL ?>/modules/satislar/">
            <i class="bi bi-receipt"></i><span>Satışlar</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('teslimatlar') ?>"
           href="<?= BASE_URL ?>/modules/satislar/teslimatlar.php">
            <i class="bi bi-truck"></i><span>Teslimatlar</span>
        </a>
    </li>

    <li class="sidebar-section-title">Finans</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('finans') ?>"
           href="<?= BASE_URL ?>/modules/finans/">
            <i class="bi bi-cash-stack"></i><span>Kasa & Finans</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('taksit_takvimi') ?>"
           href="<?= BASE_URL ?>/modules/finans/taksit_takvimi.php">
            <i class="bi bi-calendar-week"></i><span>Taksit Takvimi</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('kapanis') ?>"
           href="<?= BASE_URL ?>/modules/finans/kapanis.php">
            <i class="bi bi-door-closed"></i><span>Kasa Kapanışı</span>
        </a>
    </li>

    <li class="sidebar-section-title">Raporlar</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('raporlar') ?>"
           href="<?= BASE_URL ?>/modules/raporlar/">
            <i class="bi bi-bar-chart-line"></i><span>Raporlar</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('kar_zarar') ?>"
           href="<?= BASE_URL ?>/modules/raporlar/kar_zarar.php">
            <i class="bi bi-graph-up-arrow"></i><span>Kâr-Zarar</span>
        </a>
    </li>

    <?php if (($_SESSION['rol'] ?? '') === 'yonetici'): ?>
    <li class="sidebar-section-title">Yönetim</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('performans') ?>"
           href="<?= BASE_URL ?>/modules/satislar/performans.php">
            <i class="bi bi-person-badge"></i><span>Kasiyer Performansı</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('kullanicilar') ?>"
           href="<?= BASE_URL ?>/modules/kullanicilar/">
            <i class="bi bi-person-gear"></i><span>Kullanıcılar</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('ayarlar') ?>"
           href="<?= BASE_URL ?>/modules/ayarlar/">
            <i class="bi bi-gear"></i><span>Ayarlar</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('aktivite') ?>"
           href="<?= BASE_URL ?>/modules/kullanicilar/aktivite.php">
            <i class="bi bi-clock-history"></i><span>Aktivite Logu</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('yedekleme') ?>"
           href="<?= BASE_URL ?>/modules/yedekleme/">
            <i class="bi bi-cloud-arrow-down"></i><span>Yedekleme</span>
        </a>
    </li>
    <?php endif; ?>
</ul>
