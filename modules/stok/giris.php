<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo']);
$sayfa_basligi = 'Stok Giriş';
$pdo = db();
$urun_id = (int)($_GET['urun_id'] ?? 0);
$tedarikci_id = (int)($_GET['tedarikci_id'] ?? 0);
$urunler = $pdo->query("SELECT id, kod, barkod, ad, stok_adedi, seri_no_takip FROM urunler WHERE aktif=1 ORDER BY ad")->fetchAll();
$tedarikciler = $pdo->query("SELECT * FROM tedarikciler ORDER BY ad")->fetchAll();
$sonMaliyetler = sonAlisMaliyetleri();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $belge     = trim($_POST['belge_no'] ?? '');
    $aciklama  = trim($_POST['aciklama'] ?? '');
    $tedarikci = (int)($_POST['tedarikci_id'] ?? 0) ?: null;

    $s_urun    = $_POST['s_urun'] ?? [];
    $s_miktar  = $_POST['s_miktar'] ?? [];
    $s_maliyet = $_POST['s_maliyet'] ?? [];
    $s_tesir   = $_POST['s_tesir'] ?? [];
    $s_seri    = $_POST['s_seri'] ?? [];

    // Satırları topla + doğrula
    $satirlar = [];
    foreach ((array)$s_urun as $i => $uid) {
        $uid = (int)$uid;
        if (!$uid) continue;
        $miktar = (int)($s_miktar[$i] ?? 0);
        if ($miktar <= 0) continue;
        $satirlar[] = [
            'urun_id' => $uid,
            'miktar'  => $miktar,
            'maliyet' => ($s_maliyet[$i] ?? '') !== '' ? max(0, (float)$s_maliyet[$i]) : null,
            'tesir'   => max(0, min((int)($s_tesir[$i] ?? 0), $miktar)),
            'seri'    => array_filter(array_map('trim', explode("\n", $s_seri[$i] ?? ''))),
        ];
    }

    if (!$satirlar) {
        flash('hata', 'En az bir geçerli ürün satırı girin.');
    } else {
        $pdo->beginTransaction();
        try {
            $urunAd = $pdo->prepare("SELECT ad, kod FROM urunler WHERE id=?");
            $toplamBorc = 0; $fisSatirlari = []; $toplamAdet = 0;
            foreach ($satirlar as $sat) {
                stokGuncelle($sat['urun_id'], $sat['miktar'], 'giris', $belge, $aciklama, $tedarikci, $sat['maliyet']);
                if ($tedarikci && $sat['maliyet'] !== null && $sat['maliyet'] > 0) {
                    $toplamBorc += round($sat['miktar'] * $sat['maliyet'], 2);
                }
                if ($sat['tesir'] > 0) {
                    $pdo->prepare("UPDATE urunler SET tesir_adedi = tesir_adedi + ? WHERE id=?")
                        ->execute([$sat['tesir'], $sat['urun_id']]);
                }
                foreach ($sat['seri'] as $sn) {
                    $pdo->prepare("INSERT IGNORE INTO seri_numaralari (urun_id, seri_no) VALUES (?,?)")->execute([$sat['urun_id'], $sn]);
                }
                $urunAd->execute([$sat['urun_id']]);
                $ub = $urunAd->fetch();
                $toplamAdet += $sat['miktar'];
                $fisSatirlari[] = ['kod' => $ub['kod'], 'ad' => $ub['ad'], 'miktar' => $sat['miktar'],
                    'maliyet' => $sat['maliyet'], 'tesir' => $sat['tesir'], 'seri' => count($sat['seri'])];
            }
            if ($toplamBorc > 0) {
                $pdo->prepare("UPDATE tedarikciler SET toplam_borc = toplam_borc + ? WHERE id=?")->execute([$toplamBorc, $tedarikci]);
            }
            $pdo->commit();

            $tedAd = '';
            if ($tedarikci) {
                foreach ($tedarikciler as $t) if ($t['id'] == $tedarikci) { $tedAd = $t['ad']; break; }
            }
            logla('stok_giris', 'stok', $satirlar[0]['urun_id'],
                count($satirlar) . " kalem / $toplamAdet adet giriş" . ($belge ? " | Belge: $belge" : '') . ($tedAd ? " | $tedAd" : ''));
            $_SESSION['stok_giris_fisi'] = ['zaman' => date('d.m.Y H:i'), 'belge' => $belge, 'tedarikci' => $tedAd,
                'aciklama' => $aciklama, 'satirlar' => $fisSatirlari, 'toplam_adet' => $toplamAdet, 'toplam_tutar' => $toplamBorc,
                'kullanici' => $_SESSION['ad_soyad'] ?? ''];
            flash('basari', count($satirlar) . " kalem / $toplamAdet adet stok girişi yapıldı.");
            header('Location: fis.php'); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('hata', 'Stok girişi sırasında hata: ' . $e->getMessage());
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-box-arrow-in-down text-success"></i> Stok Giriş <small class="text-muted fs-6">(tek belgede çok ürün)</small></h4>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Stok</a>
</div>

<form method="post" id="girisForm" onsubmit="return girisKontrol()">
<?= csrfField() ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text text-primary"></i> Belge Bilgileri</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Tedarikçi</label>
                    <select name="tedarikci_id" class="form-select">
                        <option value="">Seçin (opsiyonel)</option>
                        <?php foreach ($tedarikciler as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id']==$tedarikci_id?'selected':'' ?>><?= escH($t['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Maliyet girilen satırların toplamı tedarikçi borcuna eklenir.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Belge / İrsaliye No</label>
                    <input type="text" name="belge_no" class="form-control" value="<?= escH($_POST['belge_no'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Açıklama</label>
                    <input type="text" name="aciklama" class="form-control" value="<?= escH($_POST['aciklama'] ?? '') ?>">
                </div>
                <div class="p-2 bg-light rounded text-center">
                    <div class="small text-muted">Toplam: <span id="ozetKalem">0</span> kalem / <span id="ozetAdet">0</span> adet</div>
                    <div class="fw-bold fs-5 text-primary" id="ozetTutar">0,00 ₺</div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-upc-scan text-success"></i> Barkodla Satır Ekle</div>
            <div class="card-body">
                <div class="input-group">
                    <input type="text" id="stokBarkodInput" class="form-control" placeholder="Barkod / ürün kodu..." autocomplete="off">
                    <button type="button" class="btn btn-outline-success" onclick="BarcodeScanner.start(v => barkodIsle(v))" title="Kamera ile tara">
                        <i class="bi bi-camera"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="barkodIsle(document.getElementById('stokBarkodInput').value)">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <div class="form-text">Okutulan ürün listede varsa miktarı +1 artar, yoksa yeni satır açılır.</div>
                <div class="small mt-2">Ürün listede yok mu?
                    <a href="<?= BASE_URL ?>/modules/urunler/ekle.php" target="_blank">Yeni ürün ekle</a> — kaydedince bu sayfayı yenileyin.</div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam text-primary"></i> Giriş Kalemleri</span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="satirEkle()"><i class="bi bi-plus"></i> Satır Ekle</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light"><tr>
                        <th style="min-width:220px">Ürün</th>
                        <th style="width:80px" class="text-center">Miktar</th>
                        <th style="width:150px">Birim Maliyet (₺)</th>
                        <th style="width:80px" class="text-center" title="Kaç adedi teşhire alınacak">Teşhir</th>
                        <th style="width:60px" class="text-center" title="Seri numaraları">Seri</th>
                        <th style="width:36px"></th>
                    </tr></thead>
                    <tbody id="satirBody"></tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-success btn-lg" id="kaydetBtn"><i class="bi bi-check-circle"></i> Stok Girişini Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary btn-lg">İptal</a>
        </div>
    </div>
</div>
</form>

<script>
const URUNLER = <?= json_encode(array_map(fn($u) => [
    'id' => (int)$u['id'], 'kod' => $u['kod'], 'barkod' => $u['barkod'] ?? '', 'ad' => $u['ad'],
    'stok' => (int)$u['stok_adedi'], 'seri' => (int)$u['seri_no_takip'],
], $urunler), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const SON_MALIYET = <?= json_encode(array_map('floatval', $sonMaliyetler), JSON_UNESCAPED_UNICODE) ?>;

let satirNo = 0;
function satirEkle(urunId = 0, miktar = 1) {
    satirNo++;
    const opts = URUNLER.map(u => `<option value="${u.id}" ${u.id === urunId ? 'selected' : ''}>[${u.kod}] ${u.ad} — Mevcut: ${u.stok}</option>`).join('');
    const tr = document.createElement('tr');
    tr.className = 'giris-satir';
    tr.innerHTML = `
        <td>
            <select name="s_urun[]" class="form-select form-select-sm g-urun" required>
                <option value="">Ürün seçin...</option>${opts}</select>
            <div class="form-text g-ipucu" style="display:none"></div>
        </td>
        <td><input type="number" name="s_miktar[]" class="form-control form-control-sm g-miktar text-center" min="1" value="${miktar}"></td>
        <td><input type="number" name="s_maliyet[]" class="form-control form-control-sm g-maliyet" step="0.01" min="0" placeholder="0,00"></td>
        <td><input type="number" name="s_tesir[]" class="form-control form-control-sm g-tesir text-center" min="0" value="0"></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-secondary g-seri-btn py-0" style="display:none"
                    onclick="this.closest('tr').querySelector('.g-seri-kutu').style.display=''; this.style.display='none'">SN</button>
            <textarea name="s_seri[]" class="form-control form-control-sm g-seri-kutu mt-1" rows="2"
                      placeholder="Her satıra bir seri no" style="display:none;min-width:120px"></textarea>
        </td>
        <td><button type="button" class="btn btn-sm btn-outline-danger px-1" onclick="this.closest('tr').remove();ozetGuncelle()"><i class="bi bi-trash"></i></button></td>`;
    document.getElementById('satirBody').appendChild(tr);
    const sel = tr.querySelector('.g-urun');
    sel.addEventListener('change', () => urunSecildi(tr));
    if (urunId) urunSecildi(tr);
    ozetGuncelle();
    return tr;
}

function urunSecildi(tr) {
    const id = parseInt(tr.querySelector('.g-urun').value) || 0;
    const u = URUNLER.find(x => x.id === id);
    const ipucu = tr.querySelector('.g-ipucu');
    const seriBtn = tr.querySelector('.g-seri-btn');
    const seriKutu = tr.querySelector('.g-seri-kutu');
    if (!u) { ipucu.style.display = 'none'; seriBtn.style.display = 'none'; seriKutu.style.display = 'none'; return; }
    // Son alış maliyeti ipucu + otomatik doldurma (boşsa)
    const sm = SON_MALIYET[id];
    if (sm) {
        ipucu.textContent = 'Son alış maliyeti: ' + sm.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺';
        ipucu.style.display = '';
        const malInput = tr.querySelector('.g-maliyet');
        if (!malInput.value) malInput.value = sm.toFixed(2);
    } else ipucu.style.display = 'none';
    // Seri no alanı yalnızca seri takipli ürünlerde
    if (u.seri) { seriBtn.style.display = ''; } else { seriBtn.style.display = 'none'; seriKutu.style.display = 'none'; seriKutu.value = ''; }
    ozetGuncelle();
}

function barkodIsle(deger) {
    deger = (deger || '').trim();
    if (!deger) return;
    const u = URUNLER.find(x => x.barkod === deger || x.kod === deger || x.kod.toLowerCase() === deger.toLowerCase());
    const input = document.getElementById('stokBarkodInput');
    if (!u) {
        input.classList.add('is-invalid');
        setTimeout(() => input.classList.remove('is-invalid'), 1500);
        return;
    }
    // Zaten satırda varsa miktarı artır
    const mevcutTr = [...document.querySelectorAll('.giris-satir')].find(tr => parseInt(tr.querySelector('.g-urun').value) === u.id);
    if (mevcutTr) {
        const m = mevcutTr.querySelector('.g-miktar');
        m.value = (parseInt(m.value) || 0) + 1;
    } else {
        satirEkle(u.id, 1);
    }
    input.value = '';
    ozetGuncelle();
}

function ozetGuncelle() {
    let kalem = 0, adet = 0, tutar = 0;
    document.querySelectorAll('.giris-satir').forEach(tr => {
        const id = parseInt(tr.querySelector('.g-urun').value) || 0;
        const m = parseInt(tr.querySelector('.g-miktar').value) || 0;
        const f = parseFloat(tr.querySelector('.g-maliyet').value) || 0;
        if (id && m > 0) { kalem++; adet += m; tutar += m * f; }
    });
    document.getElementById('ozetKalem').textContent = kalem;
    document.getElementById('ozetAdet').textContent = adet;
    document.getElementById('ozetTutar').textContent = tutar.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺';
}

function girisKontrol() {
    const kalem = [...document.querySelectorAll('.giris-satir')].filter(tr =>
        (parseInt(tr.querySelector('.g-urun').value) || 0) && (parseInt(tr.querySelector('.g-miktar').value) || 0) > 0).length;
    if (!kalem) { alert('En az bir ürün satırı girin.'); return false; }
    document.getElementById('kaydetBtn').disabled = true;
    return true;
}

document.addEventListener('input', e => { if (e.target.closest('.giris-satir')) ozetGuncelle(); });
document.getElementById('stokBarkodInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); barkodIsle(e.target.value); }
});
satirEkle(<?= $urun_id ?: 0 ?>);
</script>
<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
