<?php
// Örnek/Demo Veri Temizleme — TEK KULLANIMLIKTIR
// Çalıştırıldıktan sonra bir kilit dosyası bırakır ve (mümkünse) kendini siler.
define('BASE_URL', '/regal');
require_once __DIR__ . '/includes/functions.php';
auth(); yetki(['yonetici']);

$kilitYolu = __DIR__ . '/.ornek_veri_silindi.lock';
if (is_file($kilitYolu)) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-warning"><i class="bi bi-lock-fill"></i> Bu sayfa daha önce kullanıldı ve tekrar çalıştırılamaz. '
       . 'Örnek veriler zaten temizlenmiş olmalı.</div><a href="' . BASE_URL . '/modules/dashboard/" class="btn btn-primary btn-sm">Dashboard\'a Dön</a>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ornek_veri.sql'in doldurduğu ve buna bağımlı tüm operasyonel tablolar.
// Kalıcı/referans veriler (kategoriler, kasa_kategoriler, kullanicilar, ayarlar,
// denetim/sistem logları) kasıtlı olarak listede DEĞİLDİR — bunlara dokunulmaz.
const TEMIZLENECEK_TABLOLAR = [
    'satis_iade_kalemleri', 'satis_iadeleri', 'siparis_kalemleri', 'taksit_erteleme_gecmisi',
    'taksit_hatirlatmalari', 'taksit_plani', 'odemeler', 'satis_kalemleri', 'satislar',
    'park_sepetler', 'musteri_notlari', 'musteriler',
    'stok_hareketleri', 'seri_numaralari', 'fiyat_gecmisi', 'sayim_detaylari', 'sayimlar', 'urunler',
    'tedarikci_siparisleri', 'tedarikci_odemeleri', 'tedarikci_borclar', 'tedarikciler',
    'kasa_hareketleri', 'kasa_vardiyalari', 'gider_sablonlari',
];

$hata = ''; $sonuc = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $sifreOK = false;
    if (!empty($_POST['onay_sifre'])) {
        $kul = db()->prepare("SELECT sifre FROM kullanicilar WHERE id=?");
        $kul->execute([$_SESSION['kullanici_id']]);
        $sifreOK = password_verify($_POST['onay_sifre'], (string)$kul->fetchColumn());
    }
    $metinOnay = trim($_POST['onay_metni'] ?? '') === 'SİL';

    if (!$sifreOK) {
        $hata = 'Şifrenizi doğru girmelisiniz.';
    } elseif (!$metinOnay) {
        $hata = 'Onay kutusuna büyük harflerle "SİL" yazmalısınız.';
    } else {
        $pdo = db();
        $silinen = [];
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach (TEMIZLENECEK_TABLOLAR as $tablo) {
                $adet = (int)$pdo->query("SELECT COUNT(*) FROM `$tablo`")->fetchColumn();
                $pdo->exec("TRUNCATE TABLE `$tablo`");
                if ($adet > 0) $silinen[$tablo] = $adet;
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            logla('ornek_veri_silindi', 'sistem', 0, 'Örnek/demo veriler temizlendi: ' . array_sum($silinen) . ' kayıt (' . count($silinen) . ' tablo)');

            // Tek kullanımlık kilit + kendini imha
            file_put_contents($kilitYolu, "Örnek veriler " . date('Y-m-d H:i:s') . " tarihinde temizlendi.\n");
            @unlink(__FILE__); // Windows'ta dosya kullanımdayken silinemeyebilir — kilit dosyası asıl güvenceyi sağlar

            $sonuc = $silinen;
        } catch (Exception $e) {
            $hata = 'Temizlik sırasında hata: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h4><i class="bi bi-trash3-fill text-danger"></i> Örnek/Demo Verileri Sil</h4></div>

<?php if ($sonuc !== null): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> Örnek veriler başarıyla temizlendi.</div>
<div class="card shadow-sm mb-3">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tablo</th><th>Silinen Kayıt</th></tr></thead>
            <tbody>
            <?php foreach ($sonuc as $tablo => $adet): ?>
            <tr><td><code><?= htmlspecialchars($tablo) ?></code></td><td><?= number_format($adet) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($sonuc)): ?><tr><td colspan="2" class="text-muted text-center">Zaten temizlenmiş / hiç örnek veri yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="alert alert-secondary small"><i class="bi bi-info-circle"></i> Bu sayfa artık kullanılamaz (kendini kilitledi/sildi). Sistem gerçek verilerinizle kullanıma hazır.</div>
<a href="<?= BASE_URL ?>/modules/dashboard/" class="btn btn-primary">Dashboard'a Git</a>

<?php else: ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Bu işlem geri alınamaz.</strong>
    Aşağıdaki tablolardaki <strong>tüm kayıtlar kalıcı olarak silinecek</strong>: ürünler, müşteriler, satışlar, tedarikçiler,
    stok hareketleri, kasa hareketleri, sayımlar, taksitler ve bunlara bağlı tüm kayıtlar.
    Kullanıcı hesapları, sistem ayarları ve kategori tanımları <strong>etkilenmez</strong>.
    Bu sayfa <strong>yalnızca bir kez</strong> kullanılabilir.
</div>

<?php if ($hata): ?><div class="alert alert-warning"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
        <div class="fw-semibold small mb-2">Silinecek tablolar:</div>
        <div class="d-flex flex-wrap gap-1 mb-3">
            <?php foreach (TEMIZLENECEK_TABLOLAR as $t): ?>
            <span class="badge bg-secondary"><?= htmlspecialchars($t) ?></span>
            <?php endforeach; ?>
        </div>
        <form method="post" onsubmit="return confirm('Tüm örnek veriler kalıcı olarak silinecek. Bu işlem geri alınamaz ve bu sayfa bir daha kullanılamayacak. Emin misiniz?')">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Şifrenizi Girin</label>
                <input type="password" name="onay_sifre" class="form-control" required autocomplete="current-password">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Onaylamak için büyük harflerle <code>SİL</code> yazın</label>
                <input type="text" name="onay_metni" class="form-control" required placeholder="SİL">
            </div>
            <button type="submit" class="btn btn-danger w-100"><i class="bi bi-trash3-fill"></i> Örnek Verileri Kalıcı Olarak Sil</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
