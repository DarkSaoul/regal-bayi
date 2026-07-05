<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo']);
$sayfa_basligi = 'Stok Sayımı';
$pdo = db();

// Satış ekranındaki "sayım devam ediyor olabilir" bilgilendirmesi için iz
if ($_SERVER['REQUEST_METHOD'] === 'GET') { try { ayarKaydet('sayim_son_acilis', (string)time()); } catch (Exception $e) {} }

// ── Kapsam parametreleri (GET veya önizleme hidden'larından) ──
$kaynak = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$kategori_id = (int)($kaynak['kategori_id'] ?? 0) ?: null;
$arama       = trim($kaynak['ara'] ?? '');
$marka       = trim($kaynak['marka'] ?? '');
$durumF      = in_array($kaynak['durum'] ?? '', ['tesirde','kritik'], true) ? $kaynak['durum'] : '';
$rasgele     = (int)($kaynak['rasgele'] ?? 0);
$ids         = array_values(array_filter(array_map('intval', explode(',', trim($kaynak['ids'] ?? '')))));

function sayimWhere(?int $kategori_id, string $arama, string $marka, string $durumF, array $ids): array {
    $where = "WHERE u.aktif=1"; $params = [];
    if ($ids) {
        $where .= " AND u.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        return [$where, $ids];
    }
    if ($kategori_id) { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params[] = $kategori_id; $params[] = $kategori_id; }
    if ($arama)       { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ?)"; $params = array_merge($params, array_fill(0, 3, likeParam($arama))); }
    if ($marka)       { $where .= " AND u.marka=?"; $params[] = $marka; }
    if ($durumF === 'tesirde') $where .= " AND u.tesir_adedi > 0";
    if ($durumF === 'kritik')  $where .= " AND u.stok_adedi <= u.min_stok";
    return [$where, $params];
}

function kapsamMetni(PDO $pdo, ?int $kategori_id, string $arama, string $marka, string $durumF, int $rasgele, array $ids): string {
    if ($ids) return count($ids) . ' seçili ürün';
    $p = [];
    if ($kategori_id) {
        $k = $pdo->prepare("SELECT ad FROM kategoriler WHERE id=?"); $k->execute([$kategori_id]);
        $p[] = ($k->fetchColumn() ?: '?') . ' kategorisi';
    } else $p[] = 'Tüm ürünler';
    if ($marka)  $p[] = 'marka: ' . $marka;
    if ($arama)  $p[] = 'arama: ' . $arama;
    if ($durumF) $p[] = $durumF === 'tesirde' ? 'teşhirdekiler' : 'kritik stok';
    if ($rasgele) $p[] = "rastgele $rasgele ürün";
    return implode(', ', $p);
}

// ── Boş sayım formu yazdırma ─────────────────────────────────
if (isset($_GET['yazdir'])) {
    [$where, $params] = sayimWhere($kategori_id, $arama, $marka, $durumF, $ids);
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
    th { background:#f0f0f0; } .say { width:22mm; } .orta { text-align:center; }
    .arac { margin-bottom:10px; } .arac button { padding:6px 14px; }
    @media print { .arac { display:none; } }
</style></head><body>
<div class="arac"><button onclick="window.print()">🖨 Yazdır</button> <button onclick="history.back()">← Geri</button></div>
<div class="ust">
    <div><h2><?= escH($firma) ?></h2><div>STOK SAYIM FORMU — <?= escH(kapsamMetni($pdo, $kategori_id, $arama, $marka, $durumF, $rasgele, $ids)) ?></div></div>
    <div style="text-align:right"><div>Tarih: <?= date('d.m.Y') ?></div><div>Sayan: ______________________</div></div>
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
$onizleme = null; $onizlemeAciklama = ''; $kayipSeriler = []; $ciftSayim = null; $sayilmayanSayi = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon   = $_POST['aksiyon'] ?? '';
    $aciklama  = trim($_POST['sayim_aciklama'] ?? 'Stok sayımı');
    $miktarlar = $_POST['sayim_miktar'] ?? [];

    if ($aksiyon === 'onizle') {
        // Yalnızca DOLU girişler işlenir ("sayılmadı" ile "0" ayrımı)
        $onizleme = []; $onizlemeAciklama = $aciklama;
        $bilgi = $pdo->prepare("SELECT id, kod, ad, stok_adedi, alis_fiyati, seri_no_takip FROM urunler WHERE id=?");
        $sayilanUrunler = [];
        foreach ((array)$miktarlar as $uid => $deger) {
            if (!is_scalar($deger) || trim((string)$deger) === '') { $sayilmayanSayi++; continue; }
            $uid = (int)$uid; $yeni = (int)$deger;
            if ($yeni < 0) continue;
            $bilgi->execute([$uid]);
            $u = $bilgi->fetch();
            if (!$u) continue;
            $fark = $yeni - (int)$u['stok_adedi'];
            $buyuk = abs($fark) >= max(5, (int)ceil($u['stok_adedi'] * 0.5)) && $fark !== 0;
            $onizleme[] = ['id' => $uid, 'kod' => $u['kod'], 'ad' => $u['ad'], 'mevcut' => (int)$u['stok_adedi'],
                'yeni' => $yeni, 'fark' => $fark, 'maliyet' => $fark * (float)$u['alis_fiyati'], 'buyuk' => $buyuk,
                'seri' => (int)$u['seri_no_takip']];
            $sayilanUrunler[$uid] = (int)$u['seri_no_takip'];
        }
        if (!$onizleme) {
            flash('hata', 'Hiçbir ürün sayılmadı — sayım değeri girilen ürün yok.');
            header('Location: sayim.php?' . http_build_query(array_filter(['kategori_id' => $kategori_id, 'ara' => $arama, 'marka' => $marka, 'durum' => $durumF]))); exit;
        }

        // Kayıp seri tespiti: seri okutulan ürünlerde, stokta görünüp okutulmayanlar
        $okunanSeriler = array_filter(array_map('trim', explode("\n", $_POST['seri_okunan'] ?? '')));
        if ($okunanSeriler) {
            $seriUrunIds = array_keys(array_filter($sayilanUrunler));
            if ($seriUrunIds) {
                $yt = implode(',', array_fill(0, count($seriUrunIds), '?'));
                $tumSeriler = $pdo->prepare("SELECT sn.seri_no, sn.durum, u.ad, u.kod FROM seri_numaralari sn
                    JOIN urunler u ON sn.urun_id=u.id
                    WHERE sn.urun_id IN ($yt) AND sn.durum IN ('stokta','tesirde')");
                $tumSeriler->execute($seriUrunIds);
                foreach ($tumSeriler->fetchAll() as $sn) {
                    if (!in_array($sn['seri_no'], $okunanSeriler, true)) $kayipSeriler[] = $sn;
                }
            }
        }

        // Aynı gün aynı kapsamda sayım var mı? (çift sayım uyarısı)
        $kapsamStr = kapsamMetni($pdo, $kategori_id, $arama, $marka, $durumF, $rasgele, $ids);
        $cs = $pdo->prepare("SELECT created_at FROM sayimlar WHERE DATE(created_at)=CURDATE() AND kapsam=? ORDER BY id DESC LIMIT 1");
        $cs->execute([$kapsamStr]);
        $ciftSayim = $cs->fetchColumn() ?: null;

    } elseif ($aksiyon === 'kaydet') {
        $oncekiler = $_POST['sayim_onceki'] ?? [];   // önizleme anındaki stok (çakışma koruması)
        $fireIsle  = !empty($_POST['fire_isle']);
        $kapsamStr = trim($_POST['kapsam_metni'] ?? '') ?: kapsamMetni($pdo, $kategori_id, $arama, $marka, $durumF, $rasgele, $ids);
        $degisen = 0; $atlananCakisma = []; $netFark = 0; $maliyetToplam = 0.0; $detaylar = []; $sayilan = 0;

        $pdo->beginTransaction();
        try {
            foreach ((array)$miktarlar as $urun_id => $yeni_miktar) {
                if (!is_scalar($yeni_miktar) || trim((string)$yeni_miktar) === '') continue;
                $urun_id = (int)$urun_id; $yeni_miktar = (int)$yeni_miktar;
                if ($yeni_miktar < 0) continue;
                $sayilan++;

                $stmt = $pdo->prepare("SELECT stok_adedi, alis_fiyati, kod FROM urunler WHERE id=? FOR UPDATE");
                $stmt->execute([$urun_id]);
                $u = $stmt->fetch();
                if (!$u) continue;
                $mevcut = (int)$u['stok_adedi'];

                // Çakışma koruması: önizlemeden beri stok değiştiyse (örn. satış) bu ürünü ATLA
                $snapshot = isset($oncekiler[$urun_id]) ? (int)$oncekiler[$urun_id] : $mevcut;
                if ($mevcut !== $snapshot) { $atlananCakisma[] = $u['kod']; continue; }
                if ($mevcut === $yeni_miktar) continue;

                $fark = $yeni_miktar - $mevcut;
                $maliyet = $fark * (float)$u['alis_fiyati'];
                $pdo->prepare("UPDATE urunler SET stok_adedi=?, tesir_adedi=LEAST(tesir_adedi, ?) WHERE id=?")
                    ->execute([$yeni_miktar, $yeni_miktar, $urun_id]);

                // Eksik çıkanlar istenirse FIRE olarak kaydedilir (maliyetli — kayıp raporu için)
                $tip = ($fireIsle && $fark < 0) ? 'fire' : 'sayim_duzeltme';
                $bm  = ($tip === 'fire') ? (float)$u['alis_fiyati'] : null;
                $pdo->prepare("INSERT INTO stok_hareketleri (urun_id,hareket_tipi,miktar,onceki_stok,sonraki_stok,aciklama,birim_maliyet,toplam_maliyet,kullanici_id)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$urun_id, $tip, abs($fark), $mevcut, $yeni_miktar, $aciklama,
                        $bm, $bm !== null ? round(abs($fark) * $bm, 2) : null, $_SESSION['kullanici_id']]);

                $degisen++; $netFark += $fark; $maliyetToplam += $maliyet;
                $detaylar[] = [$urun_id, $mevcut, $yeni_miktar, $fark, round($maliyet, 2)];
            }

            // Sayım oturumunu kaydet (değişiklik olmasa bile — "sayıldı, fark yok" da kıymetli bilgi)
            $pdo->prepare("INSERT INTO sayimlar (kategori_id, kapsam, aciklama, sayilan, degisen, atlanan, net_fark, maliyet_etkisi, fire_islendi, kullanici_id)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$kategori_id, $kapsamStr, $aciklama, $sayilan, $degisen, count($atlananCakisma),
                    $netFark, round($maliyetToplam, 2), $fireIsle ? 1 : 0, $_SESSION['kullanici_id']]);
            $sayimId = (int)$pdo->lastInsertId();
            $dstmt = $pdo->prepare("INSERT INTO sayim_detaylari (sayim_id, urun_id, onceki, sayilan, fark, maliyet) VALUES (?,?,?,?,?,?)");
            foreach ($detaylar as $d) $dstmt->execute(array_merge([$sayimId], $d));

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('hata', 'Sayım kaydı sırasında hata: ' . $e->getMessage());
            header('Location: sayim.php'); exit;
        }
        logla('stok_sayim', 'stok', $sayimId, "$sayilan sayıldı, $degisen değişti | $kapsamStr | $aciklama");
        $mesaj = "Sayım kaydedildi: $sayilan ürün sayıldı, $degisen ürün güncellendi.";
        if ($atlananCakisma) $mesaj .= ' DİKKAT: ' . implode(', ', array_slice($atlananCakisma, 0, 5)) . ' sayım sırasında değişti (satış olabilir), atlandı — bu ürünleri yeniden sayın.';
        flash($atlananCakisma ? 'uyari' : 'basari', $mesaj);
        header('Location: sayim_gecmis.php?id=' . $sayimId); exit;
    }
}

// ── Liste verisi ─────────────────────────────────────────────
[$where, $params] = sayimWhere($kategori_id, $arama, $marka, $durumF, $ids);
$siralama = $rasgele > 0 ? "ORDER BY RAND() LIMIT " . min(100, $rasgele) : "ORDER BY k.ad, u.ad";
$urunler = $pdo->prepare("SELECT u.id, u.kod, u.barkod, u.ad, u.stok_adedi, u.min_stok, u.seri_no_takip, k.ad AS kategori
    FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where $siralama");
$urunler->execute($params);
$urunler = $urunler->fetchAll();

// Kapsamdaki seri takipli ürünlerin serileri (seri okutmalı sayım için)
$seriJs = [];
$seriUrunler = array_column(array_filter($urunler, fn($u) => $u['seri_no_takip']), 'id');
if ($seriUrunler) {
    $yt = implode(',', array_fill(0, count($seriUrunler), '?'));
    $ss = $pdo->prepare("SELECT seri_no, urun_id FROM seri_numaralari WHERE urun_id IN ($yt) AND durum IN ('stokta','tesirde')");
    $ss->execute($seriUrunler);
    foreach ($ss->fetchAll() as $sn) $seriJs[$sn['seri_no']] = (int)$sn['urun_id'];
}

$kategoriler = $pdo->query("SELECT * FROM kategoriler WHERE ust_id IS NULL ORDER BY sira, ad")->fetchAll();
$markalar = $pdo->query("SELECT DISTINCT marka FROM urunler WHERE aktif=1 AND marka!='' ORDER BY marka")->fetchAll(PDO::FETCH_COLUMN);

// Kategori sayım turu: her ana kategorinin son sayımı (kategorisiz tam sayım da sayılır)
$sonTamSayim = $pdo->query("SELECT MAX(created_at) FROM sayimlar WHERE kategori_id IS NULL AND kapsam LIKE 'Tüm ürünler%'")->fetchColumn();
$katSayimlar = $pdo->query("SELECT kategori_id, MAX(created_at) AS son FROM sayimlar WHERE kategori_id IS NOT NULL GROUP BY kategori_id")->fetchAll(PDO::FETCH_KEY_PAIR);

$kapsamStr = kapsamMetni($pdo, $kategori_id, $arama, $marka, $durumF, $rasgele, $ids);

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* Mobil kart görünümü: dar ekranda sayım tablosu satırları kart olur */
@media (max-width: 767px) {
    #sayimTablo thead { display: none; }
    #sayimTablo tbody tr { display: block; border: 1px solid #dee2e6; border-radius: 8px; margin: 8px; padding: 6px 10px; }
    #sayimTablo tbody td { display: flex; justify-content: space-between; align-items: center; border: 0; padding: 3px 0; }
    #sayimTablo tbody td::before { content: attr(data-etiket); font-weight: 600; color: #6c757d; font-size: .8rem; }
    #sayimTablo .sayim-input { width: 110px !important; }
}
</style>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-clipboard-check text-primary"></i> Stok Sayımı</h4>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <a href="sayim_gecmis.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-clock-history"></i> Sayım Geçmişi</a>
        <a href="?<?= http_build_query(array_filter(['kategori_id' => $kategori_id, 'ara' => $arama, 'marka' => $marka, 'durum' => $durumF, 'ids' => implode(',', $ids), 'yazdir' => 1])) ?>"
           target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer"></i> Boş Form</a>
        <span class="text-muted small"><?= count($urunler) ?> ürün</span>
    </div>
</div>

<?php if ($onizleme !== null): ?>
<!-- ═══ SAYIM ÖNİZLEMESİ ═══ -->
<?php
$degisenler = array_filter($onizleme, fn($o) => $o['fark'] !== 0);
$toplamFark = array_sum(array_column($onizleme, 'fark'));
$toplamMaliyet = array_sum(array_column($onizleme, 'maliyet'));
$buyukFarklar = count(array_filter($onizleme, fn($o) => $o['buyuk']));
?>
<div class="card shadow-sm mb-3 border-primary">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-eye text-primary"></i> Sayım Önizlemesi — henüz kaydedilmedi</div>
    <div class="card-body">
        <p class="mb-2"><strong>Kapsam:</strong> <?= escH($kapsamStr) ?> —
            <?= count($onizleme) ?> ürün sayıldı, <?= count($degisenler) ?> üründe fark var<?= $sayilmayanSayi ? ", $sayilmayanSayi ürün sayılmadı (dokunulmayacak)" : '' ?>.</p>

        <?php if ($ciftSayim): ?>
        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i>
            Bu kapsamda bugün <strong><?= tarihSaat($ciftSayim) ?></strong>'de zaten bir sayım kaydedilmiş — çift sayım olmadığından emin olun.</div>
        <?php endif; ?>
        <?php if ($buyukFarklar): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-octagon-fill"></i>
            <strong><?= $buyukFarklar ?> üründe büyük sapma</strong> var (kırmızı satırlar) — kaydetmeden önce bu ürünleri yeniden kontrol edin.</div>
        <?php endif; ?>

        <div class="row g-2 mb-3">
            <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Değişen Ürün</div><div class="fw-bold"><?= count($degisenler) ?></div></div></div>
            <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Net Adet Farkı</div><div class="fw-bold <?= $toplamFark < 0 ? 'text-danger' : 'text-success' ?>"><?= $toplamFark > 0 ? '+' : '' ?><?= $toplamFark ?></div></div></div>
            <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Maliyet Etkisi (Alış)</div><div class="fw-bold <?= $toplamMaliyet < 0 ? 'text-danger' : 'text-success' ?>"><?= para($toplamMaliyet) ?></div></div></div>
        </div>

        <?php if ($kayipSeriler): ?>
        <div class="card border-danger mb-3">
            <div class="card-header bg-white fw-semibold py-2 text-danger"><i class="bi bi-question-octagon"></i>
                Okutulmayan Seri No'lar — Kayıp Adayı (<?= count($kayipSeriler) ?>)</div>
            <div class="card-body py-2 small">
                <?php foreach ($kayipSeriler as $ks): ?>
                <div><code><?= escH($ks['seri_no']) ?></code> — <?= escH($ks['ad']) ?> (<?= escH($ks['kod']) ?>, sistemde: <?= escH($ks['durum']) ?>)</div>
                <?php endforeach; ?>
                <div class="text-muted mt-1">Bu cihazlar sistemde stokta görünüyor ama sayımda okutulmadı. Fiziksel olarak arayın; bulunamazsa sayım değerini düşürün.</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="table-responsive mb-3" style="max-height:340px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0">
                <thead class="sticky-top bg-white"><tr><th>Kod</th><th>Ürün</th>
                    <th class="text-center">Sistem</th><th class="text-center">Sayılan</th><th class="text-center">Fark</th><th class="text-end">Maliyet Etkisi</th></tr></thead>
                <tbody>
                <?php foreach ($onizleme as $o): ?>
                <tr class="<?= $o['buyuk'] ? 'table-danger fw-bold' : ($o['fark'] < 0 ? 'table-warning' : ($o['fark'] > 0 ? 'table-success' : '')) ?>">
                    <td><code><?= escH($o['kod']) ?></code></td>
                    <td><?= escH($o['ad']) ?><?= $o['buyuk'] ? ' <i class="bi bi-exclamation-triangle-fill text-danger"></i>' : '' ?></td>
                    <td class="text-center"><?= $o['mevcut'] ?></td>
                    <td class="text-center fw-bold"><?= $o['yeni'] ?></td>
                    <td class="text-center fw-bold"><?= $o['fark'] > 0 ? '+' : '' ?><?= $o['fark'] ?></td>
                    <td class="text-end"><?= para($o['maliyet']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="d-inline" onsubmit="taslakTemizle(); this.querySelector('button[type=submit]').disabled=true">
            <?= csrfField() ?>
            <input type="hidden" name="aksiyon" value="kaydet">
            <input type="hidden" name="sayim_aciklama" value="<?= escH($onizlemeAciklama) ?>">
            <input type="hidden" name="kapsam_metni" value="<?= escH($kapsamStr) ?>">
            <?php foreach (['kategori_id','ara','marka','durum','rasgele','ids'] as $alan): ?>
            <input type="hidden" name="<?= $alan ?>" value="<?= escH((string)($kaynak[$alan] ?? '')) ?>">
            <?php endforeach; ?>
            <?php foreach ($onizleme as $o): ?>
            <input type="hidden" name="sayim_miktar[<?= $o['id'] ?>]" value="<?= $o['yeni'] ?>">
            <input type="hidden" name="sayim_onceki[<?= $o['id'] ?>]" value="<?= $o['mevcut'] ?>">
            <?php endforeach; ?>
            <div class="form-check d-inline-block me-3">
                <input class="form-check-input" type="checkbox" name="fire_isle" id="fireIsle" value="1">
                <label class="form-check-label" for="fireIsle">Eksik çıkanları <strong>fire</strong> olarak kaydet <small class="text-muted">(maliyetli kayıp kaydı)</small></label>
            </div>
            <button type="submit" class="btn btn-success"
                    onclick="return confirm('<?= count($degisenler) ?> ürünün stoğu güncellenecek<?= $buyukFarklar ? ' (' . $buyukFarklar . ' büyük sapma dahil!)' : '' ?>. Onaylıyor musunuz?')">
                <i class="bi bi-check-circle"></i> Onayla ve Kaydet
            </button>
        </form>
        <a href="sayim.php" class="btn btn-outline-secondary">Vazgeç</a>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-info d-flex gap-2 align-items-start py-2">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div><strong>Yalnızca saydığınız ürünleri girin</strong> — boş bırakılan ürünlere dokunulmaz ("0" yazmak ise "raf boş" demektir).
        Barkod/seri no okutarak da sayabilirsiniz: her okutma +1 (Enter ile hızlı giriş, satırlar arası Enter ile geçiş).</div>
</div>

<!-- Kategori sayım turu -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between">
        <span><i class="bi bi-signpost-split text-primary"></i> Kategori Sayım Turu</span>
        <?php if ($sonTamSayim): ?><small class="text-muted">Son tam sayım: <?= tarih($sonTamSayim) ?></small><?php endif; ?>
    </div>
    <div class="card-body py-2 d-flex flex-wrap gap-2">
        <?php foreach ($kategoriler as $k): ?>
        <?php $son = $katSayimlar[$k['id']] ?? $sonTamSayim; ?>
        <a href="sayim.php?kategori_id=<?= $k['id'] ?>" class="btn btn-sm <?= $kategori_id == $k['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= escH($k['ad']) ?>
            <span class="badge <?= $son ? 'bg-success' : 'bg-danger' ?>"><?= $son ? tarih($son) : 'hiç sayılmadı' ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Filtre + barkod -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-lg-6">
                <form class="row g-2" method="get">
                    <div class="col-4">
                        <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ürün ara..." value="<?= escH($arama) ?>">
                    </div>
                    <div class="col-3">
                        <select name="kategori_id" class="form-select form-select-sm">
                            <option value="">Tüm Kategoriler</option>
                            <?php foreach ($kategoriler as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $kategori_id==$k['id']?'selected':'' ?>><?= escH($k['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-2">
                        <select name="marka" class="form-select form-select-sm">
                            <option value="">Marka</option>
                            <?php foreach ($markalar as $m): ?>
                            <option value="<?= escH($m) ?>" <?= $marka===$m?'selected':'' ?>><?= escH($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-2">
                        <select name="durum" class="form-select form-select-sm">
                            <option value="">Durum</option>
                            <option value="tesirde" <?= $durumF==='tesirde'?'selected':'' ?>>Teşhirde</option>
                            <option value="kritik" <?= $durumF==='kritik'?'selected':'' ?>>Kritik</option>
                        </select>
                    </div>
                    <div class="col-1">
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
            <div class="col-lg-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-success text-white"><i class="bi bi-upc-scan"></i></span>
                    <input type="text" id="sayimBarkod" class="form-control" placeholder="Barkod veya SERİ NO okutun (+1)" autocomplete="off">
                    <button type="button" class="btn btn-outline-success" onclick="BarcodeScanner.start(v => barkodSay(v))"><i class="bi bi-camera"></i></button>
                </div>
            </div>
            <div class="col-lg-2">
                <a href="sayim.php?<?= http_build_query(array_filter(['kategori_id' => $kategori_id, 'marka' => $marka, 'durum' => $durumF])) ?>&rasgele=10"
                   class="btn btn-sm btn-outline-info w-100" title="Rastgele 10 ürünle hızlı doğrulama"><i class="bi bi-shuffle"></i> Rastgele 10</a>
            </div>
        </div>
    </div>
</div>

<div id="taslakUyari" class="alert alert-warning py-2 d-none">
    <i class="bi bi-arrow-clockwise"></i> Yarım kalmış bir sayım taslağı bulundu (<span id="taslakZaman"></span>).
    <button type="button" class="btn btn-sm btn-warning ms-2" onclick="taslakYukle()">Devam Et</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="taslakTemizle(); document.getElementById('taslakUyari').classList.add('d-none')">Sil</button>
</div>

<form method="post" id="sayimForm" onsubmit="return sayimOnizleKontrol()">
    <?= csrfField() ?>
    <input type="hidden" name="aksiyon" value="onizle">
    <input type="hidden" name="seri_okunan" id="seriOkunanInput">
    <?php foreach (['kategori_id'=>$kategori_id, 'ara'=>$arama, 'marka'=>$marka, 'durum'=>$durumF, 'rasgele'=>$rasgele ?: '', 'ids'=>implode(',', $ids)] as $alan => $deger): ?>
    <input type="hidden" name="<?= $alan ?>" value="<?= escH((string)$deger) ?>">
    <?php endforeach; ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-list-check text-primary"></i> Sayım Listesi <small class="text-muted">(<?= escH($kapsamStr) ?>)</small></span>
            <input type="text" name="sayim_aciklama" class="form-control form-control-sm"
                   style="max-width:220px" placeholder="Sayım notu..." value="Stok sayımı <?= date('d.m.Y') ?>">
        </div>
        <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle" id="sayimTablo">
            <thead class="table-light">
                <tr>
                    <th>Kod</th><th>Ürün</th><th>Kategori</th>
                    <th class="text-center">Sistem</th>
                    <th class="text-center" style="width:190px">Sayılan</th>
                    <th class="text-center">Fark</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($urunler)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Ürün bulunamadı</td></tr>
            <?php else: ?>
            <?php foreach ($urunler as $u): ?>
            <tr class="sayim-satir" data-sistem="<?= $u['stok_adedi'] ?>" data-barkod="<?= escH($u['barkod'] ?? '') ?>" data-kod="<?= escH($u['kod']) ?>" data-id="<?= $u['id'] ?>">
                <td data-etiket="Kod"><small class="text-muted"><?= escH($u['kod']) ?></small></td>
                <td data-etiket="Ürün" class="fw-semibold"><?= escH($u['ad']) ?><?= $u['seri_no_takip'] ? ' <i class="bi bi-upc text-muted small" title="Seri takipli — seri no okutabilirsiniz"></i>' : '' ?></td>
                <td data-etiket="Kategori"><small class="text-muted"><?= escH($u['kategori'] ?? '—') ?></small></td>
                <td data-etiket="Sistem" class="text-center">
                    <span class="badge bg-<?= $u['stok_adedi'] <= 0 ? 'danger' : ($u['stok_adedi'] <= $u['min_stok'] ? 'warning' : 'success') ?>"><?= $u['stok_adedi'] ?></span>
                </td>
                <td data-etiket="Sayılan" class="text-center">
                    <div class="input-group input-group-sm justify-content-center" style="max-width:180px;margin:0 auto">
                        <input type="number" name="sayim_miktar[<?= $u['id'] ?>]"
                               class="form-control form-control-sm text-center sayim-input"
                               min="0" value="" placeholder="<?= $u['stok_adedi'] ?>"
                               oninput="farkHesapla(this); taslakKaydet()">
                        <button type="button" class="btn btn-outline-secondary" title="Raf boş: 0"
                                onclick="const i=this.previousElementSibling; i.value=0; farkHesapla(i); taslakKaydet();">0</button>
                    </div>
                </td>
                <td data-etiket="Fark" class="text-center fark-hucre fw-bold text-muted">sayılmadı</td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <div class="d-flex gap-2 align-items-center flex-wrap">
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-eye"></i> Önizle</button>
        <a href="index.php" class="btn btn-outline-secondary btn-lg">İptal</a>
        <span class="text-muted ms-2" id="degisiklikSayisi"></span>
    </div>
</form>

<script>
const SERILER = <?= json_encode($seriJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const TASLAK_ANAHTAR = 'regal_sayim_taslak_' + <?= json_encode((string)($_SERVER['QUERY_STRING'] ?? '')) ?>;
const seriOkunanlar = new Set();
const sayilanlar = new Set();

// ── Bip sesi (WebAudio) ──────────────────────────────────────
function bip(hata = false) {
    try {
        const ctx = bip._ctx = bip._ctx || new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator(), g = ctx.createGain();
        osc.frequency.value = hata ? 220 : 880;
        g.gain.value = 0.08;
        osc.connect(g); g.connect(ctx.destination);
        osc.start(); osc.stop(ctx.currentTime + (hata ? 0.28 : 0.12));
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate(hata ? [80, 40, 80] : 60);
}

function farkHesapla(input) {
    const satir  = input.closest('tr');
    const sistem = parseInt(satir.dataset.sistem) || 0;
    const hucre  = satir.querySelector('.fark-hucre');
    if (input.value.trim() === '') {
        hucre.textContent = 'sayılmadı';
        hucre.className = 'text-center fark-hucre fw-bold text-muted';
        satir.classList.remove('table-warning','table-danger','table-success');
    } else {
        const fark = (parseInt(input.value) || 0) - sistem;
        hucre.textContent = fark === 0 ? '✓ eşit' : (fark > 0 ? '+' : '') + fark;
        hucre.className = 'text-center fark-hucre fw-bold ' + (fark === 0 ? 'text-success' : (fark > 0 ? 'text-success' : 'text-danger'));
        satir.classList.remove('table-warning','table-danger','table-success');
        if (fark !== 0) satir.classList.add(fark > 0 ? 'table-success' : 'table-danger');
    }
    const sayilan = [...document.querySelectorAll('.sayim-input')].filter(i => i.value.trim() !== '').length;
    const degisen = document.querySelectorAll('.sayim-satir.table-success, .sayim-satir.table-danger').length;
    document.getElementById('degisiklikSayisi').textContent = sayilan > 0 ? `${sayilan} ürün sayıldı, ${degisen} üründe fark var` : '';
}

// ── Barkod / seri no ile sayım ───────────────────────────────
function barkodSay(deger) {
    deger = (deger || '').trim();
    if (!deger) return;
    let satir = null;
    // Önce seri no eşleşmesi (seri takipli ürünler)
    if (SERILER[deger] !== undefined) {
        if (seriOkunanlar.has(deger)) { bip(true); durumGoster('Bu seri no zaten okutuldu: ' + deger); return; }
        seriOkunanlar.add(deger);
        document.getElementById('seriOkunanInput').value = [...seriOkunanlar].join('\n');
        satir = document.querySelector('.sayim-satir[data-id="' + SERILER[deger] + '"]');
    } else {
        satir = [...document.querySelectorAll('.sayim-satir')].find(tr =>
            tr.dataset.barkod === deger || tr.dataset.kod === deger || tr.dataset.kod.toLowerCase() === deger.toLowerCase());
    }
    const input = document.getElementById('sayimBarkod');
    if (!satir) {
        bip(true);
        input.classList.add('is-invalid');
        setTimeout(() => input.classList.remove('is-invalid'), 1500);
        return;
    }
    const alan = satir.querySelector('.sayim-input');
    const uid = satir.dataset.id;
    if (!sayilanlar.has(uid) && alan.value.trim() === '') { alan.value = 1; }
    else { alan.value = (parseInt(alan.value) || 0) + 1; }
    sayilanlar.add(uid);
    farkHesapla(alan);
    taslakKaydet();
    satir.scrollIntoView({ block: 'center', behavior: 'smooth' });
    satir.style.outline = '2px solid #0d6efd';
    setTimeout(() => satir.style.outline = '', 800);
    input.value = '';
    bip(false);
}
function durumGoster(msg) {
    const input = document.getElementById('sayimBarkod');
    const eski = input.placeholder;
    input.placeholder = msg;
    setTimeout(() => input.placeholder = eski, 2000);
}

// ── Taslak (localStorage) ────────────────────────────────────
function taslakKaydet() {
    const veriler = {};
    document.querySelectorAll('.sayim-input').forEach(i => {
        if (i.value.trim() !== '') veriler[i.closest('tr').dataset.id] = i.value;
    });
    if (Object.keys(veriler).length === 0) { taslakTemizle(); return; }
    try {
        localStorage.setItem(TASLAK_ANAHTAR, JSON.stringify({
            zaman: new Date().toLocaleString('tr-TR'), veriler, seriler: [...seriOkunanlar]
        }));
    } catch (e) {}
}
function taslakYukle() {
    try {
        const t = JSON.parse(localStorage.getItem(TASLAK_ANAHTAR) || 'null');
        if (!t) return;
        Object.entries(t.veriler || {}).forEach(([id, deger]) => {
            const satir = document.querySelector('.sayim-satir[data-id="' + id + '"]');
            if (satir) {
                const alan = satir.querySelector('.sayim-input');
                alan.value = deger;
                sayilanlar.add(id);
                farkHesapla(alan);
            }
        });
        (t.seriler || []).forEach(s => seriOkunanlar.add(s));
        document.getElementById('seriOkunanInput').value = [...seriOkunanlar].join('\n');
        document.getElementById('taslakUyari').classList.add('d-none');
    } catch (e) {}
}
function taslakTemizle() { try { localStorage.removeItem(TASLAK_ANAHTAR); } catch (e) {} }

// Sayfa açılışında taslak kontrolü
(function () {
    try {
        const t = JSON.parse(localStorage.getItem(TASLAK_ANAHTAR) || 'null');
        if (t && Object.keys(t.veriler || {}).length) {
            document.getElementById('taslakZaman').textContent = t.zaman || '';
            document.getElementById('taslakUyari').classList.remove('d-none');
        }
    } catch (e) {}
})();

function sayimOnizleKontrol() {
    const sayilan = [...document.querySelectorAll('.sayim-input')].filter(i => i.value.trim() !== '').length;
    if (!sayilan) { alert('Hiçbir ürün sayılmadı. En az bir ürünün sayım değerini girin.'); return false; }
    document.getElementById('seriOkunanInput').value = [...seriOkunanlar].join('\n');
    return true;
}

// Enter: barkod alanında say, sayım girişinde sonraki satıra geç
document.getElementById('sayimBarkod').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); barkodSay(e.target.value); }
});
document.addEventListener('keydown', e => {
    if (e.key !== 'Enter' || !e.target.classList.contains('sayim-input')) return;
    e.preventDefault();
    const girisler = [...document.querySelectorAll('.sayim-input')];
    const sonraki = girisler[girisler.indexOf(e.target) + 1];
    if (sonraki) { sonraki.focus(); sonraki.closest('tr').scrollIntoView({ block: 'center' }); }
});
</script>
<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
