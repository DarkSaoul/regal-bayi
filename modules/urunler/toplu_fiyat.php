<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Toplu Fiyat Güncelleme';
$pdo = db();

$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id, ad")->fetchAll();

$sonuc = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $kategori_id = (int)($_POST['kategori_id'] ?? 0) ?: null;
    $hedef       = $_POST['hedef']  ?? 'satis';  // satis | alis | ikisi
    $tip         = $_POST['tip']    ?? 'yuzde';  // yuzde | sabit
    $deger       = (float)$_POST['deger'];
    $yon         = $_POST['yon']    ?? 'arttir'; // arttir | azalt

    if ($deger <= 0) {
        flash('hata', 'Değer 0\'dan büyük olmalıdır.');
        header('Location: toplu_fiyat.php'); exit;
    }

    $where  = $kategori_id ? "WHERE kategori_id=? AND aktif=1" : "WHERE aktif=1";
    $params = $kategori_id ? [$kategori_id] : [];

    $urunler = $pdo->prepare("SELECT id, ad, alis_fiyati, satis_fiyati FROM urunler $where");
    $urunler->execute($params);
    $urunler = $urunler->fetchAll();

    $guncellenen = 0;
    foreach ($urunler as $u) {
        $yeniAlis  = $u['alis_fiyati'];
        $yeniSatis = $u['satis_fiyati'];

        $hesapla = function($fiyat) use ($tip, $deger, $yon) {
            if ($tip === 'yuzde') {
                $fark = round($fiyat * $deger / 100, 2);
            } else {
                $fark = $deger;
            }
            return max(0, $yon === 'arttir' ? $fiyat + $fark : $fiyat - $fark);
        };

        if (in_array($hedef, ['satis','ikisi'])) $yeniSatis = $hesapla($u['satis_fiyati']);
        if (in_array($hedef, ['alis','ikisi']))  $yeniAlis  = $hesapla($u['alis_fiyati']);

        $pdo->prepare("UPDATE urunler SET satis_fiyati=?, alis_fiyati=? WHERE id=?")
            ->execute([$yeniSatis, $yeniAlis, $u['id']]);
        fiyatGecmisiKaydet((int)$u['id'], $u['alis_fiyati'], $yeniAlis, $u['satis_fiyati'], $yeniSatis, 'toplu_fiyat');
        $guncellenen++;
    }

    logla('fiyat_guncelle', 'urunler', $kategori_id ?? 0,
        ($kategori_id ? "Kategori #$kategori_id" : "Tüm ürünler") . " | $hedef | $yon %$deger");
    flash('basari', "$guncellenen ürünün fiyatı güncellendi.");
    header('Location: toplu_fiyat.php'); exit;
}

// Önizleme için ürün sayısı
$urunSayilari = [];
foreach ($kategoriler as $k) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM urunler WHERE kategori_id=? AND aktif=1");
    $s->execute([$k['id']]); $urunSayilari[$k['id']] = $s->fetchColumn();
}
$tumUrunSayisi = $pdo->query("SELECT COUNT(*) FROM urunler WHERE aktif=1")->fetchColumn();
$onSecili = (int)($_GET['kategori_id'] ?? 0); // kategori kartlarındaki "zam/indirim" kısayolu

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-percent text-primary"></i> Toplu Fiyat Güncelleme</h4>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-sliders text-primary"></i> Güncelleme Ayarları
            </div>
            <div class="card-body">
            <form method="post" id="fiyatForm">
                <?= csrfField() ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Kategori</label>
                    <select name="kategori_id" class="form-select" id="kategoriSec">
                        <option value="">Tüm Ürünler (<?= $tumUrunSayisi ?> adet)</option>
                        <?php foreach ($kategoriler as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $onSecili === (int)$k['id'] ? 'selected' : '' ?>>
                            <?= $k['ust_id'] ? '↳ ' : '' ?><?= escH($k['ad']) ?>
                            (<?= $urunSayilari[$k['id']] ?? 0 ?> ürün)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Hangi Fiyat?</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="hedef" id="h1" value="satis" checked>
                            <label class="form-check-label" for="h1">Yalnızca Satış Fiyatı</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="hedef" id="h2" value="alis">
                            <label class="form-check-label" for="h2">Yalnızca Alış Fiyatı</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="hedef" id="h3" value="ikisi">
                            <label class="form-check-label" for="h3">Her İkisi</label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">İşlem</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="yon" id="y1" value="arttir" checked>
                            <label class="form-check-label text-success fw-semibold" for="y1">↑ Artır</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="yon" id="y2" value="azalt">
                            <label class="form-check-label text-danger fw-semibold" for="y2">↓ Azalt</label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Yöntem & Değer</label>
                    <div class="input-group">
                        <select name="tip" class="form-select" style="max-width:140px" id="tipSec">
                            <option value="yuzde">Yüzde (%)</option>
                            <option value="sabit">Sabit Tutar (₺)</option>
                        </select>
                        <input type="number" name="deger" class="form-control" step="0.01" min="0.01" required
                               placeholder="Örn: 10" id="degerInput">
                        <span class="input-group-text" id="birimLabel">%</span>
                    </div>
                    <div class="form-text" id="ornekMetin">Örn: 1.000 ₺ ürün → 1.100 ₺ (%10 artış)</div>
                </div>

                <div class="alert alert-warning d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Bu işlem geri alınamaz. Etkilenecek tüm ürünlerin fiyatı kalıcı olarak değişir.</span>
                </div>

                <button type="submit" class="btn btn-primary btn-lg"
                        onclick="return confirm('Fiyat güncellemesi yapılacak. Emin misiniz?')">
                    <i class="bi bi-check-circle"></i> Fiyatları Güncelle
                </button>
            </form>
            </div>
        </div>
    </div>

    <!-- Bilgi kartı -->
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle text-primary"></i> Nasıl Çalışır?
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        <strong>Yüzde artış:</strong> Tüm seçili ürünler seçilen oran kadar artırılır/azaltılır.
                    </li>
                    <li class="list-group-item">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        <strong>Sabit tutar:</strong> Her ürüne belirtilen miktar eklenir/çıkarılır.
                    </li>
                    <li class="list-group-item">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        Kategori seçilirse yalnızca o kategorideki ürünler etkilenir.
                    </li>
                    <li class="list-group-item">
                        <i class="bi bi-exclamation-triangle text-warning me-2"></i>
                        Fiyat 0'ın altına inmez, en düşük değer 0 olarak korunur.
                    </li>
                    <li class="list-group-item">
                        <i class="bi bi-clock-history text-info me-2"></i>
                        Her güncelleme aktivite loguna kaydedilir.
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('tipSec').addEventListener('change', function() {
    document.getElementById('birimLabel').textContent = this.value === 'yuzde' ? '%' : '₺';
    guncelleOrnek();
});
document.getElementById('degerInput').addEventListener('input', guncelleOrnek);
document.querySelectorAll('input[name="yon"]').forEach(r => r.addEventListener('change', guncelleOrnek));

function guncelleOrnek() {
    const tip   = document.getElementById('tipSec').value;
    const deger = parseFloat(document.getElementById('degerInput').value) || 0;
    const yon   = document.querySelector('input[name="yon"]:checked')?.value || 'arttir';
    const örnek = 1000;
    let sonuc;
    if (tip === 'yuzde') sonuc = yon==='arttir' ? örnek*(1+deger/100) : örnek*(1-deger/100);
    else sonuc = yon==='arttir' ? örnek+deger : örnek-deger;
    sonuc = Math.max(0, sonuc);
    const isaret = yon==='arttir'?'↑':'↓';
    document.getElementById('ornekMetin').textContent =
        `Örn: 1.000 ₺ ürün → ${sonuc.toFixed(2).replace('.',',')} ₺ (${isaret} ${tip==='yuzde'?'%'+deger:deger+' ₺'})`;
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
