<?php
// Sistem geneli durum paneli — salt okunur, hiçbir veri değiştirmez
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Sistem Durumu';
$pdo = db();

// ── Sunucu bilgileri ──────────────────────────────────────────
$phpVersiyon = PHP_VERSION;
$mysqlVersiyon = $pdo->query("SELECT VERSION()")->fetchColumn();
$diskBos = @disk_free_space(__DIR__ . '/../..');
$diskToplam = @disk_total_space(__DIR__ . '/../..');
$diskYuzde = ($diskToplam && $diskBos !== false) ? round((1 - $diskBos / $diskToplam) * 100, 1) : null;
$diskUyariGb = (float)ayar('disk_uyari_esik_gb', '1');

// ── Veritabanı boyutu / tablo boyutları ───────────────────────
$dbAdi = $pdo->query("SELECT DATABASE()")->fetchColumn();
$tabloBoyutlari = $pdo->prepare(
    "SELECT TABLE_NAME AS tablo, TABLE_ROWS AS satir, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) AS mb
     FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY (DATA_LENGTH+INDEX_LENGTH) DESC LIMIT 15"
);
$tabloBoyutlari->execute([$dbAdi]);
$tabloBoyutlari = $tabloBoyutlari->fetchAll();
$dbToplamMb = array_sum(array_column($tabloBoyutlari, 'mb'));

// ── Yedek / sayım / kapanış özeti ──────────────────────────────
$sonYedekDosya = null; $sonYedekTarih = null;
foreach (glob(__DIR__ . '/../../backups/*.sql') ?: [] as $dosya) {
    $mtime = filemtime($dosya);
    if ($sonYedekTarih === null || $mtime > $sonYedekTarih) { $sonYedekTarih = $mtime; $sonYedekDosya = basename($dosya); }
}
$bugunYedekVar = bugunYedekVarMi();
$sonSayimTarihi = $pdo->query("SELECT MAX(created_at) FROM sayimlar")->fetchColumn();
$sonKapanisTarihi = ayar('son_kapanis_tarihi', '');
$bugun = date('Y-m-d');

// ── Kullanıcı aktivitesi (son giriş bilgisi — canlı oturum takibi değildir) ──
$kullaniciAktivite = $pdo->query("SELECT ad_soyad, kullanici_adi, rol, son_giris, aktif FROM kullanicilar ORDER BY son_giris IS NULL, son_giris DESC")->fetchAll();

// ── Güvenlik olayları özeti (son 7 gün) ────────────────────────
$guvenlikOzet = $pdo->query(
    "SELECT aksiyon, COUNT(*) AS adet FROM aktivite_loglari
     WHERE aksiyon IN ('giris','giris_basarisiz') AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY aksiyon"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$sonBasarisizGirisler = $pdo->query(
    "SELECT detay AS kullanici_adi, ip_adresi, created_at FROM aktivite_loglari
     WHERE aksiyon='giris_basarisiz' ORDER BY id DESC LIMIT 15"
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-hdd-stack text-primary"></i> Sistem Durumu</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Ayarlar</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">PHP Versiyonu</div>
            <div class="fw-bold fs-5"><?= escH($phpVersiyon) ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">MySQL Versiyonu</div>
            <div class="fw-bold fs-5"><?= escH($mysqlVersiyon) ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100 <?= ($diskUyariGb > 0 && $diskBos !== false && ($diskBos/1073741824) < $diskUyariGb) ? 'border-danger' : '' ?>"><div class="card-body">
            <div class="text-muted small">Disk Boş Alan</div>
            <div class="fw-bold fs-5"><?= $diskBos !== false ? number_format($diskBos/1073741824,1).' GB' : 'Bilinmiyor' ?></div>
            <div class="small text-muted"><?= $diskYuzde !== null ? "%$diskYuzde dolu" : '' ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Veritabanı Boyutu</div>
            <div class="fw-bold fs-5"><?= number_format($dbToplamMb,1) ?> MB</div>
            <div class="small text-muted"><?= escH($dbAdi) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm h-100 <?= $bugunYedekVar ? 'border-success' : 'border-warning' ?>">
            <div class="card-body">
                <div class="text-muted small"><i class="bi bi-archive"></i> Son Yedek</div>
                <div class="fw-bold"><?= $sonYedekDosya ? escH($sonYedekDosya) : 'Yedek bulunamadı' ?></div>
                <div class="small <?= $bugunYedekVar ? 'text-success' : 'text-warning' ?>">
                    <?= $sonYedekTarih ? date('d.m.Y H:i', $sonYedekTarih) : '-' ?> <?= $bugunYedekVar ? '(bugün alındı)' : '(bugün alınmadı)' ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small"><i class="bi bi-clipboard-check"></i> Son Stok Sayımı</div>
                <div class="fw-bold"><?= $sonSayimTarihi ? tarih($sonSayimTarihi) : 'Hiç yapılmamış' ?></div>
                <?php if ($sonSayimTarihi): $gecenGun = (int)((time() - strtotime($sonSayimTarihi)) / 86400); ?>
                <div class="small text-muted"><?= $gecenGun ?> gün önce</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100 <?= $sonKapanisTarihi === $bugun ? 'border-success' : '' ?>">
            <div class="card-body">
                <div class="text-muted small"><i class="bi bi-cash-stack"></i> Son Kasa Kapanışı</div>
                <div class="fw-bold"><?= $sonKapanisTarihi ? tarih($sonKapanisTarihi) : 'Hiç yapılmamış' ?></div>
                <div class="small <?= $sonKapanisTarihi === $bugun ? 'text-success' : 'text-muted' ?>"><?= $sonKapanisTarihi === $bugun ? 'Bugün kapatıldı' : 'Bugün henüz kapatılmadı' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-table text-primary"></i> En Büyük Tablolar</div>
            <div class="card-body p-0" style="max-height:350px;overflow-y:auto">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Tablo</th><th>Satır</th><th>Boyut</th></tr></thead>
                    <tbody>
                    <?php foreach ($tabloBoyutlari as $t): ?>
                    <tr><td><code><?= escH($t['tablo']) ?></code></td><td><?= number_format((int)$t['satir']) ?></td><td><?= $t['mb'] ?> MB</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-exclamation text-primary"></i> Güvenlik Olayları (son 7 gün)</div>
            <div class="card-body">
                <div class="d-flex gap-3 mb-2">
                    <span class="badge bg-success">Başarılı Giriş: <?= (int)($guvenlikOzet['giris'] ?? 0) ?></span>
                    <span class="badge bg-danger">Başarısız Giriş: <?= (int)($guvenlikOzet['giris_basarisiz'] ?? 0) ?></span>
                </div>
                <?php if ($sonBasarisizGirisler): ?>
                <div class="small fw-semibold mb-1">Son Başarısız Denemeler</div>
                <div style="max-height:200px;overflow-y:auto">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Kullanıcı Adı</th><th>IP</th><th>Zaman</th></tr></thead>
                    <tbody>
                    <?php foreach ($sonBasarisizGirisler as $g): ?>
                    <tr><td><?= escH($g['kullanici_adi']) ?></td><td><?= escH($g['ip_adresi']) ?></td><td class="small"><?= tarihSaat($g['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="text-muted small">Son 7 günde kayıtlı başarısız giriş denemesi yok.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-people text-primary"></i> Kullanıcı Aktivitesi</div>
            <div class="card-body p-0" style="max-height:560px;overflow-y:auto">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Ad Soyad</th><th>Rol</th><th>Son Giriş</th><th>Durum</th></tr></thead>
                    <tbody>
                    <?php foreach ($kullaniciAktivite as $k): ?>
                    <tr>
                        <td><?= escH($k['ad_soyad']) ?> <span class="text-muted small">(<?= escH($k['kullanici_adi']) ?>)</span></td>
                        <td><span class="badge bg-secondary"><?= escH($k['rol']) ?></span></td>
                        <td class="small"><?= $k['son_giris'] ? tarihSaat($k['son_giris']) : '<span class="text-muted">Hiç giriş yapmadı</span>' ?></td>
                        <td><?= $k['aktif'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                <i class="bi bi-info-circle"></i> Bu liste "son giriş zamanı"nı gösterir — PHP'nin varsayılan oturum yönetimi gerçek zamanlı "şu an aktif olanlar" listesini güvenilir şekilde sunmadığı için canlı oturum takibi yapılmamaktadır.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
