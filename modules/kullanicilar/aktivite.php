<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Aktivite Logu';
$pdo = db();

$sayfa     = max(1,(int)($_GET['s']??1));
$limit     = 50;
$offset    = ($sayfa-1)*$limit;
$filtre_k  = (int)($_GET['kullanici']??0);
$filtre_m  = trim($_GET['modul']??'');
$filtre_bas = gecerliTarih($_GET['bas'] ?? '', date('Y-m-01'));
$filtre_bit = gecerliTarih($_GET['bit'] ?? '', date('Y-m-d'));

$where  = "WHERE al.created_at BETWEEN ? AND ?";
$params = [$filtre_bas . ' 00:00:00', $filtre_bit . ' 23:59:59'];
if ($filtre_k)  { $where .= " AND al.kullanici_id=?"; $params[] = $filtre_k; }
if ($filtre_m)  { $where .= " AND al.modul=?";        $params[] = $filtre_m; }

$toplam = $pdo->prepare("SELECT COUNT(*) FROM aktivite_loglari al $where");
$toplam->execute($params); $toplam = (int)$toplam->fetchColumn();

$stmt = $pdo->prepare("
    SELECT al.*, k.ad_soyad AS kullanici_adi, k.rol
    FROM aktivite_loglari al
    LEFT JOIN kullanicilar k ON al.kullanici_id = k.id
    $where
    ORDER BY al.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$loglar = $stmt->fetchAll();

$kullanicilar = $pdo->query("SELECT id, ad_soyad FROM kullanicilar ORDER BY ad_soyad")->fetchAll();
$moduller = $pdo->query("SELECT DISTINCT modul FROM aktivite_loglari WHERE modul IS NOT NULL ORDER BY modul")->fetchAll(PDO::FETCH_COLUMN);
$sayfaSayisi = ceil($toplam/$limit);

$aksiyon_renk = [
    'giris'         => 'success',
    'cikis'         => 'secondary',
    'satis_olustur' => 'primary',
    'satis_iptal'   => 'danger',
    'stok_giris'    => 'success',
    'tahsilat'      => 'success',
    'musteri_ekle'  => 'info',
    'musteri_duzenle' => 'warning',
    'urun_ekle'     => 'info',
    'urun_duzenle'  => 'warning',
    'urun_sil'      => 'danger',
    'fiyat_guncelle'=> 'warning',
];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-clock-history text-primary"></i> Aktivite Logu</h4>
    <span class="badge bg-secondary"><?= number_format($toplam) ?> kayıt</span>
</div>

<!-- Filtre -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <select name="kullanici" class="form-select form-select-sm">
                    <option value="">Tüm Kullanıcılar</option>
                    <?php foreach ($kullanicilar as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $filtre_k==$k['id']?'selected':'' ?>><?= escH($k['ad_soyad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="modul" class="form-select form-select-sm">
                    <option value="">Tüm Modüller</option>
                    <?php foreach ($moduller as $m): ?>
                    <option value="<?= escH($m) ?>" <?= $filtre_m===$m?'selected':'' ?>><?= escH($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="bas" class="form-control form-control-sm" value="<?= $filtre_bas ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="bit" class="form-control form-control-sm" value="<?= $filtre_bit ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                <a href="aktivite.php" class="btn btn-sm btn-outline-secondary">Temizle</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
        <thead>
            <tr><th>Tarih/Saat</th><th>Kullanıcı</th><th>İşlem</th><th>Modül</th><th>Detay</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php if (empty($loglar)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Kayıt bulunamadı</td></tr>
        <?php else: ?>
        <?php foreach ($loglar as $log): ?>
        <tr>
            <td class="text-nowrap small text-muted"><?= tarihSaat($log['created_at']) ?></td>
            <td>
                <span class="fw-semibold"><?= escH($log['kullanici_adi'] ?? '—') ?></span>
                <?php if ($log['rol']): ?>
                <br><span class="badge bg-light text-secondary" style="font-size:.65rem"><?= escH($log['rol']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php $renk = $aksiyon_renk[$log['aksiyon']] ?? 'secondary'; ?>
                <span class="badge bg-<?= $renk ?>"><?= escH(str_replace('_',' ',$log['aksiyon'])) ?></span>
            </td>
            <td><span class="text-muted small"><?= escH($log['modul'] ?? '-') ?></span></td>
            <td class="small"><?= escH($log['detay'] ?? '-') ?></td>
            <td class="small text-muted"><?= escH($log['ip_adresi'] ?? '-') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
    <?php if ($sayfaSayisi>1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end">
        <?php for ($i=1;$i<=$sayfaSayisi;$i++): ?>
        <li class="page-item <?= $i==$sayfa?'active':'' ?>">
            <a class="page-link" href="?kullanici=<?= $filtre_k ?>&modul=<?= urlencode($filtre_m) ?>&bas=<?= $filtre_bas ?>&bit=<?= $filtre_bit ?>&s=<?= $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
