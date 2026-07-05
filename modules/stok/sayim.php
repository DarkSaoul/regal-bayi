<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo']);
$sayfa_basligi = 'Stok Sayımı';
$pdo = db();

$kategori_id = (int)($_GET['kategori_id'] ?? 0) ?: null;
$arama       = trim($_GET['ara'] ?? '');

$where  = "WHERE u.aktif=1";
$params = [];
if ($kategori_id) { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params[] = $kategori_id; $params[] = $kategori_id; }
if ($arama)       { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ?)"; $params = array_merge($params, array_fill(0, 3, likeParam($arama))); }

// ── Boş sayım formu yazdırma ─────────────────────────────────
if (isset($_GET['yazdir'])) {
    $stmt = $pdo->prepare("SELECT u.kod, u.ad, u.stok_adedi, k.ad AS kategori FROM urunler u
        LEFT JOIN kategoriler k ON u.kategori_id=k.id $where ORDER BY k.ad, u.ad");
    $stmt->execute($params);
    $liste = $stmt->fetchAll();
    $firma = ayar('firma_adi', 'Regal Bayi');
    ?><!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><title>Sayım Formu</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, sans-serif; font-size:12px; padding:10mm; }
    h2 { font-size:16px; } .ust { display:flex; justify-content:space-between; border-bottom:2px solid #333; padding-bottom:6px; margin-bottom:8px; }
    table { width:100%; border-collapse:collapse; }
    th, td { border:1px solid #999; padding:4px 6px; text-align:left; }
    th { background:#f0f0f0; }
    .say { width:22mm; } .orta { text-align:center; }
    .arac { margin-bottom:10px; } .arac button { padding:6px 14px; }
    @media print { .arac { display:none; } }
</style></head><body>
<div class="arac"><button onclick="window.print()">🖨 Yazdır</button> <button onclick="history.back()">← Geri</button></div>
<div class="ust">
    <div><h2><?= escH($firma) ?></h2><div>STOK SAYIM FORMU</div></div>
    <div style="text-align:right">
        <div>Tarih: <?= date('d.m.Y') ?></div>
        <div>Sayan: ______________________</div>
    </div>
</div>
<table>
    <thead><tr><th style="width:22mm">Kod</th><th>Ürün</th><th style="width:35mm">Kategori</th>
        <th class="orta" style="width:20mm">Sistem</th><th class="orta say">Sayılan</th><th class="orta say">Fark</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $u): ?>
    <tr><td><?= escH($u['kod']) ?></td><td><?= escH($u['ad']) ?></td><td><?= escH($u['kategori'] ?? '—') ?></td>
        <td class="orta"><?= $u['stok_adedi'] ?></td><td></td><td></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body></html><?php
    exit;
}

// ── POST: önizleme + kayıt ───────────────────────────────────
$onizleme = null; $onizlemeAciklama = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon   = $_POST['aksiyon'] ?? 'kaydet';
    $aciklama  = trim($_POST['sayim_aciklama'] ?? 'Stok sayımı');
    $miktarlar = $_POST['sayim_miktar'] ?? [];

    if (!is_array($miktarlar) || empty($miktarlar)) {
        flash('hata', 'Sayım verisi bulunamadı.');
        header('Location: sayim.php'); exit;
    }

    if ($aksiyon === 'onizle') {
        // Yalnızca değişen satırları maliyet etkisiyle listele — henüz hiçbir şey yazılmaz
        $onizleme = []; $onizlemeAciklama = $aciklama;
        $bilgi = $pdo->prepare("SELECT id, kod, ad, stok_adedi, alis_fiyati FROM urunler WHERE id=?");
        foreach ($miktarlar as $uid => $yeni) {
            $uid = (int)$uid; $yeni = (int)$yeni;
            if ($yeni < 0) continue;
            $bilgi->execute([$uid]);
            $u = $bilgi->fetch();
            if (!$u || (int)$u['stok_adedi'] === $yeni) continue;
            $fark = $yeni - (int)$u['stok_adedi'];
            $onizleme[] = ['id' => $uid, 'kod' => $u['kod'], 'ad' => $u['ad'], 'mevcut' => (int)$u['stok_adedi'],
                'yeni' => $yeni, 'fark' => $fark, 'maliyet' => $fark * (float)$u['alis_fiyati']];
        }
        if (!$onizleme) {
            flash('hata', 'Hiçbir üründe değişiklik yok — sayım kaydedilecek bir fark içermiyor.');
            header('Location: sayim.php?' . http_build_query(array_filter(['kategori_id' => $kategori_id, 'ara' => $arama]))); exit;
        }
    } else {
        $guncellenen = 0;
        $pdo->beginTransaction();
        try {
            foreach ($miktarlar as $urun_id => $yeni_miktar) {
                $urun_id     = (int)$urun_id;
                $yeni_miktar = (int)$yeni_miktar;
                if ($yeni_miktar < 0) continue;

                $stmt = $pdo->prepare("SELECT stok_adedi FROM urunler WHERE id=? FOR UPDATE");
                $stmt->execute([$urun_id]);
                $mevcut = $stmt->fetchColumn();
                if ($mevcut === false) continue; // ürün yok
                $mevcut = (int)$mevcut;
                if ($mevcut === $yeni_miktar) continue; // Değişiklik yok

                $fark = $yeni_miktar - $mevcut;
                $pdo->prepare("UPDATE urunler SET stok_adedi=?, tesir_adedi=LEAST(tesir_adedi, ?) WHERE id=?")
                    ->execute([$yeni_miktar, $yeni_miktar, $urun_id]);
                $pdo->prepare("INSERT INTO stok_hareketleri (urun_id,hareket_tipi,miktar,onceki_stok,sonraki_stok,aciklama,kullanici_id) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$urun_id, 'sayim_duzeltme', abs($fark), $mevcut, $yeni_miktar, $aciklama, $_SESSION['kullanici_id']]);
                $guncellenen++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('hata', 'Sayım kaydı sırasında hata: ' . $e->getMessage());
            header('Location: sayim.php'); exit;
        }
        logla('stok_sayim', 'stok', 0, "$guncellenen ürün güncellendi | $aciklama");
        flash('basari', "$guncellenen ürünün stok sayımı güncellendi.");
        header('Location: sayim.php'); exit;
    }
}

$urunler = $pdo->prepare("SELECT u.id, u.kod, u.barkod, u.ad, u.stok_adedi, u.min_stok, k.ad AS kategori
    FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where ORDER BY k.ad, u.ad");
$urunler->execute($params);
$urunler = $urunler->fetchAll();

$kategoriler = $pdo->query("SELECT * FROM kategoriler WHERE ust_id IS NULL ORDER BY sira, ad")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-clipboard-check text-primary"></i> Stok Sayımı</h4>
    <div class="d-flex gap-2 align-items-center">
        <a href="?<?= http_build_query(array_filter(['kategori_id' => $kategori_id, 'ara' => $arama, 'yazdir' => 1])) ?>"
           target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Boş Sayım Formu</a>
        <span class="text-muted small"><?= count($urunler) ?> ürün</span>
    </div>
</div>

<?php if ($onizleme !== null): ?>
<!-- ═══ SAYIM ÖNİZLEMESİ ═══ -->
<?php
$toplamFark = array_sum(array_column($onizleme, 'fark'));
$toplamMaliyet = array_sum(array_column($onizleme, 'maliyet'));
?>
<div class="card shadow-sm mb-3 border-primary">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-eye text-primary"></i> Sayım Önizlemesi — henüz kaydedilmedi</div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Değişen Ürün</div><div class="fw-bold"><?= count($onizleme) ?></div></div></div>
            <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Net Adet Farkı</div><div class="fw-bold <?= $toplamFark < 0 ? 'text-danger' : 'text-success' ?>"><?= $toplamFark > 0 ? '+' : '' ?><?= $toplamFark ?></div></div></div>
            <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Maliyet Etkisi (Alış)</div><div class="fw-bold <?= $toplamMaliyet < 0 ? 'text-danger' : 'text-success' ?>"><?= para($toplamMaliyet) ?></div></div></div>
        </div>
        <div class="table-responsive mb-3" style="max-height:340px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0">
                <thead class="sticky-top bg-white"><tr><th>Kod</th><th>Ürün</th>
                    <th class="text-center">Sistem</th><th class="text-center">Sayılan</th><th class="text-center">Fark</th><th class="text-end">Maliyet Etkisi</th></tr></thead>
                <tbody>
                <?php foreach ($onizleme as $o): ?>
                <tr class="<?= $o['fark'] < 0 ? 'table-danger' : 'table-success' ?>">
                    <td><code><?= escH($o['kod']) ?></code></td>
                    <td><?= escH($o['ad']) ?></td>
                    <td class="text-center"><?= $o['mevcut'] ?></td>
                    <td class="text-center fw-bold"><?= $o['yeni'] ?></td>
                    <td class="text-center fw-bold"><?= $o['fark'] > 0 ? '+' : '' ?><?= $o['fark'] ?></td>
                    <td class="text-end"><?= para($o['maliyet']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="d-inline" onsubmit="this.querySelector('button').disabled=true">
            <?= csrfField() ?>
            <input type="hidden" name="aksiyon" value="kaydet">
            <input type="hidden" name="sayim_aciklama" value="<?= escH($onizlemeAciklama) ?>">
            <?php foreach ($onizleme as $o): ?>
            <input type="hidden" name="sayim_miktar[<?= $o['id'] ?>]" value="<?= $o['yeni'] ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-success"
                    onclick="return confirm('<?= count($onizleme) ?> ürünün stoğu sayım sonucuna göre güncellenecek. Onaylıyor musunuz?')">
                <i class="bi bi-check-circle"></i> Onayla ve Kaydet (<?= count($onizleme) ?> ürün)
            </button>
        </form>
        <a href="sayim.php" class="btn btn-outline-secondary">Vazgeç</a>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>Fiziksel sayım sonucunu <strong>Gerçek Miktar</strong> sütununa girin veya <strong>barkod okutarak</strong> sayın (her okutma +1).
        "Önizle"ye bastığınızda farklar gösterilir; onaydan önce hiçbir şey değişmez.</div>
</div>

<!-- Filtre + barkod sayım -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <form class="row g-2" method="get">
                    <div class="col-6">
                        <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ürün adı / kodu..." value="<?= escH($arama) ?>">
                    </div>
                    <div class="col-4">
                        <select name="kategori_id" class="form-select form-select-sm">
                            <option value="">Tüm Kategoriler</option>
                            <?php foreach ($kategoriler as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $kategori_id==$k['id']?'selected':'' ?>><?= escH($k['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-2">
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
            <div class="col-md-7">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-success text-white"><i class="bi bi-upc-scan"></i> Barkodlu Sayım</span>
                    <input type="text" id="sayimBarkod" class="form-control" placeholder="Barkod okutun — her okutma sayımı +1 artırır" autocomplete="off">
                    <button type="button" class="btn btn-outline-success" onclick="BarcodeScanner.start(v => barkodSay(v))"><i class="bi bi-camera"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="post" id="sayimForm">
    <?= csrfField() ?>
    <input type="hidden" name="aksiyon" value="onizle">
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-check text-primary"></i> Sayım Listesi</span>
            <input type="text" name="sayim_aciklama" class="form-control form-control-sm"
                   style="max-width:220px" placeholder="Sayım notu..." value="Stok sayımı <?= date('d.m.Y') ?>">
        </div>
        <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Kod</th><th>Ürün</th><th>Kategori</th>
                    <th class="text-center">Sistemdeki Miktar</th>
                    <th class="text-center" style="width:150px">Gerçek Miktar</th>
                    <th class="text-center">Fark</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($urunler)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Ürün bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($urunler as $u): ?>
            <tr class="sayim-satir" data-sistem="<?= $u['stok_adedi'] ?>" data-barkod="<?= escH($u['barkod'] ?? '') ?>" data-kod="<?= escH($u['kod']) ?>">
                <td><small class="text-muted"><?= escH($u['kod']) ?></small></td>
                <td class="fw-semibold"><?= escH($u['ad']) ?></td>
                <td><small class="text-muted"><?= escH($u['kategori'] ?? '—') ?></small></td>
                <td class="text-center">
                    <span class="badge bg-<?= $u['stok_adedi'] <= 0 ? 'danger' : ($u['stok_adedi'] <= $u['min_stok'] ? 'warning' : 'success') ?>">
                        <?= $u['stok_adedi'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <input type="number" name="sayim_miktar[<?= $u['id'] ?>]"
                           class="form-control form-control-sm text-center sayim-input"
                           min="0" value="<?= $u['stok_adedi'] ?>"
                           style="width:90px;margin:0 auto"
                           oninput="farkHesapla(this)">
                </td>
                <td class="text-center fark-hucre fw-bold">—</td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <div class="d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-eye"></i> Önizle
        </button>
        <a href="index.php" class="btn btn-outline-secondary btn-lg">İptal</a>
        <span class="text-muted ms-3" id="degisiklikSayisi"></span>
    </div>
</form>

<script>
function farkHesapla(input) {
    const satir   = input.closest('tr');
    const sistem  = parseInt(satir.dataset.sistem) || 0;
    const gercek  = parseInt(input.value) || 0;
    const fark    = gercek - sistem;
    const hucre   = satir.querySelector('.fark-hucre');
    if (fark === 0) {
        hucre.textContent = '—';
        hucre.className = 'text-center fark-hucre fw-bold';
        satir.classList.remove('table-warning','table-danger','table-success');
    } else {
        hucre.textContent = (fark > 0 ? '+' : '') + fark;
        hucre.className = 'text-center fark-hucre fw-bold ' + (fark > 0 ? 'text-success' : 'text-danger');
        satir.classList.remove('table-warning','table-danger','table-success');
        satir.classList.add(fark > 0 ? 'table-success' : 'table-danger');
    }
    const degisen = document.querySelectorAll('.sayim-satir.table-success, .sayim-satir.table-danger').length;
    document.getElementById('degisiklikSayisi').textContent = degisen > 0 ? `${degisen} üründe değişiklik var` : '';
}

// Barkodlu sayım: okutulan ürünün "sayılan" değerini +1 artırır.
// İlk okutmada sıfırdan saymaya başlar (sistem değeri değil, gerçek sayım).
const sayilanlar = new Set();
function barkodSay(deger) {
    deger = (deger || '').trim();
    if (!deger) return;
    const satir = [...document.querySelectorAll('.sayim-satir')].find(tr =>
        tr.dataset.barkod === deger || tr.dataset.kod === deger || tr.dataset.kod.toLowerCase() === deger.toLowerCase());
    const input = document.getElementById('sayimBarkod');
    if (!satir) {
        input.classList.add('is-invalid');
        setTimeout(() => input.classList.remove('is-invalid'), 1500);
        return;
    }
    const alan = satir.querySelector('.sayim-input');
    const uid = alan.name;
    if (!sayilanlar.has(uid)) { alan.value = 1; sayilanlar.add(uid); }
    else { alan.value = (parseInt(alan.value) || 0) + 1; }
    farkHesapla(alan);
    satir.scrollIntoView({ block: 'center', behavior: 'smooth' });
    satir.style.outline = '2px solid #0d6efd';
    setTimeout(() => satir.style.outline = '', 800);
    input.value = '';
    if (navigator.vibrate) navigator.vibrate(60);
}
document.getElementById('sayimBarkod').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); barkodSay(e.target.value); }
});
</script>
<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
