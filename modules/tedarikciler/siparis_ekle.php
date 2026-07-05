<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Yeni Sipariş';
$pdo = db();

$tedarikci_id = (int)($_GET['tedarikci_id'] ?? 0);
$tedarikciler = $pdo->query("SELECT id, ad FROM tedarikciler ORDER BY ad")->fetchAll();
$urunler      = $pdo->query("SELECT id, kod, ad, alis_fiyati FROM urunler WHERE aktif=1 ORDER BY ad")->fetchAll();

// Ön doldurma (örn. düşük stok sayfasından): ?on_urunler=id:miktar,id:miktar
$onYukle = [];
foreach (array_filter(explode(',', $_GET['on_urunler'] ?? '')) as $parca) {
    [$oid, $omik] = array_pad(explode(':', $parca), 2, 1);
    $oid = (int)$oid; $omik = max(1, (int)$omik);
    if ($oid) $onYukle[] = ['id' => $oid, 'miktar' => $omik];
}

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $ted   = (int)($_POST['tedarikci_id'] ?? 0);
    $tarih = gecerliTarih($_POST['tarih'] ?? '', date('Y-m-d'));
    $beklenen = ($_POST['beklenen_tarih'] ?? '') !== '' ? gecerliTarih($_POST['beklenen_tarih'], $tarih) : null;
    $notlar = trim($_POST['notlar'] ?? '');

    $k_urun  = $_POST['kalem_urun']  ?? [];
    $k_mik   = $_POST['kalem_miktar'] ?? [];
    $k_fiyat = $_POST['kalem_fiyat'] ?? [];

    if (!$ted) {
        $hata = 'Tedarikçi seçmelisiniz.';
    } elseif (empty(array_filter($k_urun))) {
        $hata = 'En az bir ürün eklemelisiniz.';
    } else {
        $pdo->beginTransaction();
        try {
            $kalemler = []; $toplam = 0;
            foreach ($k_urun as $i => $uid) {
                if (!$uid) continue;
                $uid = (int)$uid;
                $mik = max(1, (int)($k_mik[$i] ?? 1));
                $fiy = max(0, round((float)($k_fiyat[$i] ?? 0), 2));
                $toplam += $mik * $fiy;
                $kalemler[] = [$uid, $mik, $fiy];
            }
            if (empty($kalemler)) throw new RuntimeException('Geçerli ürün satırı yok.');

            // Sipariş no çakışmasına karşı birkaç deneme
            for ($deneme = 0; $deneme < 5; $deneme++) {
                $siparis_no = yeniSiparisNo();
                try {
                    $pdo->prepare("INSERT INTO tedarikci_siparisleri (siparis_no,tedarikci_id,tarih,beklenen_tarih,durum,toplam_tutar,notlar,kullanici_id) VALUES (?,?,?,?, 'bekliyor', ?,?,?)")
                        ->execute([$siparis_no, $ted, $tarih, $beklenen, round($toplam,2), $notlar ?: null, $_SESSION['kullanici_id']]);
                    break;
                } catch (PDOException $e) {
                    if ($deneme === 4) throw $e; // son deneme de başarısızsa yükselt
                }
            }
            $sid = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO siparis_kalemleri (siparis_id,urun_id,miktar,birim_fiyat) VALUES (?,?,?,?)");
            foreach ($kalemler as [$uid, $mik, $fiy]) $stmt->execute([$sid, $uid, $mik, $fiy]);

            $pdo->commit();
            logla('siparis_olustur', 'tedarikciler', $sid, "Sipariş: $siparis_no | " . para($toplam));
            flash('basari', "Sipariş oluşturuldu: $siparis_no");
            header('Location: siparis_detay.php?id=' . $sid); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $hata = 'Sipariş kaydedilemedi: ' . $e->getMessage();
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-clipboard-plus text-primary"></i> Yeni Satın Alma Siparişi</h4>
    <a href="siparisler.php" class="btn btn-outline-secondary btn-sm">← Siparişler</a>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<form method="post" id="siparisForm">
<?= csrfField() ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-truck text-primary"></i> Sipariş Bilgileri</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Tedarikçi <span class="text-danger">*</span></label>
                    <select name="tedarikci_id" class="form-select" required>
                        <option value="">Seçin...</option>
                        <?php foreach ($tedarikciler as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id']==$tedarikci_id?'selected':'' ?>><?= escH($t['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Sipariş Tarihi</label>
                    <input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Beklenen Teslim <small class="text-muted">(ops.)</small></label>
                    <input type="date" name="beklenen_tarih" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notlar</label>
                    <textarea name="notlar" class="form-control" rows="2" placeholder="Ödeme koşulu, teslimat vb..."></textarea>
                </div>
                <div class="p-2 bg-light rounded text-center">
                    <div class="small text-muted">Tahmini Toplam</div>
                    <div class="fw-bold fs-4 text-primary" id="toplamGoster">0,00 ₺</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam text-primary"></i> Sipariş Kalemleri</span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="kalemEkle()"><i class="bi bi-plus"></i> Satır Ekle</button>
            </div>
            <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th>Ürün</th><th style="width:90px" class="text-center">Miktar</th>
                    <th style="width:140px">Birim Fiyat</th><th style="width:120px" class="text-end">Satır</th><th style="width:36px"></th>
                </tr></thead>
                <tbody id="kalemBody">
                    <tr id="bosMesaj"><td colspan="5" class="text-center text-muted py-4">"Satır Ekle" ile ürün ekleyin</td></tr>
                </tbody>
            </table>
            </div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-circle"></i> Siparişi Kaydet</button>
            <a href="siparisler.php" class="btn btn-outline-secondary btn-lg">İptal</a>
        </div>
    </div>
</div>
</form>

<script>
const urunler = <?= json_encode(array_map(fn($u) => [
    'id'=>$u['id'], 'kod'=>$u['kod'], 'ad'=>$u['ad'], 'fiyat'=>(float)$u['alis_fiyati']
], $urunler), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function fmt(n){ return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2}).format(n)+' ₺'; }

function kalemEkle() {
    document.getElementById('bosMesaj')?.remove();
    const opts = urunler.map(u => `<option value="${u.id}" data-fiyat="${u.fiyat}">[${u.kod}] ${u.ad}</option>`).join('');
    const row = document.createElement('tr');
    row.className = 'kalem-row';
    row.innerHTML = `
        <td><select name="kalem_urun[]" class="form-select form-select-sm k-urun" required>
            <option value="">Ürün seçin...</option>${opts}</select></td>
        <td><input type="number" name="kalem_miktar[]" class="form-control form-control-sm k-miktar text-center" min="1" value="1"></td>
        <td><input type="number" name="kalem_fiyat[]" class="form-control form-control-sm k-fiyat" step="0.01" min="0" value="0"></td>
        <td class="text-end fw-bold k-satir">0,00 ₺</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger px-1" onclick="this.closest('tr').remove();hesapla()"><i class="bi bi-trash"></i></button></td>`;
    document.getElementById('kalemBody').appendChild(row);
    row.querySelector('.k-urun').addEventListener('change', function(){
        const f = this.options[this.selectedIndex].dataset.fiyat;
        if (f) row.querySelector('.k-fiyat').value = f;
        hesapla();
    });
    hesapla();
}

function hesapla() {
    let toplam = 0;
    document.querySelectorAll('.kalem-row').forEach(r => {
        const m = parseInt(r.querySelector('.k-miktar').value)||0;
        const f = parseFloat(r.querySelector('.k-fiyat').value)||0;
        const s = m*f; toplam += s;
        r.querySelector('.k-satir').textContent = fmt(s);
    });
    document.getElementById('toplamGoster').textContent = fmt(toplam);
}
document.addEventListener('input', e => { if (e.target.closest('.kalem-row')) hesapla(); });

// Düşük stok sayfasından gelen ön doldurma
const ON_YUKLE = <?= json_encode($onYukle) ?>;
if (ON_YUKLE.length) {
    ON_YUKLE.forEach(o => {
        kalemEkle();
        const satirlar = document.querySelectorAll('.kalem-row');
        const satir = satirlar[satirlar.length - 1];
        const sel = satir.querySelector('.k-urun');
        sel.value = o.id;
        sel.dispatchEvent(new Event('change'));
        satir.querySelector('.k-miktar').value = o.miktar;
    });
    hesapla();
} else {
    kalemEkle();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
