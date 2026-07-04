<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Ürün İçe Aktarma';
$pdo = db();

// Şablon indirme
if (isset($_GET['sablon'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="urun_sablonu.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['kod','barkod','ad','kategori','marka','model','renk','birim','alis_fiyati','satis_fiyati','kdv_orani','min_stok'], ';');
    fputcsv($out, ['', '8690000000001', 'Örnek Buzdolabı NF6021', 'Buzdolabı', 'Regal', 'NF6021', 'Beyaz', 'Adet', '15000,00', '19500,00', '20', '2'], ';');
    fclose($out); exit;
}

// Türkçe formatlı sayıyı floata çevir: "15.000,50" → 15000.50
function csvSayi(string $v): float {
    $v = trim($v);
    if ($v === '') return 0.0;
    if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
    return (float)$v;
}

$sonuc = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $guncelleModu = !empty($_POST['guncelle']);
    if (($_FILES['dosya']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('hata', 'CSV dosyası seçilmedi veya yüklenemedi.');
        header('Location: ice_aktar.php'); exit;
    }
    $fh = fopen($_FILES['dosya']['tmp_name'], 'r');
    $ilk = fgets($fh);
    if ($ilk === false) { flash('hata', 'Dosya boş.'); header('Location: ice_aktar.php'); exit; }
    // BOM temizle, ayracı tespit et (; veya ,)
    $ilk = preg_replace('/^\xEF\xBB\xBF/', '', $ilk);
    $ayrac = substr_count($ilk, ';') >= substr_count($ilk, ',') ? ';' : ',';
    $basliklar = array_map(fn($h) => mb_strtolower(trim($h), 'UTF-8'), str_getcsv($ilk, $ayrac));
    $gerekli = ['ad'];
    foreach ($gerekli as $g) {
        if (!in_array($g, $basliklar, true)) {
            flash('hata', "CSV başlık satırında \"$g\" sütunu bulunamadı. Şablonu kullanın.");
            header('Location: ice_aktar.php'); exit;
        }
    }

    $kategoriMap = [];
    foreach ($pdo->query("SELECT id, ad FROM kategoriler")->fetchAll() as $k) {
        $kategoriMap[mb_strtolower(trim($k['ad']), 'UTF-8')] = (int)$k['id'];
    }

    $eklendi = 0; $guncellendi = 0; $hatalar = []; $satirNo = 1;
    $pdo->beginTransaction();
    try {
        while (($satir = fgetcsv($fh, 0, $ayrac)) !== false) {
            $satirNo++;
            if (count($satir) === 1 && trim((string)$satir[0]) === '') continue; // boş satır
            $v = [];
            foreach ($basliklar as $i => $b) $v[$b] = trim((string)($satir[$i] ?? ''));

            if ($v['ad'] === '') { $hatalar[] = "Satır $satirNo: ürün adı boş."; continue; }
            $kod    = strtoupper($v['kod'] ?? '');
            $barkod = $v['barkod'] ?? '';
            $kategori_id = null;
            if (!empty($v['kategori'])) {
                $kategori_id = $kategoriMap[mb_strtolower($v['kategori'], 'UTF-8')] ?? null;
                if ($kategori_id === null) $hatalar[] = "Satır $satirNo: \"{$v['kategori']}\" kategorisi bulunamadı, kategorisiz kaydedildi.";
            }
            $alis  = csvSayi($v['alis_fiyati'] ?? '0');
            $satis = csvSayi($v['satis_fiyati'] ?? '0');
            $kdv   = min(100, max(0, csvSayi($v['kdv_orani'] ?? '20')));
            $minSt = max(0, (int)($v['min_stok'] ?? 1));
            $birim = $v['birim'] ?: 'Adet';

            // Mevcut ürün eşleşmesi: önce kod, sonra barkod
            $mevcut = null;
            if ($kod) {
                $s = $pdo->prepare("SELECT * FROM urunler WHERE kod=?"); $s->execute([$kod]); $mevcut = $s->fetch();
            }
            if (!$mevcut && $barkod) {
                $s = $pdo->prepare("SELECT * FROM urunler WHERE barkod=?"); $s->execute([$barkod]); $mevcut = $s->fetch();
            }

            if ($mevcut) {
                if (!$guncelleModu) { $hatalar[] = "Satır $satirNo: \"{$mevcut['kod']}\" zaten kayıtlı, atlandı (güncelleme modu kapalı)."; continue; }
                $pdo->prepare("UPDATE urunler SET barkod=COALESCE(NULLIF(?,''),barkod), ad=?, kategori_id=COALESCE(?,kategori_id),
                        marka=?, model=?, renk=?, birim=?, alis_fiyati=?, satis_fiyati=?, kdv_orani=?, min_stok=? WHERE id=?")
                    ->execute([$barkod, $v['ad'], $kategori_id, $v['marka'] ?: 'Regal', $v['model'] ?? '', $v['renk'] ?? '',
                        $birim, $alis, $satis, $kdv, $minSt, $mevcut['id']]);
                fiyatGecmisiKaydet((int)$mevcut['id'], $mevcut['alis_fiyati'], $alis, $mevcut['satis_fiyati'], $satis, 'ice_aktar');
                $guncellendi++;
            } else {
                if ($barkod) {
                    $bk = $pdo->prepare("SELECT kod FROM urunler WHERE barkod=?"); $bk->execute([$barkod]);
                    if ($bk->fetchColumn()) { $hatalar[] = "Satır $satirNo: barkod başka üründe kayıtlı, atlandı."; continue; }
                }
                if (!$kod) {
                    do {
                        $son = $pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM urunler")->fetchColumn();
                        $kod = 'RGL' . str_pad($son, 5, '0', STR_PAD_LEFT);
                        $var = $pdo->prepare("SELECT COUNT(*) FROM urunler WHERE kod=?"); $var->execute([$kod]);
                    } while ((int)$var->fetchColumn() > 0);
                }
                $pdo->prepare("INSERT INTO urunler (kod,barkod,ad,kategori_id,marka,model,renk,birim,alis_fiyati,satis_fiyati,kdv_orani,min_stok)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$kod, $barkod ?: null, $v['ad'], $kategori_id, $v['marka'] ?: 'Regal', $v['model'] ?? '',
                        $v['renk'] ?? '', $birim, $alis, $satis, $kdv, $minSt]);
                fiyatGecmisiKaydet((int)$pdo->lastInsertId(), 0, $alis, 0, $satis, 'ice_aktar');
                $eklendi++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('hata', 'İçe aktarma başarısız, hiçbir kayıt işlenmedi: ' . $e->getMessage());
        header('Location: ice_aktar.php'); exit;
    }
    fclose($fh);
    logla('urun_ice_aktar', 'urunler', 0, "CSV: $eklendi eklendi, $guncellendi güncellendi, " . count($hatalar) . " uyarı");
    $sonuc = ['eklendi' => $eklendi, 'guncellendi' => $guncellendi, 'hatalar' => $hatalar];
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-upload text-primary"></i> Ürün İçe Aktarma (CSV)</h4>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Ürünler</a>
</div>

<?php if ($sonuc): ?>
<div class="alert alert-<?= $sonuc['eklendi'] + $sonuc['guncellendi'] ? 'success' : 'warning' ?>">
    <i class="bi bi-check-circle"></i>
    <strong><?= $sonuc['eklendi'] ?></strong> ürün eklendi, <strong><?= $sonuc['guncellendi'] ?></strong> ürün güncellendi.
</div>
<?php if ($sonuc['hatalar']): ?>
<div class="alert alert-warning">
    <strong><?= count($sonuc['hatalar']) ?> uyarı:</strong>
    <ul class="mb-0 small">
        <?php foreach (array_slice($sonuc['hatalar'], 0, 30) as $h): ?><li><?= escH($h) ?></li><?php endforeach; ?>
        <?php if (count($sonuc['hatalar']) > 30): ?><li>… ve <?= count($sonuc['hatalar']) - 30 ?> uyarı daha</li><?php endif; ?>
    </ul>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-arrow-up text-primary"></i> Dosya Yükle</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">CSV Dosyası</label>
                        <input type="file" name="dosya" class="form-control" accept=".csv,text/csv" required>
                        <div class="form-text">Ayraç olarak noktalı virgül (;) veya virgül (,) kullanılabilir. İlk satır başlık olmalıdır.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="guncelle" id="guncelle" value="1">
                        <label class="form-check-label" for="guncelle">
                            Mevcut ürünleri güncelle <small class="text-muted">(kod veya barkod eşleşirse fiyat/bilgi güncellenir; kapalıysa atlanır)</small>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('CSV içe aktarılacak. Devam edilsin mi?')">
                        <i class="bi bi-upload"></i> İçe Aktar
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary"></i> Nasıl Çalışır?</div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item"><i class="bi bi-1-circle text-primary me-2"></i>
                        <a href="?sablon=1">Şablon CSV'yi indirin</a> ve Excel/LibreOffice ile doldurun.</li>
                    <li class="list-group-item"><i class="bi bi-2-circle text-primary me-2"></i>
                        Zorunlu sütun: <code>ad</code>. Diğerleri boş bırakılabilir; <code>kod</code> boşsa otomatik üretilir.</li>
                    <li class="list-group-item"><i class="bi bi-3-circle text-primary me-2"></i>
                        <code>kategori</code> sütununa kategori ADI yazılır (örn: Buzdolabı); eşleşmezse kategorisiz kalır.</li>
                    <li class="list-group-item"><i class="bi bi-4-circle text-primary me-2"></i>
                        Fiyatlar Türkçe formatta olabilir: <code>15.000,50</code></li>
                    <li class="list-group-item"><i class="bi bi-exclamation-triangle text-warning me-2"></i>
                        Stok adedi CSV ile İÇE AKTARILMAZ — stok yalnızca <a href="<?= BASE_URL ?>/modules/stok/giris.php">Stok Giriş</a> ile eklenir (maliyet ve hareket kaydı için).</li>
                    <li class="list-group-item"><i class="bi bi-shield-check text-success me-2"></i>
                        Hata olursa hiçbir kayıt işlenmez (tümü ya da hiçbiri).</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
