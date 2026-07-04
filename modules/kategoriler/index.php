<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Kategoriler';
$pdo = db();
$yonetici = ($_SESSION['rol'] ?? '') === 'yonetici';

// Aynı üst altında aynı isim iki kez kullanılamaz
function kategoriMukerrer(PDO $pdo, string $ad, ?int $ust, int $haric = 0): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM kategoriler WHERE ad=? AND (ust_id <=> ?) AND id!=?");
    $s->execute([$ad, $ust, $haric]);
    return (int)$s->fetchColumn() > 0;
}

// ── CSV export ────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query("SELECT k.*, u.ad AS ust_ad FROM kategoriler k LEFT JOIN kategoriler u ON k.ust_id=u.id
        ORDER BY k.ust_id IS NOT NULL, u.sira, u.ad, k.sira, k.ad")->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kategoriler_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ad','ust','aciklama','ikon','renk','varsayilan_kdv','hedef_marj','sira'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [csvHucre($r['ad']), csvHucre($r['ust_ad'] ?? ''), csvHucre($r['aciklama'] ?? ''),
            csvHucre($r['ikon'] ?? ''), csvHucre($r['renk'] ?? ''),
            $r['varsayilan_kdv'] !== null ? number_format($r['varsayilan_kdv'], 0) : '',
            $r['hedef_marj'] !== null ? number_format($r['hedef_marj'], 1, ',', '') : '',
            $r['sira']], ';');
    }
    fclose($out); exit;
}

// ── POST işlemleri ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    // Ortak alan okuma + doğrulama (ekle/duzenle)
    $alanlariOku = function() use ($pdo) {
        $ad = trim($_POST['ad'] ?? '');
        if ($ad === '') throw new Exception('Kategori adı boş olamaz.');
        if (mb_strlen($ad, 'UTF-8') > 100) throw new Exception('Kategori adı en fazla 100 karakter olabilir.');
        $ust = (int)($_POST['ust_id'] ?? 0) ?: null;
        if ($ust) {
            $k = $pdo->prepare("SELECT ust_id FROM kategoriler WHERE id=?"); $k->execute([$ust]);
            $ustKayit = $k->fetch();
            if (!$ustKayit) throw new Exception('Üst kategori bulunamadı.');
            if ($ustKayit['ust_id'] !== null) throw new Exception('En fazla 2 seviye desteklenir; alt kategori üst olamaz.');
        }
        $ikon = trim($_POST['ikon'] ?? '');
        if ($ikon !== '' && !preg_match('/^[a-z0-9\-]{2,50}$/', $ikon)) $ikon = '';
        $renk = trim($_POST['renk'] ?? '');
        if ($renk !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $renk)) $renk = '';
        $kdv = $_POST['varsayilan_kdv'] ?? '';
        $kdv = ($kdv === '' ? null : min(100, max(0, (float)$kdv)));
        $marj = trim($_POST['hedef_marj'] ?? '');
        $marj = ($marj === '' ? null : min(999, max(0, (float)$marj)));
        return [$ad, $ust, $ikon ?: null, $renk ?: null, trim($_POST['aciklama'] ?? '') ?: null, $kdv, $marj];
    };

    try {
        if ($aksiyon === 'ekle') {
            [$ad, $ust, $ikon, $renk, $aciklama, $kdv, $marj] = $alanlariOku();
            if (kategoriMukerrer($pdo, $ad, $ust)) throw new Exception("\"$ad\" bu seviyede zaten var.");
            $sira = (int)$pdo->query("SELECT COALESCE(MAX(sira),0)+10 FROM kategoriler WHERE ust_id " . ($ust ? "=$ust" : "IS NULL"))->fetchColumn();
            $pdo->prepare("INSERT INTO kategoriler (ad, ust_id, sira, ikon, renk, aciklama, varsayilan_kdv, hedef_marj) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$ad, $ust, $sira, $ikon, $renk, $aciklama, $kdv, $marj]);
            logla('kategori_ekle', 'kategoriler', (int)$pdo->lastInsertId(), $ad);
            flash('basari', "\"$ad\" kategorisi eklendi.");

        } elseif ($aksiyon === 'duzenle') {
            $id = (int)($_POST['id'] ?? 0);
            [$ad, $ust, $ikon, $renk, $aciklama, $kdv, $marj] = $alanlariOku();
            if (!$id) throw new Exception('Kategori bulunamadı.');
            if ($ust == $id) throw new Exception('Kategori kendisinin altına taşınamaz.');
            // Döngü/derinlik koruması: alt kategorisi olan kategori başka kategorinin altına giremez
            if ($ust) {
                $alt = $pdo->prepare("SELECT COUNT(*) FROM kategoriler WHERE ust_id=?"); $alt->execute([$id]);
                if ((int)$alt->fetchColumn() > 0) throw new Exception('Alt kategorileri olan kategori bir üst kategorinin altına taşınamaz. Önce altlarını taşıyın.');
            }
            if (kategoriMukerrer($pdo, $ad, $ust, $id)) throw new Exception("\"$ad\" bu seviyede zaten var.");
            $pdo->prepare("UPDATE kategoriler SET ad=?, ust_id=?, ikon=?, renk=?, aciklama=?, varsayilan_kdv=?, hedef_marj=? WHERE id=?")
                ->execute([$ad, $ust, $ikon, $renk, $aciklama, $kdv, $marj, $id]);
            logla('kategori_duzenle', 'kategoriler', $id, $ad);
            flash('basari', 'Kategori güncellendi.');

        } elseif ($aksiyon === 'sil') {
            if (!$yonetici) throw new Exception('Kategori silme yetkisi yalnızca yöneticidedir.');
            $id = (int)($_POST['id'] ?? 0);
            $tasi = (int)($_POST['tasi_kategori_id'] ?? 0) ?: null;
            $k = $pdo->prepare("SELECT ad FROM kategoriler WHERE id=?"); $k->execute([$id]);
            if (!($kAd = $k->fetchColumn())) throw new Exception('Kategori bulunamadı.');
            $alt = $pdo->prepare("SELECT COUNT(*) FROM kategoriler WHERE ust_id=?"); $alt->execute([$id]);
            if ((int)$alt->fetchColumn() > 0) throw new Exception('Alt kategorileri olan kategori silinemez. Önce altlarını taşıyın veya "Birleştir" kullanın.');
            $urun = $pdo->prepare("SELECT COUNT(*) FROM urunler WHERE kategori_id=?"); $urun->execute([$id]);
            $urunAdet = (int)$urun->fetchColumn();
            if ($urunAdet > 0 && !$tasi) throw new Exception("Kategoride $urunAdet ürün var. Silmek için ürünlerin taşınacağı kategoriyi seçin.");
            if ($tasi === $id) throw new Exception('Ürünler silinen kategorinin kendisine taşınamaz.');
            $pdo->beginTransaction();
            if ($urunAdet > 0) {
                $pdo->prepare("UPDATE urunler SET kategori_id=? WHERE kategori_id=?")->execute([$tasi, $id]);
            }
            $pdo->prepare("DELETE FROM kategoriler WHERE id=?")->execute([$id]);
            $pdo->commit();
            logla('kategori_sil', 'kategoriler', $id, $kAd . ($urunAdet ? " ($urunAdet ürün #$tasi kategorisine taşındı)" : ''));
            flash('basari', "\"$kAd\" silindi." . ($urunAdet ? " $urunAdet ürün taşındı." : ''));

        } elseif ($aksiyon === 'birlestir') {
            if (!$yonetici) throw new Exception('Kategori birleştirme yetkisi yalnızca yöneticidedir.');
            $kaynak = (int)($_POST['kaynak_id'] ?? 0);
            $hedef  = (int)($_POST['hedef_id'] ?? 0);
            if (!$kaynak || !$hedef || $kaynak === $hedef) throw new Exception('Geçerli bir kaynak ve hedef kategori seçin.');
            $s = $pdo->prepare("SELECT id, ad, ust_id FROM kategoriler WHERE id IN (?,?)"); $s->execute([$kaynak, $hedef]);
            $kayitlar = [];
            foreach ($s->fetchAll() as $r) $kayitlar[$r['id']] = $r;
            if (count($kayitlar) !== 2) throw new Exception('Kategori bulunamadı.');
            if (($kayitlar[$hedef]['ust_id'] ?? null) == $kaynak) throw new Exception('Hedef, kaynağın alt kategorisi olamaz.');
            $altVar = $pdo->prepare("SELECT COUNT(*) FROM kategoriler WHERE ust_id=?"); $altVar->execute([$kaynak]);
            $altSayi = (int)$altVar->fetchColumn();
            if ($altSayi > 0 && $kayitlar[$hedef]['ust_id'] !== null)
                throw new Exception('Alt kategorileri olan bir kategori yalnızca ANA kategoriyle birleştirilebilir (2 seviye sınırı).');
            $pdo->beginTransaction();
            $tasinanUrun = $pdo->prepare("UPDATE urunler SET kategori_id=? WHERE kategori_id=?");
            $tasinanUrun->execute([$hedef, $kaynak]);
            $urunSayi = $tasinanUrun->rowCount();
            if ($altSayi > 0) $pdo->prepare("UPDATE kategoriler SET ust_id=? WHERE ust_id=?")->execute([$hedef, $kaynak]);
            $pdo->prepare("DELETE FROM kategoriler WHERE id=?")->execute([$kaynak]);
            $pdo->commit();
            logla('kategori_birlestir', 'kategoriler', $hedef, "{$kayitlar[$kaynak]['ad']} → {$kayitlar[$hedef]['ad']} ($urunSayi ürün, $altSayi alt)");
            flash('basari', "\"{$kayitlar[$kaynak]['ad']}\" kategorisi \"{$kayitlar[$hedef]['ad']}\" ile birleştirildi ($urunSayi ürün taşındı).");

        } elseif ($aksiyon === 'sirala') {
            $siralama = json_decode($_POST['siralama'] ?? '', true);
            if (!is_array($siralama)) throw new Exception('Geçersiz sıralama verisi.');
            $guncelle = $pdo->prepare("UPDATE kategoriler SET sira=? WHERE id=?");
            $pdo->beginTransaction();
            foreach ($siralama as $grup) {
                $s = 0;
                foreach ((array)$grup as $kid) $guncelle->execute([$s += 10, (int)$kid]);
            }
            $pdo->commit();
            logla('kategori_sirala', 'kategoriler', 0, 'Kategori sıralaması güncellendi');
            flash('basari', 'Sıralama kaydedildi.');

        } elseif ($aksiyon === 'kategorisiz_ata') {
            $hedef = (int)($_POST['hedef_kategori_id'] ?? 0);
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['urun'] ?? []))));
            $v = $pdo->prepare("SELECT COUNT(*) FROM kategoriler WHERE id=?"); $v->execute([$hedef]);
            if (!$hedef || !$v->fetchColumn()) throw new Exception('Hedef kategori seçin.');
            if (!$ids) throw new Exception('Ürün seçilmedi.');
            $yt = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE urunler SET kategori_id=? WHERE id IN ($yt) AND kategori_id IS NULL")
                ->execute(array_merge([$hedef], $ids));
            logla('kategori_urun_ata', 'kategoriler', $hedef, count($ids) . ' kategorisiz ürün atandı');
            flash('basari', count($ids) . ' ürün kategoriye atandı.');

        } elseif ($aksiyon === 'ice_aktar') {
            if (!$yonetici) throw new Exception('İçe aktarma yetkisi yalnızca yöneticidedir.');
            if (($_FILES['dosya']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Exception('CSV dosyası yüklenemedi.');
            $fh = fopen($_FILES['dosya']['tmp_name'], 'r');
            $ilk = preg_replace('/^\xEF\xBB\xBF/', '', (string)fgets($fh));
            $ayrac = substr_count($ilk, ';') >= substr_count($ilk, ',') ? ';' : ',';
            $basliklar = array_map(fn($h) => mb_strtolower(trim($h), 'UTF-8'), str_getcsv($ilk, $ayrac));
            if (!in_array('ad', $basliklar, true)) throw new Exception('CSV başlığında "ad" sütunu yok. Önce dışa aktararak şablon alın.');
            $satirlar = [];
            while (($satir = fgetcsv($fh, 0, $ayrac)) !== false) {
                $v = [];
                foreach ($basliklar as $i => $b) $v[$b] = trim((string)($satir[$i] ?? ''));
                if (($v['ad'] ?? '') !== '') $satirlar[] = $v;
            }
            fclose($fh);
            $eklendi = 0; $atlandi = 0;
            $pdo->beginTransaction();
            // İki geçiş: önce ana kategoriler, sonra altlar (üst adı eşleşmesi için)
            foreach ([false, true] as $altGecis) {
                foreach ($satirlar as $v) {
                    $ustAd = $v['ust'] ?? '';
                    if (($ustAd !== '') !== $altGecis) continue;
                    $ust = null;
                    if ($ustAd !== '') {
                        $b = $pdo->prepare("SELECT id FROM kategoriler WHERE ad=? AND ust_id IS NULL"); $b->execute([$ustAd]);
                        $ust = $b->fetchColumn() ?: null;
                        if (!$ust) { $atlandi++; continue; }
                    }
                    if (kategoriMukerrer($pdo, $v['ad'], $ust ? (int)$ust : null)) { $atlandi++; continue; }
                    $renk = preg_match('/^#[0-9a-fA-F]{6}$/', $v['renk'] ?? '') ? $v['renk'] : null;
                    $ikon = preg_match('/^[a-z0-9\-]{2,50}$/', $v['ikon'] ?? '') ? $v['ikon'] : null;
                    $kdv  = ($v['varsayilan_kdv'] ?? '') !== '' ? min(100, max(0, (float)str_replace(',', '.', $v['varsayilan_kdv']))) : null;
                    $marj = ($v['hedef_marj'] ?? '') !== '' ? min(999, max(0, (float)str_replace(',', '.', $v['hedef_marj']))) : null;
                    $sira = (int)($v['sira'] ?? 0);
                    $pdo->prepare("INSERT INTO kategoriler (ad, ust_id, sira, ikon, renk, aciklama, varsayilan_kdv, hedef_marj) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$v['ad'], $ust ?: null, $sira, $ikon, $renk, ($v['aciklama'] ?? '') ?: null, $kdv, $marj]);
                    $eklendi++;
                }
            }
            $pdo->commit();
            logla('kategori_ice_aktar', 'kategoriler', 0, "CSV: $eklendi eklendi, $atlandi atlandı");
            flash('basari', "$eklendi kategori eklendi" . ($atlandi ? ", $atlandi satır atlandı (mükerrer/üst bulunamadı)" : '') . '.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('hata', $e->getMessage());
    }
    header('Location: index.php'); exit;
}

// ── Veri hazırlama ────────────────────────────────────────────
$ustler = $pdo->query("SELECT * FROM kategoriler WHERE ust_id IS NULL ORDER BY sira, ad")->fetchAll();
$tumKategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY sira, ad")->fetchAll();

// Kategori bazlı ürün istatistikleri (aktif ürünler)
$istatistik = [];
foreach ($pdo->query("SELECT kategori_id, COUNT(*) AS urun, COALESCE(SUM(stok_adedi),0) AS stok,
        COALESCE(SUM(stok_adedi*alis_fiyati),0) AS deger,
        SUM(CASE WHEN stok_adedi <= min_stok THEN 1 ELSE 0 END) AS dusuk
    FROM urunler WHERE aktif=1 AND kategori_id IS NOT NULL GROUP BY kategori_id")->fetchAll() as $r) {
    $istatistik[$r['kategori_id']] = $r;
}
// Son 30 gün ciro (kategori bazlı)
$cirolar = $pdo->query("SELECT u.kategori_id, SUM(sk.toplam) AS ciro
    FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id JOIN satislar s ON sk.satis_id=s.id
    WHERE s.durum!='iptal' AND s.tarih >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND u.kategori_id IS NOT NULL
    GROUP BY u.kategori_id")->fetchAll(PDO::FETCH_KEY_PAIR);

// Ana kategori + altlarının birleşik istatistiği
function grupIstatistik(array $ids, array $istatistik, array $cirolar): array {
    $t = ['urun'=>0,'stok'=>0,'deger'=>0.0,'dusuk'=>0,'ciro'=>0.0];
    foreach ($ids as $kid) {
        $s = $istatistik[$kid] ?? null;
        if ($s) { $t['urun'] += $s['urun']; $t['stok'] += $s['stok']; $t['deger'] += $s['deger']; $t['dusuk'] += $s['dusuk']; }
        $t['ciro'] += (float)($cirolar[$kid] ?? 0);
    }
    return $t;
}

// Kategorisiz ürünler
$kategorisiz = $pdo->query("SELECT id, kod, ad FROM urunler WHERE aktif=1 AND kategori_id IS NULL ORDER BY ad LIMIT 50")->fetchAll();

// Özet
$toplamKategori = count($tumKategoriler);
$bosKategori = 0;
foreach ($tumKategoriler as $k) if (empty($istatistik[$k['id']]['urun'])) $bosKategori++;

$grafikVeri = []; $enCok = null;
$varsayilanRenkler = ['#0d6efd','#dc3545','#198754','#ffc107','#6f42c1','#fd7e14','#20c997','#e83e8c','#6c757d','#0dcaf0'];
foreach ($ustler as $i => $ust) {
    $altIds = array_map(fn($k) => $k['id'], array_filter($tumKategoriler, fn($k) => $k['ust_id'] == $ust['id']));
    $g = grupIstatistik(array_merge([$ust['id']], $altIds), $istatistik, $cirolar);
    $grafikVeri[] = ['ad' => $ust['ad'], 'urun' => $g['urun'], 'renk' => $ust['renk'] ?: $varsayilanRenkler[$i % count($varsayilanRenkler)]];
    if (!$enCok || $g['urun'] > $enCok['urun']) $enCok = ['ad' => $ust['ad'], 'urun' => $g['urun']];
}

// Düzenleme modalı için JS haritası
$katJson = json_encode(array_map(fn($k) => [
    'id'=>(int)$k['id'], 'ad'=>$k['ad'], 'ust_id'=>$k['ust_id'] ? (int)$k['ust_id'] : '',
    'ikon'=>$k['ikon'] ?? '', 'renk'=>$k['renk'] ?? '', 'aciklama'=>$k['aciklama'] ?? '',
    'kdv'=>$k['varsayilan_kdv'] !== null ? (float)$k['varsayilan_kdv'] : '',
    'marj'=>$k['hedef_marj'] !== null ? (float)$k['hedef_marj'] : '',
], $tumKategoriler), JSON_UNESCAPED_UNICODE);

$ikonlar = ['tags','snow','droplet','fire','wind','lightning-charge','plug','cup-hot','cart','basket',
    'house-gear','tv','phone','laptop','speaker','fan','thermometer-snow','washing-machine','box-seam','gear',
    'star','heart','lightbulb','tools'];

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-tags text-primary"></i> Kategoriler</h4>
    <div class="d-flex flex-wrap gap-2">
        <input type="text" id="katAra" class="form-control form-control-sm" style="width:180px"
               placeholder="Kategori ara..." oninput="kategoriAra(this.value)">
        <div class="form-check form-switch align-self-center" title="Boş kategorileri vurgula">
            <input class="form-check-input" type="checkbox" id="bosFiltre" onchange="bosGoster(this.checked)">
            <label class="form-check-label small" for="bosFiltre">Sadece boş</label>
        </div>
        <a href="?export=csv" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
        <?php if ($yonetici): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#iceAktarModal"><i class="bi bi-upload"></i> İçe Aktar</button>
        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#birlestirModal"><i class="bi bi-sign-merge-left"></i> Birleştir</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary d-none" id="siraKaydetBtn" onclick="siralamayiKaydet()"><i class="bi bi-save"></i> Sıralamayı Kaydet</button>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#kategoriEkleModal"><i class="bi bi-plus-circle"></i> Yeni Kategori</button>
    </div>
</div>

<!-- Özet kartları -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Toplam Kategori</div>
            <div class="fw-bold"><?= $toplamKategori ?> (<?= count($ustler) ?> ana)</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Boş Kategori</div>
            <div class="fw-bold <?= $bosKategori ? 'text-warning' : 'text-success' ?>"><?= $bosKategori ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">En Çok Ürünlü</div>
            <div class="fw-bold"><?= $enCok ? escH($enCok['ad']) . ' (' . $enCok['urun'] . ')' : '-' ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100 <?= $kategorisiz ? 'border-warning' : '' ?>"><div class="card-body py-2">
            <div class="small text-muted">Kategorisiz Ürün</div>
            <div class="fw-bold <?= $kategorisiz ? 'text-warning' : 'text-success' ?>"><?= count($kategorisiz) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <!-- Kategori kartları -->
    <div class="col-lg-8">
        <div class="row g-3" id="kartAlani">
        <?php foreach ($ustler as $i => $ust): ?>
        <?php
            $altlar = array_values(array_filter($tumKategoriler, fn($k) => $k['ust_id'] == $ust['id']));
            $altIds = array_map(fn($k) => $k['id'], $altlar);
            $g = grupIstatistik(array_merge([$ust['id']], $altIds), $istatistik, $cirolar);
            $renk = $ust['renk'] ?: $varsayilanRenkler[$i % count($varsayilanRenkler)];
        ?>
        <div class="col-md-6 kat-kart" data-id="<?= $ust['id'] ?>" data-ad="<?= escH(mb_strtolower($ust['ad'], 'UTF-8')) ?>"
             data-bos="<?= $g['urun'] ? 0 : 1 ?>" draggable="true">
            <div class="card shadow-sm h-100 <?= $g['urun'] ? '' : 'opacity-50' ?>" style="border-top:3px solid <?= escH($renk) ?>">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <span class="fw-semibold text-truncate">
                        <i class="bi bi-grip-vertical text-muted me-1" style="cursor:grab" title="Sürükleyerek sırala"></i>
                        <i class="bi bi-<?= escH($ust['ikon'] ?: 'folder-fill') ?> me-1" style="color:<?= escH($renk) ?>"></i>
                        <a href="detay.php?id=<?= $ust['id'] ?>" class="text-decoration-none text-dark"><?= escH($ust['ad']) ?></a>
                    </span>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <?php if ($yonetici): ?>
                        <a href="<?= BASE_URL ?>/modules/urunler/toplu_fiyat.php?kategori_id=<?= $ust['id'] ?>"
                           class="btn btn-sm btn-outline-warning py-0 px-2" title="Bu kategoriye zam/indirim"><i class="bi bi-percent"></i></a>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="kategoriDuzenle(<?= $ust['id'] ?>)" title="Düzenle"><i class="bi bi-pencil"></i></button>
                        <?php if ($yonetici): ?>
                        <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Sil"
                                onclick="silModal(<?= $ust['id'] ?>, '<?= escH(addslashes($ust['ad'])) ?>', <?= (int)($istatistik[$ust['id']]['urun'] ?? 0) ?>, <?= count($altlar) ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body py-2 small">
                    <?php if ($ust['aciklama']): ?><div class="text-muted mb-1"><?= escH($ust['aciklama']) ?></div><?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= BASE_URL ?>/modules/urunler/index.php?kat=<?= $ust['id'] ?>" class="badge bg-secondary text-decoration-none"><?= $g['urun'] ?> ürün</a>
                        <span class="badge bg-light text-dark border"><?= $g['stok'] ?> stok</span>
                        <span class="badge bg-light text-dark border"><?= para($g['deger']) ?></span>
                        <?php if ($g['ciro'] > 0): ?><span class="badge bg-success" title="Son 30 gün ciro">30g: <?= para($g['ciro']) ?></span><?php endif; ?>
                        <?php if ($g['dusuk'] > 0): ?><span class="badge bg-danger" title="Kritik stok altındaki ürün"><?= $g['dusuk'] ?> düşük stok</span><?php endif; ?>
                        <?php if ($ust['varsayilan_kdv'] !== null): ?><span class="badge bg-info text-dark">KDV %<?= (float)$ust['varsayilan_kdv'] ?></span><?php endif; ?>
                        <?php if ($ust['hedef_marj'] !== null): ?><span class="badge bg-primary">Marj %<?= (float)$ust['hedef_marj'] ?></span><?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($altlar)): ?>
                <ul class="list-group list-group-flush alt-liste" data-ust="<?= $ust['id'] ?>">
                    <?php foreach ($altlar as $alt): ?>
                    <?php $as = $istatistik[$alt['id']] ?? ['urun'=>0,'dusuk'=>0]; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-1 alt-kat"
                        data-id="<?= $alt['id'] ?>" data-ad="<?= escH(mb_strtolower($alt['ad'], 'UTF-8')) ?>" data-bos="<?= $as['urun'] ? 0 : 1 ?>" draggable="true">
                        <span class="small <?= $as['urun'] ? '' : 'text-muted' ?>">
                            <i class="bi bi-grip-vertical text-muted" style="cursor:grab"></i>
                            <i class="bi bi-<?= escH($alt['ikon'] ?: 'folder') ?> me-1" <?= $alt['renk'] ? 'style="color:'.escH($alt['renk']).'"' : '' ?>></i>
                            <a href="detay.php?id=<?= $alt['id'] ?>" class="text-decoration-none text-dark"><?= escH($alt['ad']) ?></a>
                            <a href="<?= BASE_URL ?>/modules/urunler/index.php?kat=<?= $alt['id'] ?>" class="badge bg-light text-secondary text-decoration-none border ms-1"><?= $as['urun'] ?></a>
                            <?php if ($as['dusuk'] > 0): ?><span class="badge bg-danger"><?= $as['dusuk'] ?>!</span><?php endif; ?>
                        </span>
                        <span class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="kategoriDuzenle(<?= $alt['id'] ?>)"><i class="bi bi-pencil"></i></button>
                            <?php if ($yonetici): ?>
                            <button class="btn btn-sm btn-outline-danger py-0 px-2"
                                    onclick="silModal(<?= $alt['id'] ?>, '<?= escH(addslashes($alt['ad'])) ?>', <?= (int)$as['urun'] ?>, 0)">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="card-body py-1 text-muted small border-top">Alt kategori yok</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Sağ sütun: grafik + kategorisiz ürünler -->
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-pie-chart text-primary"></i> Ürün Dağılımı</div>
            <div class="card-body"><canvas id="dagilimGrafik" height="220"></canvas></div>
        </div>

        <?php if ($kategorisiz): ?>
        <div class="card shadow-sm border-warning">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-question-circle text-warning"></i> Kategorisiz Ürünler (<?= count($kategorisiz) ?>)
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="kategorisiz_ata">
                <ul class="list-group list-group-flush" style="max-height:260px;overflow-y:auto">
                    <?php foreach ($kategorisiz as $ku): ?>
                    <li class="list-group-item py-1 small">
                        <label class="d-flex align-items-center gap-2 mb-0">
                            <input type="checkbox" class="form-check-input mt-0 ktsz" name="urun[]" value="<?= $ku['id'] ?>">
                            <code><?= escH($ku['kod']) ?></code> <?= escH($ku['ad']) ?>
                        </label>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="card-body py-2 d-flex gap-2">
                    <input type="checkbox" class="form-check-input" title="Tümünü seç" onclick="document.querySelectorAll('.ktsz').forEach(c=>c.checked=this.checked)">
                    <select name="hedef_kategori_id" class="form-select form-select-sm" required>
                        <option value="">Kategori seçin...</option>
                        <?php foreach ($tumKategoriler as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-warning text-nowrap"><i class="bi bi-arrow-right"></i> Ata</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Sıralama formu -->
<form method="post" id="siraForm" class="d-none">
    <?= csrfField() ?>
    <input type="hidden" name="aksiyon" value="sirala">
    <input type="hidden" name="siralama" id="siralamaInput">
</form>

<!-- Ekle/Düzenle Modal (ortak) -->
<div class="modal fade" id="kategoriEkleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="ekle" id="formAksiyon">
                <input type="hidden" name="id" id="formId">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="formBaslik"><i class="bi bi-plus-circle text-primary"></i> Yeni Kategori</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold">Kategori Adı *</label>
                            <input type="text" name="ad" id="formAd" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Renk</label>
                            <input type="color" name="renk" id="formRenk" class="form-control form-control-color w-100" value="#0d6efd">
                        </div>
                        <div class="col-8">
                            <label class="form-label fw-semibold">Üst Kategori <small class="text-muted">(boş = ana)</small></label>
                            <select name="ust_id" id="formUst" class="form-select">
                                <option value="">— Ana Kategori —</option>
                                <?php foreach ($ustler as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= escH($u['ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">İkon <i class="bi bi-tags" id="ikonOnizle"></i></label>
                            <select name="ikon" id="formIkon" class="form-select" onchange="document.getElementById('ikonOnizle').className='bi bi-'+(this.value||'folder-fill')">
                                <option value="">Varsayılan</option>
                                <?php foreach ($ikonlar as $ik): ?>
                                <option value="<?= $ik ?>"><?= $ik ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Varsayılan KDV <small class="text-muted">(ürün formunda otomatik)</small></label>
                            <select name="varsayilan_kdv" id="formKdv" class="form-select">
                                <option value="">Yok</option>
                                <?php foreach ([0,1,10,20] as $kdv): ?><option value="<?= $kdv ?>">%<?= $kdv ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Hedef Marj (%)</label>
                            <input type="number" name="hedef_marj" id="formMarj" class="form-control" step="0.1" min="0" placeholder="Örn: 25">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Açıklama</label>
                            <input type="text" name="aciklama" id="formAciklama" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($yonetici): ?>
<!-- Sil Modal -->
<div class="modal fade" id="silModalEl" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="sil">
                <input type="hidden" name="id" id="silId">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold text-danger"><i class="bi bi-trash"></i> Kategori Sil</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="silMetin"></p>
                    <div id="silTasima" class="d-none">
                        <label class="form-label fw-semibold">Ürünler hangi kategoriye taşınsın?</label>
                        <select name="tasi_kategori_id" id="silTasiSecim" class="form-select">
                            <option value="">Seçin...</option>
                            <?php foreach ($tumKategoriler as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Sil</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Birleştir Modal -->
<div class="modal fade" id="birlestirModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" onsubmit="return confirm('Kaynak kategori silinip tüm ürünleri/altları hedefe taşınacak. Emin misiniz?')">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="birlestir">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-sign-merge-left text-warning"></i> Kategori Birleştir</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Kaynak <small class="text-muted">(silinecek)</small></label>
                        <select name="kaynak_id" class="form-select" required>
                            <option value="">Seçin...</option>
                            <?php foreach ($tumKategoriler as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Hedef <small class="text-muted">(ürünler buraya taşınacak)</small></label>
                        <select name="hedef_id" class="form-select" required>
                            <option value="">Seçin...</option>
                            <?php foreach ($tumKategoriler as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-warning py-2 small mb-0">
                        Kaynağın ürünleri ve alt kategorileri hedefe taşınır, kaynak silinir. Bu işlem geri alınamaz.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-sign-merge-left"></i> Birleştir</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- İçe Aktar Modal -->
<div class="modal fade" id="iceAktarModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="aksiyon" value="ice_aktar">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-upload text-primary"></i> Kategori İçe Aktar (CSV)</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="file" name="dosya" class="form-control mb-2" accept=".csv,text/csv" required>
                    <div class="small text-muted">
                        Sütunlar: <code>ad;ust;aciklama;ikon;renk;varsayilan_kdv;hedef_marj;sira</code> —
                        yalnızca <code>ad</code> zorunlu. Örnek dosya için önce <strong>CSV</strong> butonuyla dışa aktarın.
                        Mükerrer isimler ve üst kategorisi bulunamayanlar atlanır.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> İçe Aktar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const KATLAR = <?= $katJson ?>;

// ── Ekle/Düzenle modalı ──────────────────────────────────────
function kategoriDuzenle(id) {
    const k = KATLAR.find(x => x.id === id);
    if (!k) return;
    document.getElementById('formAksiyon').value = 'duzenle';
    document.getElementById('formId').value = k.id;
    document.getElementById('formBaslik').innerHTML = '<i class="bi bi-pencil text-primary"></i> Kategori Düzenle';
    document.getElementById('formAd').value = k.ad;
    document.getElementById('formUst').value = k.ust_id;
    document.getElementById('formIkon').value = k.ikon;
    document.getElementById('formRenk').value = k.renk || '#0d6efd';
    document.getElementById('formAciklama').value = k.aciklama;
    document.getElementById('formKdv').value = k.kdv === '' ? '' : Math.round(k.kdv);
    document.getElementById('formMarj').value = k.marj;
    document.getElementById('ikonOnizle').className = 'bi bi-' + (k.ikon || 'folder-fill');
    new bootstrap.Modal(document.getElementById('kategoriEkleModal')).show();
}
// Modal kapandığında ekleme moduna dön
document.getElementById('kategoriEkleModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('formAksiyon').value = 'ekle';
    document.getElementById('formId').value = '';
    document.getElementById('formBaslik').innerHTML = '<i class="bi bi-plus-circle text-primary"></i> Yeni Kategori';
    ['formAd','formUst','formIkon','formAciklama','formKdv','formMarj'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('formRenk').value = '#0d6efd';
});

// ── Sil modalı ───────────────────────────────────────────────
function silModal(id, ad, urunSayi, altSayi) {
    if (altSayi > 0) {
        alert('"' + ad + '" kategorisinin ' + altSayi + ' alt kategorisi var; silinemez.\nÖnce altlarını taşıyın veya Birleştir kullanın.');
        return;
    }
    document.getElementById('silId').value = id;
    document.getElementById('silMetin').innerHTML = '<strong>' + ad + '</strong> kategorisi silinecek.' +
        (urunSayi > 0 ? '<br>Bu kategoride <strong>' + urunSayi + ' ürün</strong> var — taşınacağı kategoriyi seçmelisiniz.' : ' Kategori boş.');
    document.getElementById('silTasima').classList.toggle('d-none', urunSayi === 0);
    document.getElementById('silTasiSecim').required = urunSayi > 0;
    // Hedef listesinde silinen kategori seçilemesin
    [...document.getElementById('silTasiSecim').options].forEach(o => o.disabled = (parseInt(o.value) === id));
    new bootstrap.Modal(document.getElementById('silModalEl')).show();
}

// ── Arama + boş filtre ───────────────────────────────────────
function kategoriAra(q) {
    q = q.toLocaleLowerCase('tr');
    document.querySelectorAll('.kat-kart').forEach(kart => {
        const anaEs = kart.dataset.ad.includes(q);
        let altEs = false;
        kart.querySelectorAll('.alt-kat').forEach(alt => {
            const es = alt.dataset.ad.includes(q);
            alt.style.display = (q === '' || es || anaEs) ? '' : 'none';
            if (es) altEs = true;
        });
        kart.style.display = (q === '' || anaEs || altEs) ? '' : 'none';
    });
}
function bosGoster(sadece) {
    document.querySelectorAll('.kat-kart').forEach(kart => {
        const altBos = [...kart.querySelectorAll('.alt-kat')].some(a => a.dataset.bos === '1');
        kart.style.display = (!sadece || kart.dataset.bos === '1' || altBos) ? '' : 'none';
        if (sadece) kart.querySelectorAll('.alt-kat').forEach(a => a.style.display = a.dataset.bos === '1' ? '' : 'none');
        else kart.querySelectorAll('.alt-kat').forEach(a => a.style.display = '');
    });
}

// ── Sürükle-bırak sıralama ───────────────────────────────────
let siraDegisti = false;
function dndKur(kapsayici, secici) {
    let suruklenen = null;
    kapsayici.querySelectorAll(secici).forEach(el => {
        el.addEventListener('dragstart', e => { suruklenen = el; el.style.opacity = '.4'; e.dataTransfer.effectAllowed = 'move'; });
        el.addEventListener('dragend', () => { el.style.opacity = ''; suruklenen = null; });
        el.addEventListener('dragover', e => {
            e.preventDefault();
            if (!suruklenen || suruklenen === el || suruklenen.parentElement !== el.parentElement) return;
            const kutu = el.getBoundingClientRect();
            const sonra = (e.clientY - kutu.top) > kutu.height / 2;
            el.parentElement.insertBefore(suruklenen, sonra ? el.nextSibling : el);
            siraDegisti = true;
            document.getElementById('siraKaydetBtn').classList.remove('d-none');
        });
    });
}
function siralamayiKaydet() {
    const gruplar = [];
    gruplar.push([...document.querySelectorAll('#kartAlani > .kat-kart')].map(k => k.dataset.id));
    document.querySelectorAll('.alt-liste').forEach(ul => {
        gruplar.push([...ul.querySelectorAll('.alt-kat')].map(li => li.dataset.id));
    });
    document.getElementById('siralamaInput').value = JSON.stringify(gruplar);
    document.getElementById('siraForm').submit();
}

// ── Grafik ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    dndKur(document.getElementById('kartAlani'), '.kat-kart');
    document.querySelectorAll('.alt-liste').forEach(ul => dndKur(ul, '.alt-kat'));

    const canvas = document.getElementById('dagilimGrafik');
    if (canvas && typeof Chart !== 'undefined') {
        const veri = <?= json_encode($grafikVeri, JSON_UNESCAPED_UNICODE) ?>;
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: veri.map(v => v.ad),
                datasets: [{ data: veri.map(v => v.urun), backgroundColor: veri.map(v => v.renk), borderWidth: 1 }]
            },
            options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
