<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Yeni Ürün';
$pdo = db();
$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id, ad")->fetchAll();
$birimler = ['Adet','Koli','Paket','Metre','Kg','Litre','Çift','Takım'];

// Kopyalama: mevcut üründen ön doldurma (kod ve barkod hariç — ikisi de benzersiz)
$kopya = null;
if (!empty($_GET['kopya'])) {
    $ks = $pdo->prepare("SELECT * FROM urunler WHERE id=?");
    $ks->execute([(int)$_GET['kopya']]);
    if ($kopya = $ks->fetch()) { $kopya['kod'] = ''; $kopya['barkod'] = ''; }
}

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
    $barkod = trim($d['barkod'] ?? '');
    // Aynı barkod iki üründe olamaz — satış ekranı barkodla ilk bulduğunu ekler
    if ($barkod) {
        $bk = $pdo->prepare("SELECT kod FROM urunler WHERE barkod=? LIMIT 1");
        $bk->execute([$barkod]);
        if ($mevcutKod = $bk->fetchColumn()) {
            $hata = "Bu barkod zaten \"$mevcutKod\" kodlu üründe kayıtlı.";
        }
    }
    if (empty($hata)) {
        try {
            $resim = urunResmiYukle($_FILES['resim'] ?? []);
            $birim = in_array($d['birim'] ?? '', $birimler, true) ? $d['birim'] : 'Adet';
            $alis  = max(0, (float)($d['alis_fiyati'] ?? 0));
            $satis = max(0, (float)($d['satis_fiyati'] ?? 0));
            $pdo->prepare("INSERT INTO urunler (kod,barkod,ad,kategori_id,marka,model,renk,birim,aciklama,resim,alis_fiyati,satis_fiyati,kdv_orani,min_stok,seri_no_takip)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $kod, $barkod, $d['ad'], $d['kategori_id']?:null, $d['marka']?:'Regal',
                    $d['model']??'', $d['renk']??'', $birim, $d['aciklama']??'', $resim,
                    $alis, $satis,
                    min(100, max(0, (float)($d['kdv_orani']??20))), max(0, (int)($d['min_stok']??1)),
                    isset($d['seri_no_takip'])?1:0
                ]);
            $yeniId = (int)$pdo->lastInsertId();
            fiyatGecmisiKaydet($yeniId, 0, $alis, 0, $satis, 'olusturma');
            flash('basari', "\"$kod - {$d['ad']}\" ürünü eklendi.");
            header('Location: index.php'); exit;
        } catch (Exception $e) {
            $hata = $e->getMessage();
        }
    }
}
// Öncelik: POST (hata sonrası) > kopya kaynağı > boş
$d = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($kopya ?: []);
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-plus-circle text-primary"></i> Yeni Ürün Ekle</h4>
</div>

<?php if ($kopya): ?>
<div class="alert alert-info py-2"><i class="bi bi-files"></i>
    <strong><?= escH($kopya['ad']) ?></strong> ürününden kopyalanıyor — kod ve barkod alanlarını yeni ürüne göre doldurun.</div>
<?php endif; ?>
<?php if (!empty($hata)): ?>
<div class="alert alert-danger"><?= escH($hata) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Ürün Kodu <small class="text-muted">(boş bırakılırsa otomatik)</small></label>
                <input type="text" name="kod" class="form-control" value="<?= escH($d['kod']??'') ?>">
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
                <input type="text" name="ad" class="form-control" required value="<?= escH($d['ad']??'') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori <small class="text-muted">(KDV/marj varsayılanını getirir)</small></label>
                <select name="kategori_id" id="kategoriSecim" class="form-select" onchange="kategoriVarsayilan()">
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
                <label class="form-label fw-semibold">Min. Stok Uyarısı</label>
                <input type="number" name="min_stok" class="form-control" min="0" value="<?= (int)($d['min_stok']??1) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">KDV Oranı (%)</label>
                <select name="kdv_orani" id="kdvSecim" class="form-select">
                    <?php foreach ([0,1,10,20] as $kdv): ?>
                    <option value="<?= $kdv ?>" <?= (float)($d['kdv_orani']??20)==$kdv?'selected':'' ?>>%<?= $kdv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Alış Fiyatı (₺)</label>
                <input type="number" name="alis_fiyati" id="alisInput" class="form-control" step="0.01" min="0" value="<?= escH($d['alis_fiyati']??'0') ?>">
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
                <input type="number" name="satis_fiyati" id="satisInput" class="form-control" step="0.01" min="0" required value="<?= escH($d['satis_fiyati']??'0') ?>">
                <div class="form-text" id="marjBilgi"></div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Ürün Görseli <small class="text-muted">(JPG/PNG/WEBP, ≤2MB)</small></label>
                <input type="file" name="resim" class="form-control" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Açıklama</label>
                <textarea name="aciklama" class="form-control" rows="2"><?= escH($d['aciklama']??'') ?></textarea>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="seri_no_takip" id="seriNo" <?= !empty($d['seri_no_takip'])?'checked':'' ?>>
                    <label class="form-check-label fw-semibold" for="seriNo">
                        Seri No Takibi <small class="text-muted">(büyük beyaz eşya)</small>
                    </label>
                </div>
            </div>
        </div>
        <div class="alert alert-warning mt-3 mb-0 py-2 d-none" id="zararUyari">
            <i class="bi bi-exclamation-triangle-fill"></i> Satış fiyatı alış fiyatının altında — bu ürün <strong>zararına</strong> satılacak.
        </div>
        <hr>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<script>
const alisEl = document.getElementById('alisInput');
const satisEl = document.getElementById('satisInput');

// Kategori varsayılanları: KDV oranı + hedef marj (marj alanına yazar, satışı ezmez)
const KATEGORI_VARSAYILAN = <?= json_encode(array_column(array_map(fn($k) => [
    'id' => (int)$k['id'],
    'kdv' => isset($k['varsayilan_kdv']) && $k['varsayilan_kdv'] !== null ? (float)$k['varsayilan_kdv'] : null,
    'marj' => isset($k['hedef_marj']) && $k['hedef_marj'] !== null ? (float)$k['hedef_marj'] : null,
], $kategoriler), null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

function kategoriVarsayilan() {
    const v = KATEGORI_VARSAYILAN[document.getElementById('kategoriSecim').value];
    if (!v) return;
    if (v.kdv !== null) document.getElementById('kdvSecim').value = Math.round(v.kdv);
    if (v.marj !== null) {
        document.getElementById('marjInput').value = v.marj;
        // Alış girildiyse ve satış henüz boş/sıfırsa satışı da hesapla
        const alis = parseFloat(alisEl.value) || 0;
        if (alis > 0 && !(parseFloat(satisEl.value) > 0)) marjdanHesapla();
    }
}

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
