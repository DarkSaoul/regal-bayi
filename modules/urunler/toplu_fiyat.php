<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Toplu Fiyat Güncelleme';
$pdo = db();

// ── Son işlem sonucunun CSV'si (session'dan) ─────────────────
if (isset($_GET['sonuc_csv'])) {
    $sonuc = $_SESSION['toplu_fiyat_sonuc'] ?? null;
    if (!$sonuc) { flash('hata', 'İndirilecek sonuç bulunamadı.'); header('Location: toplu_fiyat.php'); exit; }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fiyat_guncelleme_' . date('Y-m-d_Hi') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kod','Ürün','Eski Alış','Yeni Alış','Eski Satış','Yeni Satış','Durum'], ';');
    foreach ($sonuc['satirlar'] as $r) {
        fputcsv($out, [csvHucre($r['kod']), csvHucre($r['ad']),
            number_format($r['eski_alis'], 2, ',', '.'), number_format($r['yeni_alis'], 2, ',', '.'),
            number_format($r['eski_satis'], 2, ',', '.'), number_format($r['yeni_satis'], 2, ',', '.'),
            csvHucre($r['neden'] ?: 'Güncellendi')], ';');
    }
    fclose($out); exit;
}

// ── Yardımcılar ───────────────────────────────────────────────
function psikolojikYuvarla(float $f, string $mod): float {
    if ($mod === '10')  return round($f / 10) * 10;
    if ($mod === '100') return round($f / 100) * 100;
    if ($mod === '99') {
        if ($f >= 100) return max(99, round($f / 100) * 100 - 1);
        if ($f >= 10)  return max(9, round($f / 10) * 10 - 1);
        return round($f, 2);
    }
    return round($f, 2);
}

// Form parametrelerini oku + doğrula (onizle ve uygula ortak)
function parametreOku(PDO $pdo): array {
    $p = [];
    $p['ids'] = array_values(array_filter(array_map('intval', explode(',', trim($_POST['ids'] ?? '')))));
    $p['kategori_id']  = (int)($_POST['kategori_id'] ?? 0) ?: null;
    $p['altlar_dahil'] = !empty($_POST['altlar_dahil']);
    $p['marka']        = trim($_POST['marka'] ?? '');
    $p['fiyat_min']    = ($_POST['fiyat_min'] ?? '') !== '' ? max(0, (float)$_POST['fiyat_min']) : null;
    $p['fiyat_max']    = ($_POST['fiyat_max'] ?? '') !== '' ? max(0, (float)$_POST['fiyat_max']) : null;
    $p['stok_durum']   = in_array($_POST['stok_durum'] ?? '', ['var','yok'], true) ? $_POST['stok_durum'] : '';
    $p['yontem']       = in_array($_POST['yontem'] ?? '', ['yuzde','sabit','marj','maliyet'], true) ? $_POST['yontem'] : 'yuzde';
    $p['hedef']        = in_array($_POST['hedef'] ?? '', ['satis','alis','ikisi'], true) ? $_POST['hedef'] : 'satis';
    if ($p['yontem'] === 'marj')    $p['hedef'] = 'satis';   // marj yöntemi satış fiyatını hesaplar
    if ($p['yontem'] === 'maliyet') $p['hedef'] = 'alis';    // maliyet yöntemi alış fiyatını günceller
    $p['yon']          = ($_POST['yon'] ?? 'arttir') === 'azalt' ? 'azalt' : 'arttir';
    $p['deger']        = (float)($_POST['deger'] ?? 0);
    $p['yuvarla']      = in_array($_POST['yuvarla'] ?? '', ['99','10','100'], true) ? $_POST['yuvarla'] : 'yok';
    $p['zarar_koruma'] = !empty($_POST['zarar_koruma']);
    $p['taban']        = ($_POST['taban'] ?? '') !== '' ? max(0, (float)$_POST['taban']) : null;
    $p['tavan']        = ($_POST['tavan'] ?? '') !== '' ? max(0, (float)$_POST['tavan']) : null;

    if (in_array($p['yontem'], ['yuzde','sabit'], true) && $p['deger'] <= 0)
        throw new Exception('Değer 0\'dan büyük olmalıdır.');
    if ($p['yontem'] === 'yuzde' && $p['yon'] === 'azalt' && $p['deger'] >= 100)
        throw new Exception('%100 ve üzeri azaltma tüm fiyatları sıfırlar; izin verilmiyor.');
    if ($p['taban'] !== null && $p['tavan'] !== null && $p['taban'] > $p['tavan'])
        throw new Exception('Taban fiyat tavandan büyük olamaz.');
    return $p;
}

// Kapsamdaki ürünleri getir
function kapsamUrunleri(PDO $pdo, array $p): array {
    $where = "u.aktif=1"; $params = [];
    if ($p['ids']) {
        $where .= " AND u.id IN (" . implode(',', array_fill(0, count($p['ids']), '?')) . ")";
        $params = $p['ids'];
    } else {
        if ($p['kategori_id']) {
            if ($p['altlar_dahil']) { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params[] = $p['kategori_id']; $params[] = $p['kategori_id']; }
            else                    { $where .= " AND u.kategori_id=?"; $params[] = $p['kategori_id']; }
        }
        if ($p['marka'])              { $where .= " AND u.marka=?"; $params[] = $p['marka']; }
        if ($p['fiyat_min'] !== null) { $where .= " AND u.satis_fiyati >= ?"; $params[] = $p['fiyat_min']; }
        if ($p['fiyat_max'] !== null) { $where .= " AND u.satis_fiyati <= ?"; $params[] = $p['fiyat_max']; }
        if ($p['stok_durum'] === 'var') $where .= " AND u.stok_adedi > 0";
        if ($p['stok_durum'] === 'yok') $where .= " AND u.stok_adedi <= 0";
    }
    $stmt = $pdo->prepare("SELECT u.id, u.kod, u.ad, u.stok_adedi, u.alis_fiyati, u.satis_fiyati, k.hedef_marj
        FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id WHERE $where ORDER BY u.ad");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Tek ürünün yeni fiyatını hesapla → satır (değişim yoksa/atlandıysa neden dolu)
function fiyatlariHesapla(array $urunler, array $p, array $maliyetler): array {
    $satirlar = [];
    foreach ($urunler as $u) {
        $a = (float)$u['alis_fiyati']; $s = (float)$u['satis_fiyati'];
        $yeniA = $a; $yeniS = $s; $neden = null;

        if ($p['yontem'] === 'yuzde' || $p['yontem'] === 'sabit') {
            $hesapla = function(float $f) use ($p): float {
                $fark = $p['yontem'] === 'yuzde' ? $f * $p['deger'] / 100 : $p['deger'];
                return max(0, $p['yon'] === 'arttir' ? $f + $fark : $f - $fark);
            };
            if (in_array($p['hedef'], ['satis','ikisi'], true)) $yeniS = $hesapla($s);
            if (in_array($p['hedef'], ['alis','ikisi'], true))  $yeniA = $hesapla($a);
        } elseif ($p['yontem'] === 'marj') {
            $m = $p['deger'] > 0 ? $p['deger'] : ($u['hedef_marj'] !== null ? (float)$u['hedef_marj'] : null);
            if ($m === null)   $neden = 'Kategori hedef marjı tanımsız';
            elseif ($a <= 0)   $neden = 'Alış fiyatı yok';
            else               $yeniS = $a * (1 + $m / 100);
        } elseif ($p['yontem'] === 'maliyet') {
            $sm = $maliyetler[$u['id']] ?? null;
            if ($sm === null) $neden = 'Maliyetli stok girişi yok';
            else              $yeniA = (float)$sm;
        }

        if ($neden === null) {
            // Yuvarlama + taban/tavan yalnızca değişen fiyatlara uygulanır
            if ($yeniS !== $s) {
                $yeniS = psikolojikYuvarla($yeniS, $p['yuvarla']);
                if ($p['taban'] !== null) $yeniS = max($p['taban'], $yeniS);
                if ($p['tavan'] !== null) $yeniS = min($p['tavan'], $yeniS);
            }
            if ($yeniA !== $a) $yeniA = round($yeniA, 2);
            $yeniS = round(max(0, $yeniS), 2); $yeniA = round(max(0, $yeniA), 2);
            if ($p['zarar_koruma'] && $yeniA > 0 && $yeniS > 0 && $yeniS < $yeniA)
                $neden = 'Zararına satış olurdu (satış < alış)';
            elseif ($yeniA === round($a, 2) && $yeniS === round($s, 2))
                $neden = 'Değişiklik yok';
        }

        $satirlar[] = [
            'id' => (int)$u['id'], 'kod' => $u['kod'], 'ad' => $u['ad'], 'stok' => (int)$u['stok_adedi'],
            'eski_alis' => $a, 'yeni_alis' => $neden ? $a : $yeniA,
            'eski_satis' => $s, 'yeni_satis' => $neden ? $s : $yeniS,
            'neden' => $neden,
        ];
    }
    return $satirlar;
}

function ozetle(array $satirlar): array {
    $o = ['toplam'=>count($satirlar), 'degisen'=>0, 'atlanan'=>0,
          'deger_once'=>0.0, 'deger_sonra'=>0.0, 'marj_once'=>[], 'marj_sonra'=>[]];
    foreach ($satirlar as $r) {
        $r['neden'] ? $o['atlanan']++ : $o['degisen']++;
        $o['deger_once']  += $r['stok'] * $r['eski_satis'];
        $o['deger_sonra'] += $r['stok'] * $r['yeni_satis'];
        if ($r['eski_alis'] > 0 && $r['eski_satis'] > 0) $o['marj_once'][]  = ($r['eski_satis'] - $r['eski_alis']) / $r['eski_alis'] * 100;
        if ($r['yeni_alis'] > 0 && $r['yeni_satis'] > 0) $o['marj_sonra'][] = ($r['yeni_satis'] - $r['yeni_alis']) / $r['yeni_alis'] * 100;
    }
    $o['marj_once']  = $o['marj_once']  ? round(array_sum($o['marj_once'])  / count($o['marj_once']), 1)  : null;
    $o['marj_sonra'] = $o['marj_sonra'] ? round(array_sum($o['marj_sonra']) / count($o['marj_sonra']), 1) : null;
    return $o;
}

function sonMaliyetler(PDO $pdo): array {
    return $pdo->query("SELECT h.urun_id, h.birim_maliyet FROM stok_hareketleri h
        JOIN (SELECT urun_id, MAX(id) AS mid FROM stok_hareketleri
              WHERE hareket_tipi='giris' AND birim_maliyet IS NOT NULL GROUP BY urun_id) x ON x.mid=h.id")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

function kapsamMetni(array $p, PDO $pdo): string {
    if ($p['ids']) return count($p['ids']) . ' seçili ürün';
    $parca = [];
    if ($p['kategori_id']) {
        $k = $pdo->prepare("SELECT ad FROM kategoriler WHERE id=?"); $k->execute([$p['kategori_id']]);
        $parca[] = $k->fetchColumn() . ($p['altlar_dahil'] ? ' (altlar dahil)' : '');
    } else $parca[] = 'Tüm ürünler';
    if ($p['marka']) $parca[] = 'marka: ' . $p['marka'];
    if ($p['fiyat_min'] !== null || $p['fiyat_max'] !== null)
        $parca[] = 'fiyat: ' . ($p['fiyat_min'] ?? 0) . '–' . ($p['fiyat_max'] ?? '∞');
    if ($p['stok_durum']) $parca[] = $p['stok_durum'] === 'var' ? 'stokta olanlar' : 'stokta olmayanlar';
    return implode(', ', $parca);
}

function yontemMetni(array $p): string {
    switch ($p['yontem']) {
        case 'yuzde':   return ($p['yon'] === 'arttir' ? '+' : '-') . '%' . $p['deger'] . ' (' . $p['hedef'] . ')';
        case 'sabit':   return ($p['yon'] === 'arttir' ? '+' : '-') . $p['deger'] . ' ₺ (' . $p['hedef'] . ')';
        case 'marj':    return 'hedef marja eşitle' . ($p['deger'] > 0 ? ' %' . $p['deger'] : ' (kategori marjı)');
        case 'maliyet': return 'alışı son maliyete eşitle';
    }
    return '';
}

// ── POST işlemleri ────────────────────────────────────────────
$onizleme = null; $onizlemeOzet = null; $parametreler = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';
    try {
        if ($aksiyon === 'geri_al') {
            $grup = $_POST['grup'] ?? '';
            if (!preg_match('/^tf_[0-9]{14}_[a-z0-9]{4}$/', $grup)) throw new Exception('Geçersiz işlem grubu.');
            $var = $pdo->prepare("SELECT COUNT(*) FROM fiyat_gecmisi WHERE kaynak='geri_alma' AND islem_grubu=?");
            $var->execute(['geri:' . $grup]);
            if ($var->fetchColumn() > 0) throw new Exception('Bu güncelleme zaten geri alınmış.');
            $kayitlar = $pdo->prepare("SELECT * FROM fiyat_gecmisi WHERE islem_grubu=? AND kaynak='toplu_fiyat' ORDER BY id");
            $kayitlar->execute([$grup]);
            $kayitlar = $kayitlar->fetchAll();
            if (!$kayitlar) throw new Exception('Geri alınacak kayıt bulunamadı.');
            $geri = 0; $atlanan = 0;
            $pdo->beginTransaction();
            foreach ($kayitlar as $k) {
                $g = $pdo->prepare("SELECT alis_fiyati, satis_fiyati FROM urunler WHERE id=? AND aktif=1 FOR UPDATE");
                $g->execute([$k['urun_id']]);
                $guncel = $g->fetch();
                // Fiyat, toplu işlemden sonra elle değiştiyse dokunma
                if (!$guncel || round($guncel['alis_fiyati'],2) != round($k['yeni_alis'],2)
                             || round($guncel['satis_fiyati'],2) != round($k['yeni_satis'],2)) { $atlanan++; continue; }
                $pdo->prepare("UPDATE urunler SET alis_fiyati=?, satis_fiyati=? WHERE id=?")
                    ->execute([$k['eski_alis'], $k['eski_satis'], $k['urun_id']]);
                fiyatGecmisiKaydet((int)$k['urun_id'], $k['yeni_alis'], $k['eski_alis'], $k['yeni_satis'], $k['eski_satis'], 'geri_alma', 'geri:' . $grup);
                $geri++;
            }
            $pdo->commit();
            logla('fiyat_geri_al', 'urunler', 0, "Grup $grup: $geri geri alındı, $atlanan atlandı");
            flash($geri ? 'basari' : 'hata', "$geri ürünün fiyatı geri alındı." . ($atlanan ? " $atlanan ürün atlandı (fiyatı sonradan değişmiş)." : ''));
            header('Location: toplu_fiyat.php'); exit;
        }

        if ($aksiyon === 'onizle' || $aksiyon === 'uygula') {
            $p = parametreOku($pdo);
            $urunler = kapsamUrunleri($pdo, $p);
            if (!$urunler) throw new Exception('Seçilen kapsamda ürün bulunamadı — filtreleri kontrol edin.');
            $maliyetler = $p['yontem'] === 'maliyet' ? sonMaliyetler($pdo) : [];
            $satirlar = fiyatlariHesapla($urunler, $p, $maliyetler);
            $ozet = ozetle($satirlar);

            if ($aksiyon === 'onizle') {
                $onizleme = $satirlar; $onizlemeOzet = $ozet; $parametreler = $p;
            } else {
                if ($ozet['degisen'] === 0) throw new Exception('Hiçbir ürünün fiyatı değişmiyor; uygulanacak bir şey yok.');
                $grup = 'tf_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
                $pdo->beginTransaction();
                $guncelle = $pdo->prepare("UPDATE urunler SET alis_fiyati=?, satis_fiyati=? WHERE id=?");
                foreach ($satirlar as $r) {
                    if ($r['neden']) continue;
                    $guncelle->execute([$r['yeni_alis'], $r['yeni_satis'], $r['id']]);
                    fiyatGecmisiKaydet($r['id'], $r['eski_alis'], $r['yeni_alis'], $r['eski_satis'], $r['yeni_satis'], 'toplu_fiyat', $grup);
                }
                $pdo->commit();
                logla('fiyat_guncelle', 'urunler', $p['kategori_id'] ?? 0,
                    kapsamMetni($p, $pdo) . ' | ' . yontemMetni($p) . " | {$ozet['degisen']} ürün | grup: $grup");
                $_SESSION['toplu_fiyat_sonuc'] = ['grup' => $grup, 'zaman' => date('d.m.Y H:i'),
                    'kapsam' => kapsamMetni($p, $pdo), 'yontem' => yontemMetni($p), 'ozet' => $ozet, 'satirlar' => $satirlar];
                header('Location: toplu_fiyat.php?sonuc=1'); exit;
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('hata', $e->getMessage());
        if ($aksiyon !== 'onizle' && $aksiyon !== 'uygula') { header('Location: toplu_fiyat.php'); exit; }
    }
}

// ── Görünüm verileri ──────────────────────────────────────────
$sonuc = null;
if (isset($_GET['sonuc'])) $sonuc = $_SESSION['toplu_fiyat_sonuc'] ?? null;

$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id IS NOT NULL, sira, ad")->fetchAll();
$markalar = $pdo->query("SELECT DISTINCT marka FROM urunler WHERE aktif=1 AND marka!='' ORDER BY marka")->fetchAll(PDO::FETCH_COLUMN);
$onSecili = (int)($_GET['kategori_id'] ?? 0);
$seciliIds = array_values(array_filter(array_map('intval', explode(',', trim($_GET['ids'] ?? '')))));

// Kategori bazlı ürün sayıları (doğrudan + altlar dahil)
$dogrudan = $pdo->query("SELECT kategori_id, COUNT(*) FROM urunler WHERE aktif=1 GROUP BY kategori_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$birlesik = [];
foreach ($kategoriler as $k) {
    $t = (int)($dogrudan[$k['id']] ?? 0);
    if ($k['ust_id'] === null)
        foreach ($kategoriler as $alt) if ($alt['ust_id'] == $k['id']) $t += (int)($dogrudan[$alt['id']] ?? 0);
    $birlesik[$k['id']] = $t;
}
$tumUrunSayisi = $pdo->query("SELECT COUNT(*) FROM urunler WHERE aktif=1")->fetchColumn();

// Son toplu güncellemeler (geri alma için)
$gecmis = $pdo->query("SELECT f.islem_grubu, COUNT(*) AS adet, MIN(f.created_at) AS zaman, MAX(ku.ad_soyad) AS kullanici
    FROM fiyat_gecmisi f LEFT JOIN kullanicilar ku ON f.kullanici_id=ku.id
    WHERE f.kaynak='toplu_fiyat' AND f.islem_grubu IS NOT NULL
    GROUP BY f.islem_grubu ORDER BY zaman DESC LIMIT 10")->fetchAll();
$geriAlinanlar = $pdo->query("SELECT DISTINCT SUBSTRING(islem_grubu, 6) FROM fiyat_gecmisi
    WHERE kaynak='geri_alma' AND islem_grubu LIKE 'geri:%'")->fetchAll(PDO::FETCH_COLUMN);

$kurlar = tcmbKurlari();
$f = fn($k) => $_POST[$k] ?? ''; // sticky form değeri

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-percent text-primary"></i> Toplu Fiyat Güncelleme</h4>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Ürünler</a>
</div>

<?php if ($sonuc): ?>
<!-- ═══ SONUÇ RAPORU ═══ -->
<div class="card shadow-sm mb-3 border-success">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-check-circle text-success"></i> Güncelleme Tamamlandı — <?= escH($sonuc['zaman']) ?></div>
    <div class="card-body">
        <p class="mb-2"><strong>Kapsam:</strong> <?= escH($sonuc['kapsam']) ?> · <strong>Yöntem:</strong> <?= escH($sonuc['yontem']) ?></p>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Güncellenen</div><div class="fw-bold text-success"><?= $sonuc['ozet']['degisen'] ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Atlanan</div><div class="fw-bold text-warning"><?= $sonuc['ozet']['atlanan'] ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Envanter Değeri (Satış)</div><div class="fw-bold"><?= para($sonuc['ozet']['deger_once']) ?> → <?= para($sonuc['ozet']['deger_sonra']) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Ortalama Marj</div><div class="fw-bold">%<?= $sonuc['ozet']['marj_once'] ?? '-' ?> → %<?= $sonuc['ozet']['marj_sonra'] ?? '-' ?></div></div></div>
        </div>
        <div class="d-flex gap-2">
            <a href="?sonuc_csv=1" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> Sonuç CSV'sini İndir</a>
            <form method="post" onsubmit="return confirm('Bu güncelleme geri alınacak (fiyatı sonradan değişen ürünler atlanır). Emin misiniz?')">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="geri_al">
                <input type="hidden" name="grup" value="<?= escH($sonuc['grup']) ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise"></i> Bu Güncellemeyi Geri Al</button>
            </form>
            <a href="toplu_fiyat.php" class="btn btn-sm btn-outline-secondary">Yeni Güncelleme</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($onizleme !== null): ?>
<!-- ═══ ÖNİZLEME ═══ -->
<div class="card shadow-sm mb-3 border-primary">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-eye text-primary"></i> Önizleme — henüz hiçbir şey değişmedi</div>
    <div class="card-body">
        <p class="mb-2"><strong>Kapsam:</strong> <?= escH(kapsamMetni($parametreler, $pdo)) ?> · <strong>Yöntem:</strong> <?= escH(yontemMetni($parametreler)) ?></p>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Güncellenecek</div><div class="fw-bold text-success"><?= $onizlemeOzet['degisen'] ?> ürün</div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Atlanacak</div><div class="fw-bold text-warning"><?= $onizlemeOzet['atlanan'] ?> ürün</div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Envanter Değeri (Satış)</div><div class="fw-bold"><?= para($onizlemeOzet['deger_once']) ?> → <?= para($onizlemeOzet['deger_sonra']) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Ortalama Marj</div><div class="fw-bold">%<?= $onizlemeOzet['marj_once'] ?? '-' ?> → %<?= $onizlemeOzet['marj_sonra'] ?? '-' ?></div></div></div>
        </div>
        <div class="table-responsive mb-3" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0">
                <thead class="sticky-top bg-white"><tr><th>Kod</th><th>Ürün</th>
                    <th class="text-end">Alış (Eski→Yeni)</th><th class="text-end">Satış (Eski→Yeni)</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($onizleme, 0, 150) as $r): ?>
                <tr class="<?= $r['neden'] ? 'table-warning' : '' ?>">
                    <td><code><?= escH($r['kod']) ?></code></td>
                    <td><?= escH($r['ad']) ?></td>
                    <td class="text-end"><?= para($r['eski_alis']) ?><?= $r['yeni_alis'] != $r['eski_alis'] ? ' → <strong>' . para($r['yeni_alis']) . '</strong>' : '' ?></td>
                    <td class="text-end"><?= para($r['eski_satis']) ?><?= $r['yeni_satis'] != $r['eski_satis'] ? ' → <strong class="text-primary">' . para($r['yeni_satis']) . '</strong>' : '' ?></td>
                    <td class="small"><?= $r['neden'] ? '<span class="text-warning">' . escH($r['neden']) . '</span>' : '<span class="text-success">Güncellenecek</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($onizleme) > 150): ?>
                <tr><td colspan="5" class="text-center text-muted">… ve <?= count($onizleme) - 150 ?> ürün daha</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="d-inline" onsubmit="this.querySelector('button').disabled=true">
            <?= csrfField() ?>
            <input type="hidden" name="aksiyon" value="uygula">
            <?php foreach (['ids','kategori_id','marka','fiyat_min','fiyat_max','stok_durum','yontem','hedef','yon','deger','yuvarla','taban','tavan'] as $alan): ?>
            <input type="hidden" name="<?= $alan ?>" value="<?= escH($_POST[$alan] ?? '') ?>">
            <?php endforeach; ?>
            <?php if (!empty($_POST['altlar_dahil'])): ?><input type="hidden" name="altlar_dahil" value="1"><?php endif; ?>
            <?php if (!empty($_POST['zarar_koruma'])): ?><input type="hidden" name="zarar_koruma" value="1"><?php endif; ?>
            <button type="submit" class="btn btn-success"
                    onclick="return confirm('<?= $onizlemeOzet['degisen'] ?> ürünün fiyatı güncellenecek. Onaylıyor musunuz?')">
                <i class="bi bi-check-circle"></i> Onayla ve Uygula (<?= $onizlemeOzet['degisen'] ?> ürün)
            </button>
        </form>
        <a href="toplu_fiyat.php" class="btn btn-outline-secondary">Vazgeç</a>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders text-primary"></i> Güncelleme Ayarları</div>
            <div class="card-body">
            <form method="post" id="fiyatForm" onsubmit="document.getElementById('onizleBtn').disabled=true">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="onizle">
                <input type="hidden" name="ids" id="idsInput" value="<?= escH(implode(',', $seciliIds)) ?>">

                <?php if ($seciliIds): ?>
                <div class="alert alert-info py-2 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-check2-square"></i> Ürün listesinden <strong><?= count($seciliIds) ?> seçili ürün</strong> ile çalışılıyor — aşağıdaki kapsam filtreleri yok sayılır.</span>
                    <a href="toplu_fiyat.php" class="btn btn-sm btn-outline-secondary">Seçimi Temizle</a>
                </div>
                <?php endif; ?>

                <fieldset <?= $seciliIds ? 'disabled' : '' ?>>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Kategori</label>
                        <select name="kategori_id" class="form-select form-select-sm">
                            <option value="">Tüm Ürünler (<?= $tumUrunSayisi ?>)</option>
                            <?php foreach ($kategoriler as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= ($f('kategori_id') ?: $onSecili) == $k['id'] ? 'selected' : '' ?>>
                                <?= $k['ust_id'] ? '↳ ' : '' ?><?= escH($k['ad']) ?>
                                (<?= $dogrudan[$k['id']] ?? 0 ?><?= !$k['ust_id'] && $birlesik[$k['id']] != ($dogrudan[$k['id']] ?? 0) ? ', altlarla ' . $birlesik[$k['id']] : '' ?> ürün)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" name="altlar_dahil" id="altlarDahil" value="1"
                                   <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? (!empty($_POST['altlar_dahil']) ? 'checked' : '') : 'checked' ?>>
                            <label class="form-check-label small" for="altlarDahil">Alt kategoriler dahil</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Marka</label>
                        <select name="marka" class="form-select form-select-sm">
                            <option value="">Tümü</option>
                            <?php foreach ($markalar as $m): ?>
                            <option value="<?= escH($m) ?>" <?= $f('marka') === $m ? 'selected' : '' ?>><?= escH($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Satış Fiyatı Aralığı (₺)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="fiyat_min" class="form-control" placeholder="Min" step="0.01" min="0" value="<?= escH($f('fiyat_min')) ?>">
                            <span class="input-group-text">—</span>
                            <input type="number" name="fiyat_max" class="form-control" placeholder="Max" step="0.01" min="0" value="<?= escH($f('fiyat_max')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Stok Durumu</label>
                        <select name="stok_durum" class="form-select form-select-sm">
                            <option value="">Tümü</option>
                            <option value="var" <?= $f('stok_durum') === 'var' ? 'selected' : '' ?>>Yalnızca stokta olanlar</option>
                            <option value="yok" <?= $f('stok_durum') === 'yok' ? 'selected' : '' ?>>Yalnızca stokta olmayanlar</option>
                        </select>
                    </div>
                </div>
                </fieldset>
                <hr class="my-3">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Yöntem</label>
                    <select name="yontem" id="yontemSec" class="form-select" onchange="yontemAlanlari()">
                        <option value="yuzde" <?= $f('yontem') === 'yuzde' || !$f('yontem') ? 'selected' : '' ?>>Yüzde artır/azalt (%)</option>
                        <option value="sabit" <?= $f('yontem') === 'sabit' ? 'selected' : '' ?>>Sabit tutar artır/azalt (₺)</option>
                        <option value="marj" <?= $f('yontem') === 'marj' ? 'selected' : '' ?>>Hedef marja eşitle (satış = alış + marj)</option>
                        <option value="maliyet" <?= $f('yontem') === 'maliyet' ? 'selected' : '' ?>>Alışı son gerçek maliyete eşitle</option>
                    </select>
                    <div class="form-text" id="yontemAciklama"></div>
                </div>

                <div class="row g-2 mb-3" id="hedefYonSatir">
                    <div class="col-md-6" id="hedefAlan">
                        <label class="form-label fw-semibold mb-1">Hangi Fiyat?</label>
                        <select name="hedef" class="form-select form-select-sm">
                            <option value="satis" <?= $f('hedef') === 'satis' || !$f('hedef') ? 'selected' : '' ?>>Yalnızca Satış</option>
                            <option value="alis" <?= $f('hedef') === 'alis' ? 'selected' : '' ?>>Yalnızca Alış</option>
                            <option value="ikisi" <?= $f('hedef') === 'ikisi' ? 'selected' : '' ?>>Her İkisi</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="yonAlan">
                        <label class="form-label fw-semibold mb-1">İşlem</label>
                        <div class="d-flex gap-3 pt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="yon" id="y1" value="arttir" <?= $f('yon') !== 'azalt' ? 'checked' : '' ?>>
                                <label class="form-check-label text-success fw-semibold" for="y1">↑ Artır</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="yon" id="y2" value="azalt" <?= $f('yon') === 'azalt' ? 'checked' : '' ?>>
                                <label class="form-check-label text-danger fw-semibold" for="y2">↓ Azalt</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3" id="degerAlanKapsayici">
                    <label class="form-label fw-semibold mb-1" id="degerEtiket">Değer</label>
                    <div class="input-group">
                        <input type="number" name="deger" class="form-control" step="0.01" min="0" placeholder="Örn: 10" id="degerInput" value="<?= escH($f('deger')) ?>">
                        <span class="input-group-text" id="birimLabel">%</span>
                    </div>
                    <div class="form-text" id="ornekMetin">Örn: 1.000 ₺ ürün → 1.100,00 ₺</div>
                </div>

                <?php if ($kurlar): ?>
                <div class="border rounded p-2 mb-3 bg-light" id="kurKutusu">
                    <div class="small fw-semibold mb-1"><i class="bi bi-currency-exchange"></i> Kur farkı hesaplayıcı
                        <span class="text-muted fw-normal">(bugün: <?php foreach ($kurlar as $kod => $kur): ?><?= $kod ?> <?= number_format($kur['satis'], 2, ',', '.') ?> · <?php endforeach; ?>TCMB)</span>
                    </div>
                    <div class="input-group input-group-sm" style="max-width:420px">
                        <span class="input-group-text">Eski kur</span>
                        <input type="number" step="0.0001" class="form-control" id="eskiKur" placeholder="örn: <?= number_format((reset($kurlar)['satis'] ?: 40) * 0.95, 2, '.', '') ?>">
                        <span class="input-group-text">Yeni</span>
                        <input type="number" step="0.0001" class="form-control" id="yeniKur" value="<?= reset($kurlar)['satis'] ?>">
                        <button type="button" class="btn btn-outline-primary" onclick="kurFarkiUygula()">→ % değere yaz</button>
                    </div>
                    <div class="form-text">Alışını dövizle yaptığınız ürünlere kur artışı kadar zam için: eski kuru girin, fark yüzde olarak değer alanına yazılır.</div>
                </div>
                <?php endif; ?>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold mb-1">Yuvarlama</label>
                        <select name="yuvarla" class="form-select form-select-sm">
                            <option value="">Yok</option>
                            <option value="99" <?= $f('yuvarla') === '99' ? 'selected' : '' ?>>Psikolojik (…99)</option>
                            <option value="10" <?= $f('yuvarla') === '10' ? 'selected' : '' ?>>10'un katına</option>
                            <option value="100" <?= $f('yuvarla') === '100' ? 'selected' : '' ?>>100'ün katına</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold mb-1">Taban Fiyat (₺)</label>
                        <input type="number" name="taban" class="form-control form-control-sm" step="0.01" min="0" placeholder="Yok" value="<?= escH($f('taban')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold mb-1">Tavan Fiyat (₺)</label>
                        <input type="number" name="tavan" class="form-control form-control-sm" step="0.01" min="0" placeholder="Yok" value="<?= escH($f('tavan')) ?>">
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="zarar_koruma" id="zararKoruma" value="1"
                           <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? (!empty($_POST['zarar_koruma']) ? 'checked' : '') : 'checked' ?>>
                    <label class="form-check-label" for="zararKoruma">
                        <strong>Zarar koruması:</strong> sonuçta satış fiyatı alışın altına inecek ürünleri atla
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-lg" id="onizleBtn">
                    <i class="bi bi-eye"></i> Önizle
                </button>
                <div class="form-text mt-1">Önce etkilenecek ürünler ve yeni fiyatlar gösterilir; onaydan önce hiçbir şey değişmez.</div>
            </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <!-- Son güncellemeler -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history text-primary"></i> Son Toplu Güncellemeler</div>
            <?php if (!$gecmis): ?>
            <div class="card-body py-2 small text-muted">Henüz gruplu toplu güncelleme yapılmadı.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($gecmis as $g): ?>
                <?php $geriAlindi = in_array($g['islem_grubu'], $geriAlinanlar, true); ?>
                <li class="list-group-item py-2 d-flex justify-content-between align-items-center small">
                    <span>
                        <strong><?= tarihSaat($g['zaman']) ?></strong> — <?= $g['adet'] ?> ürün
                        <?= $g['kullanici'] ? '· ' . escH($g['kullanici']) : '' ?>
                        <?= $geriAlindi ? '<span class="badge bg-secondary">Geri alındı</span>' : '' ?>
                    </span>
                    <?php if (!$geriAlindi): ?>
                    <form method="post" onsubmit="return confirm('<?= $g['adet'] ?> ürünlük güncelleme geri alınacak (sonradan elle değişenler atlanır). Emin misiniz?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="aksiyon" value="geri_al">
                        <input type="hidden" name="grup" value="<?= escH($g['islem_grubu']) ?>">
                        <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-arrow-counterclockwise"></i> Geri Al</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- Bilgi kartı -->
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary"></i> Nasıl Çalışır?</div>
            <div class="card-body">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item"><i class="bi bi-1-circle text-primary me-2"></i>
                        Kapsamı ve yöntemi seçip <strong>Önizle</strong>'ye basın — etkilenecek her ürünün eski→yeni fiyatı listelenir.</li>
                    <li class="list-group-item"><i class="bi bi-2-circle text-primary me-2"></i>
                        <strong>Onayla ve Uygula</strong> ile fiyatlar tek seferde (transaction içinde) güncellenir; yarıda kalma riski yoktur.</li>
                    <li class="list-group-item"><i class="bi bi-arrow-counterclockwise text-danger me-2"></i>
                        Her güncelleme gruplanır ve <strong>geri alınabilir</strong>; sonradan elle değiştirilen ürünlere dokunulmaz.</li>
                    <li class="list-group-item"><i class="bi bi-tag text-success me-2"></i>
                        <strong>Hedef marja eşitle:</strong> değer boş bırakılırsa her ürünün kategorisindeki hedef marj kullanılır.</li>
                    <li class="list-group-item"><i class="bi bi-truck text-warning me-2"></i>
                        <strong>Son maliyete eşitle:</strong> alış fiyatını son maliyetli stok girişindeki birim maliyete çeker.</li>
                    <li class="list-group-item"><i class="bi bi-exclamation-triangle text-warning me-2"></i>
                        Tüm fiyatlar <strong>KDV dahil</strong> kabul edilir; sistem satış fiyatını KDV dahil kullanır.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function yontemAlanlari() {
    const y = document.getElementById('yontemSec').value;
    document.getElementById('hedefAlan').style.display = (y === 'yuzde' || y === 'sabit') ? '' : 'none';
    document.getElementById('yonAlan').style.display   = (y === 'yuzde' || y === 'sabit') ? '' : 'none';
    document.getElementById('degerAlanKapsayici').style.display = (y === 'maliyet') ? 'none' : '';
    document.getElementById('birimLabel').textContent = (y === 'sabit') ? '₺' : '%';
    document.getElementById('degerInput').placeholder = (y === 'marj') ? 'Boş = kategori hedef marjı' : 'Örn: 10';
    const aciklamalar = {
        yuzde: 'Seçili fiyatlar girilen yüzde kadar artırılır/azaltılır.',
        sabit: 'Seçili fiyatlara girilen tutar eklenir/çıkarılır.',
        marj: 'Satış fiyatı = alış fiyatı + marj. Değer boşsa ürünün kategorisindeki hedef marj kullanılır.',
        maliyet: 'Alış fiyatı, ürünün son maliyetli stok girişindeki birim maliyete eşitlenir. Maliyet kaydı olmayanlar atlanır.'
    };
    document.getElementById('yontemAciklama').textContent = aciklamalar[y] || '';
    guncelleOrnek();
}
function guncelleOrnek() {
    const y = document.getElementById('yontemSec').value;
    const el = document.getElementById('ornekMetin');
    const deger = parseFloat(document.getElementById('degerInput').value) || 0;
    const yon = document.querySelector('input[name="yon"]:checked')?.value || 'arttir';
    if (y === 'yuzde' || y === 'sabit') {
        let sonuc = y === 'yuzde' ? (yon === 'arttir' ? 1000 * (1 + deger / 100) : 1000 * (1 - deger / 100))
                                  : (yon === 'arttir' ? 1000 + deger : 1000 - deger);
        sonuc = Math.max(0, sonuc);
        el.textContent = 'Örn: 1.000 ₺ ürün → ' + sonuc.toFixed(2).replace('.', ',') + ' ₺';
    } else if (y === 'marj') {
        el.textContent = deger > 0 ? 'Örn: alış 1.000 ₺ → satış ' + (1000 * (1 + deger / 100)).toFixed(2).replace('.', ',') + ' ₺'
                                   : 'Her ürün kendi kategorisinin hedef marjını kullanır.';
    } else el.textContent = '';
}
function kurFarkiUygula() {
    const eski = parseFloat(document.getElementById('eskiKur').value) || 0;
    const yeni = parseFloat(document.getElementById('yeniKur').value) || 0;
    if (eski <= 0 || yeni <= 0) { alert('Eski ve yeni kuru girin.'); return; }
    const fark = ((yeni / eski) - 1) * 100;
    document.getElementById('yontemSec').value = fark >= 0 ? 'yuzde' : 'yuzde';
    document.getElementById(fark >= 0 ? 'y1' : 'y2').checked = true;
    document.getElementById('degerInput').value = Math.abs(fark).toFixed(2);
    yontemAlanlari();
}
document.getElementById('degerInput').addEventListener('input', guncelleOrnek);
document.querySelectorAll('input[name="yon"]').forEach(r => r.addEventListener('change', guncelleOrnek));
yontemAlanlari();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
