<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Stok Giriş';
$pdo = db();
$urun_id = (int)($_GET['urun_id'] ?? 0);
$tedarikci_id = (int)($_GET['tedarikci_id'] ?? 0);
$urunler = $pdo->query("SELECT id, kod, ad, stok_adedi FROM urunler WHERE aktif=1 ORDER BY ad")->fetchAll();
$tedarikciler = $pdo->query("SELECT * FROM tedarikciler ORDER BY ad")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $uid          = (int)$_POST['urun_id'];
    $miktar       = (int)$_POST['miktar'];
    $belge        = trim($_POST['belge_no'] ?? '');
    $aciklama     = trim($_POST['aciklama'] ?? '');
    $tedarikci    = (int)($_POST['tedarikci_id'] ?? 0) ?: null;
    $birim_maliyet = $_POST['birim_maliyet'] !== '' ? (float)$_POST['birim_maliyet'] : null;

    $tesir_adet = max(0, min((int)($_POST['tesir_adet'] ?? 0), $miktar));

    if ($uid > 0 && $miktar > 0) {
        stokGuncelle($uid, $miktar, 'giris', $belge, $aciklama, $tedarikci, $birim_maliyet);
        if ($tedarikci && $birim_maliyet !== null && $birim_maliyet > 0) {
            $toplam = round($miktar * $birim_maliyet, 2);
            $pdo->prepare("UPDATE tedarikciler SET toplam_borc = toplam_borc + ? WHERE id=?")->execute([$toplam, $tedarikci]);
        }
        // Teşhire al
        if ($tesir_adet > 0) {
            $pdo->prepare("UPDATE urunler SET tesir_adedi = tesir_adedi + ? WHERE id=?")
                ->execute([$tesir_adet, $uid]);
            logla('tesir_guncelle', 'stok', $uid, "Stok girişiyle $tesir_adet adet teşhire alındı");
        }
        logla('stok_giris', 'stok', $uid, "$miktar adet stok girişi" . ($belge ? " | Belge: $belge" : '') . ($tesir_adet ? " | $tesir_adet teşhir" : ''));

        // Seri no girişleri
        $seri_nolar = array_filter(array_map('trim', explode("\n", $_POST['seri_nolar'] ?? '')));
        foreach ($seri_nolar as $sn) {
            if ($sn) {
                $pdo->prepare("INSERT IGNORE INTO seri_numaralari (urun_id, seri_no) VALUES (?,?)")->execute([$uid, $sn]);
            }
        }
        flash('basari', "$miktar adet stok girişi yapıldı.");
        header('Location: index.php'); exit;
    }
    flash('hata', 'Geçersiz veri.');
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-box-arrow-in-down text-success"></i> Stok Giriş</h4>
</div>
<div class="card shadow-sm" style="max-width:600px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <!-- Kamera ile barkod tarama -->
        <div class="mb-3">
            <label class="form-label fw-semibold">Barkod ile Hızlı Seç</label>
            <div class="input-group">
                <input type="text" id="stokBarkodInput" class="form-control"
                       placeholder="Barkod / ürün kodu..." autocomplete="off" inputmode="text">
                <button type="button" class="btn btn-outline-success"
                        onclick="kameraIleTara('stok')" title="Kamera ile tara">
                    <i class="bi bi-camera"></i> <span class="d-none d-sm-inline">Kamera</span>
                </button>
                <button type="button" class="btn btn-outline-primary"
                        onclick="_stokBarkodEsles()">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Ürün <span class="text-danger">*</span></label>
            <select name="urun_id" class="form-select" required id="urunSec">
                <option value="">Seçin...</option>
                <?php foreach ($urunler as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $u['id']==$urun_id?'selected':'' ?>>
                    [<?= escH($u['kod']) ?>] <?= escH($u['ad']) ?> — Mevcut: <?= $u['stok_adedi'] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Giriş Miktarı <span class="text-danger">*</span></label>
            <input type="number" name="miktar" class="form-control" min="1" required value="<?= (int)($_POST['miktar']??1) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Tedarikçi</label>
            <select name="tedarikci_id" class="form-select">
                <option value="">Seçin (opsiyonel)</option>
                <?php foreach ($tedarikciler as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $t['id']==$tedarikci_id?'selected':'' ?>><?= escH($t['ad']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Alış / Birim Maliyet <small class="text-muted">(tedarikçi borcunu etkiler)</small></label>
            <div class="input-group">
                <input type="number" name="birim_maliyet" class="form-control" step="0.01" min="0"
                       placeholder="0,00" value="<?= escH($_POST['birim_maliyet']??'') ?>">
                <span class="input-group-text"><?= escH(ayar('para_sembol','₺')) ?></span>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Belge / İrsaliye No</label>
            <input type="text" name="belge_no" class="form-control" value="<?= escH($_POST['belge_no']??'') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Açıklama</label>
            <input type="text" name="aciklama" class="form-control" value="<?= escH($_POST['aciklama']??'') ?>">
        </div>
        <div class="mb-3 p-3 border rounded bg-warning bg-opacity-10">
            <label class="form-label fw-semibold">
                <i class="bi bi-shop-window text-warning"></i> Teşhire Al
                <small class="text-muted fw-normal">(opsiyonel)</small>
            </label>
            <div class="input-group" style="max-width:200px">
                <input type="number" name="tesir_adet" class="form-control" min="0" value="0"
                       placeholder="0" id="tesirAdet">
                <span class="input-group-text">adet</span>
            </div>
            <div class="form-text">Bu girişten kaç adedini doğrudan teşhire almak istiyorsunuz?</div>
        </div>
        <div class="mb-3" id="seriNoAlan" style="display:none">
            <label class="form-label fw-semibold">Seri Numaraları <small class="text-muted">(her satıra bir tane)</small></label>
            <textarea name="seri_nolar" class="form-control" rows="4" placeholder="SN001&#10;SN002&#10;SN003"><?= escH($_POST['seri_nolar']??'') ?></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Stok Girişi Yap</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<script>
window._baseUrl = '<?= BASE_URL ?>';
window._stokUrunler = <?= json_encode(array_map(fn($u) => [
    'id'     => $u['id'],
    'kod'    => $u['kod'],
    'barkod' => $u['barkod'] ?? '',
    'ad'     => $u['ad'],
], $urunler), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

document.getElementById('urunSec').addEventListener('change', function() {
    document.getElementById('seriNoAlan').style.display = this.value ? 'block' : 'none';
});
<?php if ($urun_id): ?>
document.getElementById('seriNoAlan').style.display = 'block';
<?php endif; ?>

function _stokBarkodEsles() {
    const val = document.getElementById('stokBarkodInput').value.trim();
    if (!val) return;
    const u = window._stokUrunler.find(u =>
        u.barkod === val || u.kod === val || u.kod.toLowerCase() === val.toLowerCase()
    );
    if (u) {
        document.getElementById('urunSec').value = u.id;
        document.getElementById('urunSec').dispatchEvent(new Event('change'));
        document.getElementById('stokBarkodInput').value = '';
        document.getElementById('stokBarkodInput').classList.remove('is-invalid');
    } else {
        document.getElementById('stokBarkodInput').classList.add('is-invalid');
        setTimeout(() => document.getElementById('stokBarkodInput').classList.remove('is-invalid'), 1500);
    }
}

document.getElementById('stokBarkodInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); _stokBarkodEsles(); }
});
</script>
<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
