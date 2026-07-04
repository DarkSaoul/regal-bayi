<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Yedekleme';
$pdo = db();

$yedekDir = __DIR__ . '/../../backups/';
if (!is_dir($yedekDir)) mkdir($yedekDir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    // Yedek al ve indir
    if ($aksiyon === 'indir') {
        $dosyaAdi = 'regal_bayi_' . date('Y-m-d_H-i-s') . '.sql';
        $hedef    = $yedekDir . $dosyaAdi;

        // Şifre process list'te görünmesin diye ortak güvenli helper kullanılır
        $output = [];
        if (mysqldumpCalistir($hedef, $output)) {
            unset($_SESSION['yedek_gerekli']);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
            header('Content-Length: ' . filesize($hedef));
            readfile($hedef);
            exit;
        } else {
            flash('hata', 'Yedek alınamadı: ' . implode(' ', $output));
            header('Location: index.php'); exit;
        }
    }

    // Yedeği sil
    if ($aksiyon === 'sil') {
        $dosya = basename($_POST['dosya'] ?? '');
        $yol   = $yedekDir . $dosya;
        if ($dosya && file_exists($yol) && str_ends_with($dosya, '.sql')) {
            unlink($yol);
            flash('basari', 'Yedek silindi.');
        }
        header('Location: index.php'); exit;
    }
}

// Yedekleri listele
$yedekler = [];
foreach (glob($yedekDir . '*.sql') as $dosya) {
    $yedekler[] = [
        'ad'    => basename($dosya),
        'boyut' => filesize($dosya),
        'tarih' => filemtime($dosya),
    ];
}
usort($yedekler, fn($a, $b) => $b['tarih'] - $a['tarih']);

// DB istatistikleri
$tablolar    = $pdo->query("SHOW TABLE STATUS")->fetchAll();
$toplamSatir = array_sum(array_column($tablolar, 'Rows'));
$dbBoyut     = array_sum(array_map(fn($t) => ($t['Data_length'] ?? 0) + ($t['Index_length'] ?? 0), $tablolar));

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-cloud-arrow-down text-primary"></i> Yedekleme</h4>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="aksiyon" value="indir">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-download"></i> Şimdi Yedek Al
        </button>
    </form>
</div>

<!-- DB Durum Kartları -->
<div class="row g-3 mb-4">
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

<!-- Tablo Listesi + Yedek Listesi -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-table text-primary"></i> Tablo Durumu
            </div>
            <div class="card-body p-0">
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
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-archive text-primary"></i> Alınan Yedekler (<?= count($yedekler) ?>)
            </div>
            <div class="card-body p-0">
            <?php if (empty($yedekler)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                    Henüz yedek alınmamış
                </div>
            <?php else: ?>
            <table class="table table-hover table-sm mb-0">
                <thead><tr><th>Dosya</th><th>Tarih</th><th>Boyut</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($yedekler as $y): ?>
                <tr>
                    <td><code class="small"><?= escH($y['ad']) ?></code></td>
                    <td class="small"><?= date('d.m.Y H:i', $y['tarih']) ?></td>
                    <td class="small"><?= round($y['boyut'] / 1024, 1) ?> KB</td>
                    <td class="text-end d-flex gap-1 justify-content-end">
                        <a href="indir.php?dosya=<?= urlencode($y['ad']) ?>"
                           class="btn btn-sm btn-outline-success py-0 px-2">
                            <i class="bi bi-download"></i>
                        </a>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Bu yedeği silmek istediğinize emin misiniz?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="aksiyon" value="sil">
                            <input type="hidden" name="dosya" value="<?= escH($y['ad']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
