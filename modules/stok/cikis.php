<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Stok Çıkış (Fire/İade)';
$pdo = db();
$urun_id = (int)($_GET['urun_id'] ?? 0);
$urunler = $pdo->query("SELECT id, kod, ad, stok_adedi, seri_no_takip FROM urunler WHERE aktif=1 ORDER BY ad")->fetchAll();
$sonMaliyetler = sonAlisMaliyetleri();

// Seri takipli ürünlerin stoktaki/teşhirdeki seri numaraları (JS için)
$seriler = $pdo->query("SELECT id, urun_id, seri_no, durum FROM seri_numaralari
    WHERE durum IN ('stokta','tesirde') ORDER BY seri_no")->fetchAll();
$seriMap = [];
foreach ($seriler as $sn) $seriMap[$sn['urun_id']][] = ['id' => (int)$sn['id'], 'no' => $sn['seri_no'], 'durum' => $sn['durum']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $uid    = (int)$_POST['urun_id'];
    $miktar = (int)$_POST['miktar'];
    // Her iki seçenek de stok DÜŞÜRÜR; tedarikçiye iade 'cikis' tipiyle kaydedilir
    // ('iade_giris' yalnızca stok artışları için kullanılır — bkz. satış iptali)
    $secim  = $_POST['tip'] ?? 'fire';
    $tip    = $secim === 'tedarikci_iade' ? 'cikis' : 'fire';
    $aciklama = trim($_POST['aciklama'] ?? '');
    if ($secim === 'tedarikci_iade') {
        $aciklama = 'Tedarikçiye iade' . ($aciklama ? ' — ' . $aciklama : '');
    }
    $birim_maliyet = ($_POST['birim_maliyet'] ?? '') !== '' ? max(0, (float)$_POST['birim_maliyet']) : null;
    $seciliSeriler = array_values(array_filter(array_map('intval', (array)($_POST['seri'] ?? []))));

    $pdo->beginTransaction();
    try {
        $stmtM = $pdo->prepare("SELECT stok_adedi, seri_no_takip, ad FROM urunler WHERE id=? FOR UPDATE");
        $stmtM->execute([$uid]);
        $urun = $stmtM->fetch();
        $mevcut = (int)($urun['stok_adedi'] ?? 0);

        if (!$urun || $uid <= 0 || $miktar <= 0 || $miktar > $mevcut) {
            $pdo->rollBack();
            flash('hata', 'Geçersiz miktar veya stok yetersiz.');
        } elseif ($urun['seri_no_takip'] && count($seciliSeriler) !== $miktar) {
            $pdo->rollBack();
            flash('hata', 'Bu ürün seri no takipli: çıkış miktarı kadar seri numarası seçmelisiniz (' . $miktar . ' adet).');
        } else {
            // Seri numaralarını doğrula + durumlarını güncelle (fire→ariza, iade→iade)
            if ($urun['seri_no_takip'] && $seciliSeriler) {
                $yt = implode(',', array_fill(0, count($seciliSeriler), '?'));
                $dogrula = $pdo->prepare("SELECT COUNT(*) FROM seri_numaralari
                    WHERE id IN ($yt) AND urun_id=? AND durum IN ('stokta','tesirde')");
                $dogrula->execute(array_merge($seciliSeriler, [$uid]));
                if ((int)$dogrula->fetchColumn() !== count($seciliSeriler)) {
                    throw new Exception('Seçilen seri numaraları geçersiz veya artık stokta değil.');
                }
                $yeniDurum = $secim === 'tedarikci_iade' ? 'iade' : 'ariza';
                $pdo->prepare("UPDATE seri_numaralari SET durum='$yeniDurum' WHERE id IN ($yt)")->execute($seciliSeriler);
            }

            stokGuncelle($uid, -$miktar, $tip, '', $aciklama, null, $birim_maliyet);
            // Teşhir adedi toplam stoktan büyük kalamaz
            $pdo->prepare("UPDATE urunler SET tesir_adedi = LEAST(tesir_adedi, stok_adedi) WHERE id=?")->execute([$uid]);
            $pdo->commit();

            $maliyetNot = $birim_maliyet !== null ? ' | Maliyet: ' . para($birim_maliyet * $miktar) : '';
            logla($secim === 'tedarikci_iade' ? 'stok_iade' : 'stok_fire', 'stok', $uid,
                "$miktar adet {$urun['ad']} çıkışı ($secim)$maliyetNot" . ($seciliSeriler ? ' | ' . count($seciliSeriler) . ' seri no' : ''));
            flash('basari', "$miktar adet stok çıkışı yapıldı." . ($seciliSeriler ? ' Seri numaraları güncellendi.' : ''));
            header('Location: index.php'); exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('hata', 'Stok çıkışı sırasında hata: ' . $e->getMessage());
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-box-arrow-up text-danger"></i> Stok Çıkış / Fire</h4>
</div>
<div class="alert alert-warning"><i class="bi bi-info-circle"></i> Bu form satış dışı stok azaltımları içindir (fire, hasar, tedarikçiye iade vb.). Fiyatlar maliyet raporu için kaydedilir.</div>
<div class="card shadow-sm" style="max-width:560px">
    <div class="card-body">
    <form method="post" onsubmit="return cikisKontrol()">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label fw-semibold">Ürün <span class="text-danger">*</span></label>
            <select name="urun_id" id="urunSec" class="form-select" required onchange="urunDegisti()">
                <option value="">Seçin...</option>
                <?php foreach ($urunler as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $u['id']==$urun_id?'selected':'' ?>>[<?= escH($u['kod']) ?>] <?= escH($u['ad']) ?> — Stok: <?= $u['stok_adedi'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Çıkış Tipi</label>
            <select name="tip" class="form-select">
                <option value="fire">Fire / Hasar</option>
                <option value="tedarikci_iade">Tedarikçiye İade</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Miktar <span class="text-danger">*</span></label>
            <input type="number" name="miktar" id="miktarInput" class="form-control" min="1" required value="1" oninput="seriUyari()">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Birim Maliyet (₺) <small class="text-muted">(fire maliyet raporu için)</small></label>
            <input type="number" name="birim_maliyet" id="maliyetInput" class="form-control" step="0.01" min="0" placeholder="0,00">
            <div class="form-text" id="maliyetIpucu"></div>
        </div>
        <div class="mb-3" id="seriAlan" style="display:none">
            <label class="form-label fw-semibold">Seri Numaraları <span class="text-danger">*</span>
                <small class="text-muted">(çıkış miktarı kadar seçin)</small></label>
            <div class="border rounded p-2" style="max-height:180px;overflow-y:auto" id="seriListe"></div>
            <div class="form-text text-danger" id="seriDurum"></div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Açıklama / Neden</label>
            <input type="text" name="aciklama" class="form-control">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-danger"><i class="bi bi-dash-circle"></i> Çıkış Yap</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>

<script>
const SERILER = <?= json_encode($seriMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const SERI_TAKIP = <?= json_encode(array_column(array_filter($urunler, fn($u) => $u['seri_no_takip']), 'seri_no_takip', 'id')) ?>;
const SON_MALIYET = <?= json_encode(array_map('floatval', $sonMaliyetler)) ?>;

function urunDegisti() {
    const id = parseInt(document.getElementById('urunSec').value) || 0;
    // Maliyet ipucu + otomatik doldurma
    const ipucu = document.getElementById('maliyetIpucu');
    const malInput = document.getElementById('maliyetInput');
    if (SON_MALIYET[id]) {
        ipucu.textContent = 'Son alış maliyeti: ' + SON_MALIYET[id].toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺ (otomatik yazıldı)';
        malInput.value = SON_MALIYET[id].toFixed(2);
    } else { ipucu.textContent = ''; malInput.value = ''; }
    // Seri no listesi
    const alan = document.getElementById('seriAlan');
    const liste = document.getElementById('seriListe');
    if (SERI_TAKIP[id]) {
        const seriler = SERILER[id] || [];
        liste.innerHTML = seriler.length
            ? seriler.map(s => `<label class="d-block small mb-1">
                <input type="checkbox" class="form-check-input me-1 seri-sec" name="seri[]" value="${s.id}" onchange="seriUyari()">
                <code>${s.no}</code>${s.durum === 'tesirde' ? ' <span class="badge bg-warning text-dark">teşhirde</span>' : ''}</label>`).join('')
            : '<div class="text-muted small">Bu ürün için stokta kayıtlı seri no yok — önce stok girişinde seri no girin.</div>';
        alan.style.display = '';
    } else { alan.style.display = 'none'; liste.innerHTML = ''; }
    seriUyari();
}
function seriUyari() {
    const id = parseInt(document.getElementById('urunSec').value) || 0;
    if (!SERI_TAKIP[id]) { document.getElementById('seriDurum').textContent = ''; return; }
    const secili = document.querySelectorAll('.seri-sec:checked').length;
    const miktar = parseInt(document.getElementById('miktarInput').value) || 0;
    document.getElementById('seriDurum').textContent =
        secili === miktar ? '' : `Seçili: ${secili} / Gerekli: ${miktar}`;
}
function cikisKontrol() {
    const id = parseInt(document.getElementById('urunSec').value) || 0;
    if (SERI_TAKIP[id]) {
        const secili = document.querySelectorAll('.seri-sec:checked').length;
        const miktar = parseInt(document.getElementById('miktarInput').value) || 0;
        if (secili !== miktar) {
            alert('Bu ürün seri no takipli: ' + miktar + ' adet çıkış için ' + miktar + ' seri numarası seçmelisiniz (şu an ' + secili + ').');
            return false;
        }
    }
    return true;
}
<?php if ($urun_id): ?>urunDegisti();<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
