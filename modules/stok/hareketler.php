<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Stok Hareketleri';
$pdo = db();

$urun_id = (int)($_GET['urun_id'] ?? 0);

$sql = "SELECT sh.*, u.ad AS urun_adi, u.kod, k.ad_soyad AS kullanici, t.ad AS tedarikci
    FROM stok_hareketleri sh
    JOIN urunler u ON sh.urun_id = u.id
    LEFT JOIN kullanicilar k ON sh.kullanici_id = k.id
    LEFT JOIN tedarikciler t ON sh.tedarikci_id = t.id";
$params = [];
if ($urun_id) {
    $sql .= " WHERE sh.urun_id=?";
    $params[] = $urun_id;
}
$sql .= " ORDER BY sh.created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hareketler = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between">
    <h4><i class="bi bi-clock-history text-primary"></i> Stok Hareketleri</h4>
    <a href="index.php" class="btn btn-outline-secondary">Stoka Dön</a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Tarih</th><th>Ürün</th><th>Tip</th>
                <th>Miktar</th><th>Önceki</th><th>Sonraki</th>
                <th>Belge No</th><th>Tedarikçi</th><th>Kullanıcı</th><th>Açıklama</th>
            </tr></thead>
            <tbody>
            <?php foreach ($hareketler as $h): ?>
            <?php
            $tipler = ['giris'=>['Giriş','success'], 'cikis'=>['Satış Çıkış','danger'], 'iade_giris'=>['İade Giriş','info'], 'fire'=>['Fire','warning']];
            [$tipAdi, $renk] = $tipler[$h['hareket_tipi']] ?? [$h['hareket_tipi'],'secondary'];
            ?>
            <tr>
                <td><?= tarihSaat($h['created_at']) ?></td>
                <td><strong><?= escH($h['urun_adi']) ?></strong><br><small class="text-muted"><?= escH($h['kod']) ?></small></td>
                <td><span class="badge bg-<?= $renk ?>"><?= $tipAdi ?></span></td>
                <td class="fw-bold"><?= $h['miktar'] ?></td>
                <td><?= $h['onceki_stok'] ?></td>
                <td><?= $h['sonraki_stok'] ?></td>
                <td><?= escH($h['belge_no'] ?: '-') ?></td>
                <td><?= escH($h['tedarikci'] ?: '-') ?></td>
                <td><?= escH($h['kullanici'] ?: '-') ?></td>
                <td><?= escH($h['aciklama'] ?: '-') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
