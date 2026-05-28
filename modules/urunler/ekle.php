<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Yeni Ürün';
$pdo = db();
$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id, ad")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    // Otomatik kod oluştur
    $kod = strtoupper(trim($d['kod'] ?? ''));
    if (!$kod) {
        // Benzersizlik için rastgele suffix ekle, UNIQUE constraint zaten korur
        do {
            $son = $pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM urunler")->fetchColumn();
            $kod = 'RGL' . str_pad($son, 5, '0', STR_PAD_LEFT);
            $var = $pdo->prepare("SELECT COUNT(*) FROM urunler WHERE kod=?");
            $var->execute([$kod]);
        } while ((int)$var->fetchColumn() > 0);
    }
    try {
        $pdo->prepare("INSERT INTO urunler (kod,barkod,ad,kategori_id,marka,model,renk,aciklama,alis_fiyati,satis_fiyati,kdv_orani,min_stok,seri_no_takip)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $kod, $d['barkod']??'', $d['ad'], $d['kategori_id']?:null, $d['marka']?:'Regal',
                $d['model']??'', $d['renk']??'', $d['aciklama']??'',
                $d['alis_fiyati']??0, $d['satis_fiyati']??0,
                $d['kdv_orani']??20, $d['min_stok']??1, isset($d['seri_no_takip'])?1:0
            ]);
        flash('basari', "\"$kod - {$d['ad']}\" ürünü eklendi.");
        header('Location: index.php'); exit;
    } catch (Exception $e) {
        $hata = $e->getMessage();
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-plus-circle text-primary"></i> Yeni Ürün Ekle</h4>
</div>

<?php if (!empty($hata)): ?>
<div class="alert alert-danger"><?= escH($hata) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Ürün Kodu <small class="text-muted">(boş bırakılırsa otomatik)</small></label>
                <input type="text" name="kod" class="form-control" value="<?= escH($_POST['kod']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Barkod</label>
                <input type="text" name="barkod" class="form-control" value="<?= escH($_POST['barkod']??'') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Ürün Adı <span class="text-danger">*</span></label>
                <input type="text" name="ad" class="form-control" required value="<?= escH($_POST['ad']??'') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <select name="kategori_id" class="form-select">
                    <option value="">Seçin</option>
                    <?php foreach ($kategoriler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= ($_POST['kategori_id']??'')==$k['id']?'selected':'' ?>>
                        <?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Marka</label>
                <input type="text" name="marka" class="form-control" value="<?= escH($_POST['marka']??'Regal') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Model</label>
                <input type="text" name="model" class="form-control" value="<?= escH($_POST['model']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Renk</label>
                <input type="text" name="renk" class="form-control" value="<?= escH($_POST['renk']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Alış Fiyatı (₺)</label>
                <input type="number" name="alis_fiyati" class="form-control" step="0.01" min="0" value="<?= $_POST['alis_fiyati']??'0' ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Satış Fiyatı (₺) <span class="text-danger">*</span></label>
                <input type="number" name="satis_fiyati" class="form-control" step="0.01" min="0" required value="<?= $_POST['satis_fiyati']??'0' ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">KDV Oranı (%)</label>
                <select name="kdv_orani" class="form-select">
                    <option value="0" <?= ($_POST['kdv_orani']??20)==0?'selected':'' ?>>%0</option>
                    <option value="1" <?= ($_POST['kdv_orani']??20)==1?'selected':'' ?>>%1</option>
                    <option value="10" <?= ($_POST['kdv_orani']??20)==10?'selected':'' ?>>%10</option>
                    <option value="20" <?= ($_POST['kdv_orani']??20)==20?'selected':'' ?>>%20</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Min. Stok Uyarısı</label>
                <input type="number" name="min_stok" class="form-control" min="0" value="<?= $_POST['min_stok']??'1' ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Açıklama</label>
                <textarea name="aciklama" class="form-control" rows="2"><?= escH($_POST['aciklama']??'') ?></textarea>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="seri_no_takip" id="seriNo" <?= !empty($_POST['seri_no_takip'])?'checked':'' ?>>
                    <label class="form-check-label fw-semibold" for="seriNo">
                        Seri No Takibi <small class="text-muted">(büyük beyaz eşya)</small>
                    </label>
                </div>
            </div>
        </div>
        <hr>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
