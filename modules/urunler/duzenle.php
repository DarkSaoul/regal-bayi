<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$urun = $pdo->prepare("SELECT * FROM urunler WHERE id=?");
$urun->execute([$id]);
$urun = $urun->fetch();
if (!$urun) { flash('hata','Ürün bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = 'Ürün Düzenle: ' . $urun['ad'];
$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id, ad")->fetchAll();
$birimler = ['Adet','Koli','Paket','Metre','Kg','Litre','Çift','Takım'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $barkod = trim($d['barkod'] ?? '');
    // Aynı barkod başka bir üründe olamaz
    if ($barkod) {
        $bk = $pdo->prepare("SELECT kod FROM urunler WHERE barkod=? AND id!=? LIMIT 1");
        $bk->execute([$barkod, $id]);
        if ($mevcutKod = $bk->fetchColumn()) {
            $hata = "Bu barkod zaten \"$mevcutKod\" kodlu üründe kayıtlı.";
        }
    }
    if (empty($hata)) {
        try {
            $resim = $urun['resim'];
            if (!empty($d['resim_sil'])) {
                $dosya = __DIR__ . '/../../uploads/urunler/' . basename((string)$resim);
                if ($resim && is_file($dosya)) @unlink($dosya);
                $resim = null;
            }
            $yeniResim = urunResmiYukle($_FILES['resim'] ?? [], $resim);
            if ($yeniResim) $resim = $yeniResim;

            $birim = in_array($d['birim'] ?? '', $birimler, true) ? $d['birim'] : 'Adet';
            $alis  = max(0, (float)($d['alis_fiyati'] ?? 0));
            $satis = max(0, (float)($d['satis_fiyati'] ?? 0));
            $pdo->prepare("UPDATE urunler SET barkod=?,ad=?,kategori_id=?,marka=?,model=?,renk=?,birim=?,aciklama=?,resim=?,
                alis_fiyati=?,satis_fiyati=?,kdv_orani=?,min_stok=?,seri_no_takip=? WHERE id=?")
                ->execute([
                    $barkod, $d['ad'], $d['kategori_id']?:null, $d['marka']?:'Regal',
                    $d['model']??'', $d['renk']??'', $birim, $d['aciklama']??'', $resim,
                    $alis, $satis,
                    min(100, max(0, (float)($d['kdv_orani']??20))), max(0, (int)($d['min_stok']??1)),
                    isset($d['seri_no_takip'])?1:0, $id
                ]);
            fiyatGecmisiKaydet($id, $urun['alis_fiyati'], $alis, $urun['satis_fiyati'], $satis, 'duzenleme');
            flash('basari', "Ürün güncellendi.");
            header('Location: detay.php?id=' . $id); exit;
        } catch (Exception $e) {
            error_log($e->getMessage());
            $hata = 'İşlem sırasında bir hata oluştu: ' . $e->getMessage();
        }
    }
}
$d = $_SERVER['REQUEST_METHOD']==='POST' ? $_POST : $urun;
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-pencil text-primary"></i> Ürün Düzenle</h4>
    <a href="detay.php?id=<?= $id ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i> Detay</a>
</div>
<?php if (!empty($hata)): ?>
<div class="alert alert-danger"><?= escH($hata) ?></div>
<?php endif; ?>
<div class="card shadow-sm">
    <div class="card-body">
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Ürün Kodu</label>
                <input type="text" class="form-control" value="<?= escH($urun['kod']) ?>" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Barkod</label>
                <div class="input-group">
                    <input type="text" name="barkod" id="barkodInput" class="form-control" value="<?= escH($d['barkod']??'') ?>">
                    <button type="button" class="btn btn-outline-secondary" title="Kamerayla tara"
                            onclick="BarcodeScanner.start(v => document.getElementById('barkodInput').value = v)">
                        <i class="bi bi-upc-scan"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Ürün Adı <span class="text-danger">*</span></label>
                <input type="text" name="ad" class="form-control" required value="<?= escH($d['ad']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <select name="kategori_id" class="form-select">
                    <option value="">Seçin</option>
                    <?php foreach ($kategoriler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= ($d['kategori_id']??'')==$k['id']?'selected':'' ?>>
                        <?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Marka</label>
                <input type="text" name="marka" class="form-control" value="<?= escH($d['marka']??'Regal') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Model</label>
                <input type="text" name="model" class="form-control" value="<?= escH($d['model']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Renk</label>
                <input type="text" name="renk" class="form-control" value="<?= escH($d['renk']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Birim</label>
                <select name="birim" class="form-select">
                    <?php foreach ($birimler as $b): ?>
                    <option value="<?= $b ?>" <?= ($d['birim']??'Adet')===$b?'selected':'' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Min. Stok</label>
                <input type="number" name="min_stok" class="form-control" min="0" value="<?= (int)($d['min_stok']??1) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">KDV Oranı (%)</label>
                <select name="kdv_orani" class="form-select">
                    <?php foreach ([0,1,10,20] as $kdv): ?>
                    <option value="<?= $kdv ?>" <?= (float)($d['kdv_orani']??20)==$kdv?'selected':'' ?>>%<?= $kdv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Alış Fiyatı (₺)</label>
                <input type="number" name="alis_fiyati" id="alisInput" class="form-control" step="0.01" min="0" value="<?= escH($d['alis_fiyati']??0) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Hedef Marj (%) <small class="text-muted">(satışı hesaplar)</small></label>
                <div class="input-group">
                    <input type="number" id="marjInput" class="form-control" step="0.1" placeholder="Örn: 25">
                    <button type="button" class="btn btn-outline-secondary" onclick="marjdanHesapla()" title="Alış + marj → satış fiyatı"><i class="bi bi-calculator"></i></button>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Satış Fiyatı (₺) <span class="text-danger">*</span></label>
                <input type="number" name="satis_fiyati" id="satisInput" class="form-control" step="0.01" min="0" required value="<?= escH($d['satis_fiyati']??0) ?>">
                <div class="form-text" id="marjBilgi"></div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Ürün Görseli <small class="text-muted">(JPG/PNG/WEBP, ≤2MB)</small></label>
                <input type="file" name="resim" class="form-control" accept="image/jpeg,image/png,image/webp">
                <?php if ($urun['resim']): ?>
                <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" name="resim_sil" id="resimSil" value="1">
                    <label class="form-check-label small" for="resimSil">Mevcut görseli kaldır</label>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($urun['resim']): ?>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Mevcut Görsel</label><br>
                <img src="<?= BASE_URL ?>/uploads/urunler/<?= escH($urun['resim']) ?>" alt="" class="rounded border" style="max-height:70px">
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Açıklama</label>
                <textarea name="aciklama" class="form-control" rows="2"><?= escH($d['aciklama']??'') ?></textarea>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="seri_no_takip" id="seriNo" <?= ($d['seri_no_takip']??0)?'checked':'' ?>>
                    <label class="form-check-label" for="seriNo">Seri No Takibi</label>
                </div>
            </div>
        </div>
        <div class="alert alert-warning mt-3 mb-0 py-2 d-none" id="zararUyari">
            <i class="bi bi-exclamation-triangle-fill"></i> Satış fiyatı alış fiyatının altında — bu ürün <strong>zararına</strong> satılacak.
        </div>
        <hr>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Güncelle</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<script>
const alisEl = document.getElementById('alisInput');
const satisEl = document.getElementById('satisInput');

function marjdanHesapla() {
    const alis = parseFloat(alisEl.value) || 0;
    const marj = parseFloat(document.getElementById('marjInput').value);
    if (alis <= 0 || isNaN(marj)) { alert('Önce alış fiyatı ve marj girin.'); return; }
    satisEl.value = (alis * (1 + marj / 100)).toFixed(2);
    fiyatKontrol();
}
function fiyatKontrol() {
    const alis = parseFloat(alisEl.value) || 0;
    const satis = parseFloat(satisEl.value) || 0;
    document.getElementById('zararUyari').classList.toggle('d-none', !(alis > 0 && satis > 0 && satis < alis));
    const bilgi = document.getElementById('marjBilgi');
    bilgi.textContent = (alis > 0 && satis > 0)
        ? 'Marj: %' + (((satis - alis) / alis) * 100).toFixed(1) : '';
}
alisEl.addEventListener('input', fiyatKontrol);
satisEl.addEventListener('input', fiyatKontrol);
fiyatKontrol();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
