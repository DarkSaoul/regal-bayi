</main>
</div><!-- /layout-wrapper -->

<!-- ══ MOBİL ALT NAVİGASYON ════════════════════════════════ -->
<nav id="bottom-nav" class="d-md-none">
    <a href="<?= BASE_URL ?>/modules/dashboard/"
       class="bn-item <?= $mevcut_sayfa==='dashboard'?'active':'' ?>">
        <i class="bi bi-speedometer2"></i>
        <span>Ana Sayfa</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/stok/"
       class="bn-item <?= $mevcut_sayfa==='stok'?'active':'' ?>">
        <i class="bi bi-archive"></i>
        <span>Stok</span>
    </a>
    <!-- FAB: Yeni Satış -->
    <a href="<?= BASE_URL ?>/modules/satislar/yeni.php" class="bn-item bn-fab">
        <i class="bi bi-plus-lg"></i>
    </a>
    <a href="<?= BASE_URL ?>/modules/satislar/"
       class="bn-item <?= $mevcut_sayfa==='satislar'?'active':'' ?>">
        <i class="bi bi-receipt"></i>
        <span>Satışlar</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/finans/"
       class="bn-item <?= $mevcut_sayfa==='finans'?'active':'' ?>">
        <i class="bi bi-cash-stack"></i>
        <span>Finans</span>
    </a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mobil offcanvas: link tıklayınca kapat, sonra git
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('mobileSidebar');
    if (!sidebar) return;
    sidebar.querySelectorAll('a[href]').forEach(link => {
        link.addEventListener('click', function(e) {
            const oc = bootstrap.Offcanvas.getInstance(sidebar);
            if (oc) {
                e.preventDefault();
                const href = this.href;
                oc.hide();
                sidebar.addEventListener('hidden.bs.offcanvas', () => {
                    window.location.href = href;
                }, { once: true });
            }
        });
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
