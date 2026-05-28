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
        <a class="nav-link <?= navAktif('stok') ?>"
           href="<?= BASE_URL ?>/modules/stok/">
            <i class="bi bi-archive"></i><span>Stok Takibi</span>
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

    <li class="sidebar-section-title">Finans</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('finans') ?>"
           href="<?= BASE_URL ?>/modules/finans/">
            <i class="bi bi-cash-stack"></i><span>Kasa & Finans</span>
        </a>
    </li>

    <li class="sidebar-section-title">Raporlar</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('raporlar') ?>"
           href="<?= BASE_URL ?>/modules/raporlar/">
            <i class="bi bi-bar-chart-line"></i><span>Raporlar</span>
        </a>
    </li>

    <?php if (($_SESSION['rol'] ?? '') === 'yonetici'): ?>
    <li class="sidebar-section-title">Yönetim</li>
    <li class="nav-item">
        <a class="nav-link <?= navAktif('kullanicilar') ?>"
           href="<?= BASE_URL ?>/modules/kullanicilar/">
            <i class="bi bi-person-gear"></i><span>Kullanıcılar</span>
        </a>
    </li>
    <?php endif; ?>
</ul>
