<?php
// Vardiya (kasa devri) — açılış/kapanış, fiziksel nakit sayımı, devir tutanağı
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
moduleKontrol('vardiya', 'Vardiya');
$sayfa_basligi = 'Vardiya';
$pdo = db();
$rol = $_SESSION['rol'] ?? '';

$banknotlar = [200, 100, 50, 20, 10, 5, 1];

$acikVardiya = $pdo->query("SELECT v.*, k.ad_soyad FROM kasa_vardiyalari v JOIN kullanicilar k ON v.kullanici_id=k.id WHERE v.durum='acik' ORDER BY v.id DESC LIMIT 1")->fetch();

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'ac') {
        if ($acikVardiya) {
            $hata = 'Zaten açık bir vardiya var (' . escH($acikVardiya['ad_soyad']) . ').';
        } else {
            $acilis = max(0, round((float)($_POST['acilis_tutari'] ?? 0), 2));
            $pdo->prepare("INSERT INTO kasa_vardiyalari (kullanici_id, baslangic, acilis_tutari, durum) VALUES (?,NOW(),?,'acik')")
                ->execute([$_SESSION['kullanici_id'], $acilis]);
            logla('vardiya_ac', 'finans', (int)$pdo->lastInsertId(), 'Açılış: ' . para($acilis));
            flash('basari', 'Vardiya açıldı.');
            header('Location: vardiya.php'); exit;
        }
    } elseif ($aksiyon === 'kapat') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $v = $pdo->prepare("SELECT * FROM kasa_vardiyalari WHERE id=? AND durum='acik' FOR UPDATE");
            $v->execute([$id]); $v = $v->fetch();
            if (!$v) throw new RuntimeException('Açık vardiya bulunamadı.');
            if ($rol !== 'yonetici' && (int)$v['kullanici_id'] !== (int)$_SESSION['kullanici_id']) {
                throw new RuntimeException('Yalnızca kendi vardiyanızı kapatabilirsiniz.');
            }

            // Sistem bakiyesi: vardiya başlangıcından şu ana kadarki onaylı kasa (nakit) hareketi + açılış tutarı
            $hareket = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE -tutar END),0) FROM kasa_hareketleri
                WHERE hesap='kasa' AND onay_durumu='onaylandi' AND created_at >= ?");
            $hareket->execute([$v['baslangic']]);
            $sistemBakiye = round((float)$v['acilis_tutari'] + (float)$hareket->fetchColumn(), 2);

            // Fiziksel sayım
            $toplam = 0; $detay = [];
            foreach ($banknotlar as $b) {
                $adet = max(0, (int)($_POST['adet_' . $b] ?? 0));
                if ($adet > 0) { $detay[$b] = $adet; $toplam += $adet * $b; }
            }
            $bozukluk = max(0, round((float)($_POST['bozukluk'] ?? 0), 2));
            if ($bozukluk > 0) $detay['bozukluk'] = $bozukluk;
            $toplam = round($toplam + $bozukluk, 2);
            $fark = round($toplam - $sistemBakiye, 2);

            $devirId = (int)($_POST['devir_kullanici_id'] ?? 0) ?: null;
            if ($devirId) {
                $dv = $pdo->prepare("SELECT id FROM kullanicilar WHERE id=? AND aktif=1");
                $dv->execute([$devirId]);
                if (!$dv->fetchColumn()) $devirId = null;
            }
            $notlar = mb_substr(trim($_POST['notlar'] ?? ''), 0, 255) ?: null;

            $pdo->prepare("UPDATE kasa_vardiyalari SET bitis=NOW(), sistem_bakiye=?, fiili_tutar=?, fark=?, sayim_detay=?, devir_kullanici_id=?, notlar=?, durum='kapali' WHERE id=?")
                ->execute([$sistemBakiye, $toplam, $fark, json_encode($detay, JSON_UNESCAPED_UNICODE), $devirId, $notlar, $id]);

            // Devir varsa: sonraki kasiyer için otomatik yeni vardiya aç (fiili tutar açılış olur)
            if ($devirId) {
                $pdo->prepare("INSERT INTO kasa_vardiyalari (kullanici_id, baslangic, acilis_tutari, durum, notlar) VALUES (?,NOW(),?,'acik',?)")
                    ->execute([$devirId, $toplam, 'Devir: #' . $id . ' vardiyasından']);
            }

            $pdo->commit();
            logla('vardiya_kapat', 'finans', $id, 'Sistem: ' . para($sistemBakiye) . ' | Fiili: ' . para($toplam) . ' | Fark: ' . para($fark)
                . ($devirId ? ' | Devir yapıldı' : ''));
            $mesaj = 'Vardiya kapatıldı. Fark: ' . para($fark);
            flash(abs($fark) > 0.005 ? 'uyari' : 'basari', $mesaj . ($devirId ? ' — sonraki kasiyer için yeni vardiya açıldı.' : ''));
        } catch (RuntimeException $e) {
            $pdo->rollBack(); flash('hata', $e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack(); flash('hata', 'Vardiya kapatılırken hata: ' . $e->getMessage());
        }
        header('Location: vardiya.php'); exit;
    }
}

// Açık vardiya için canlı sistem bakiyesi (görüntüleme amaçlı)
$canliSistemBakiye = null;
if ($acikVardiya) {
    $hareket = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE -tutar END),0) FROM kasa_hareketleri
        WHERE hesap='kasa' AND onay_durumu='onaylandi' AND created_at >= ?");
    $hareket->execute([$acikVardiya['baslangic']]);
    $canliSistemBakiye = round((float)$acikVardiya['acilis_tutari'] + (float)$hareket->fetchColumn(), 2);
}

$kasiyerler = $pdo->query("SELECT id, ad_soyad FROM kullanicilar WHERE aktif=1 AND rol IN ('yonetici','kasiyer') ORDER BY ad_soyad")->fetchAll();
$gecmisVardiyalar = $pdo->query("SELECT v.*, k.ad_soyad, d.ad_soyad AS devir_adi FROM kasa_vardiyalari v
    JOIN kullanicilar k ON v.kullanici_id=k.id LEFT JOIN kullanicilar d ON v.devir_kullanici_id=d.id
    WHERE v.durum='kapali' ORDER BY v.id DESC LIMIT 20")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-arrow-left-right text-primary"></i> Vardiya (Kasa Devri)</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Kasa & Finans</a>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<?php if (!$acikVardiya): ?>
<div class="card shadow-sm mb-3" style="max-width:450px">
    <div class="card-header bg-white fw-semibold py-2">Vardiya Aç</div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="aksiyon" value="ac">
            <label class="form-label small fw-semibold mb-1">Açılış Tutarı (₺)</label>
            <input type="number" name="acilis_tutari" class="form-control mb-2" step="0.01" min="0" value="0" required>
            <div class="form-text mb-2">Kasada şu an fiilen bulunan nakit tutar.</div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-play-circle"></i> Vardiyayı Başlat</button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm mb-3 border-info">
    <div class="card-header bg-info text-white fw-semibold py-2">
        <i class="bi bi-person-check"></i> Açık Vardiya — <?= escH($acikVardiya['ad_soyad']) ?>
        <span class="small opacity-75 ms-2">Başlangıç: <?= tarihSaat($acikVardiya['baslangic']) ?></span>
    </div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="p-2 bg-light rounded text-center">
                    <div class="small text-muted">Açılış Tutarı</div>
                    <div class="fw-bold"><?= para($acikVardiya['acilis_tutari']) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-2 bg-light rounded text-center">
                    <div class="small text-muted">Şu An Beklenen (Sistem)</div>
                    <div class="fw-bold text-primary"><?= para($canliSistemBakiye) ?></div>
                </div>
            </div>
        </div>

        <?php if ($rol === 'yonetici' || (int)$acikVardiya['kullanici_id'] === (int)$_SESSION['kullanici_id']): ?>
        <hr>
        <h6 class="fw-semibold"><i class="bi bi-calculator text-primary"></i> Vardiyayı Kapat — Fiziksel Nakit Sayımı</h6>
        <form method="post" id="kapatForm">
            <?= csrfField() ?>
            <input type="hidden" name="aksiyon" value="kapat">
            <input type="hidden" name="id" value="<?= $acikVardiya['id'] ?>">
            <div class="row g-2 mb-2">
                <?php foreach ($banknotlar as $b): ?>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1"><?= $b ?> ₺</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="adet_<?= $b ?>" class="form-control sayim-adet" data-deger="<?= $b ?>" min="0" value="0">
                        <span class="input-group-text">adet</span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Bozukluk Toplamı (₺)</label>
                    <input type="number" name="bozukluk" id="bozuklukInput" class="form-control form-control-sm sayim-adet" step="0.01" min="0" value="0">
                </div>
            </div>
            <div class="p-2 bg-light rounded d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Sayılan Toplam</span>
                <span class="fw-bold fs-5" id="sayimToplam">0,00 ₺</span>
            </div>
            <div class="p-2 rounded d-flex justify-content-between align-items-center mb-3" id="farkKutu">
                <span class="fw-semibold">Fark (Sayılan − Sistem)</span>
                <span class="fw-bold fs-5" id="farkGoster">-</span>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold mb-1">Devir Yapılacak Kasiyer <span class="text-muted">(opsiyonel)</span></label>
                    <select name="devir_kullanici_id" class="form-select form-select-sm">
                        <option value="">— Devir yok, vardiya kapatılıyor —</option>
                        <?php foreach ($kasiyerler as $k): if ((int)$k['id'] === (int)$acikVardiya['kullanici_id']) continue; ?>
                        <option value="<?= $k['id'] ?>"><?= escH($k['ad_soyad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold mb-1">Not</label>
                    <input type="text" name="notlar" class="form-control form-control-sm" maxlength="255">
                </div>
            </div>
            <button type="submit" class="btn btn-warning fw-semibold" onclick="return confirm('Vardiya kapatılacak. Onaylıyor musunuz?')">
                <i class="bi bi-door-closed"></i> Vardiyayı Kapat
            </button>
        </form>
        <?php else: ?>
        <div class="text-muted small">Bu vardiyayı yalnızca açan kasiyer veya yönetici kapatabilir.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold py-2">Geçmiş Vardiyalar (Açık/Fazla Geçmişi)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Kasiyer</th><th>Başlangıç</th><th>Bitiş</th><th>Açılış</th><th>Sistem</th><th>Fiili</th><th>Fark</th><th>Devir</th></tr></thead>
            <tbody>
            <?php if (empty($gecmisVardiyalar)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Kapanmış vardiya yok</td></tr>
            <?php endif; ?>
            <?php foreach ($gecmisVardiyalar as $v):
                $farkRenk = abs($v['fark']) < 0.01 ? 'text-success' : ($v['fark'] < 0 ? 'text-danger' : 'text-warning');
            ?>
            <tr>
                <td><?= escH($v['ad_soyad']) ?></td>
                <td class="small"><?= tarihSaat($v['baslangic']) ?></td>
                <td class="small"><?= tarihSaat($v['bitis']) ?></td>
                <td><?= para($v['acilis_tutari']) ?></td>
                <td><?= para($v['sistem_bakiye']) ?></td>
                <td><?= para($v['fiili_tutar']) ?></td>
                <td class="fw-bold <?= $farkRenk ?>"><?= $v['fark'] > 0 ? '+' : '' ?><?= para($v['fark']) ?></td>
                <td class="small"><?= $v['devir_adi'] ? escH($v['devir_adi']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
function sayimHesapla() {
    if (!document.getElementById('sayimToplam')) return; // açık vardiya / kapatma formu yoksa hiçbir şey yapma
    let toplam = 0;
    document.querySelectorAll('.sayim-adet').forEach(inp => {
        const deger = parseFloat(inp.dataset.deger || 1);
        const val = parseFloat(inp.value) || 0;
        toplam += inp.id === 'bozuklukInput' ? val : val * deger;
    });
    document.getElementById('sayimToplam').textContent = new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2}).format(toplam) + ' ₺';
    const sistem = <?= json_encode((float)($canliSistemBakiye ?? 0)) ?>;
    const fark = toplam - sistem;
    const el = document.getElementById('farkGoster');
    const kutu = document.getElementById('farkKutu');
    el.textContent = (fark > 0 ? '+' : '') + new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2}).format(fark) + ' ₺';
    kutu.className = 'p-2 rounded d-flex justify-content-between align-items-center mb-3 ' + (Math.abs(fark) < 0.01 ? 'bg-success bg-opacity-25' : (fark < 0 ? 'bg-danger bg-opacity-25' : 'bg-warning bg-opacity-25'));
}
document.querySelectorAll('.sayim-adet').forEach(inp => inp.addEventListener('input', sayimHesapla));
sayimHesapla();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
