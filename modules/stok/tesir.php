<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo','kasiyer']); // kasiyer salt-okunur (teşhirden satış yapıyor)
$sayfa_basligi = 'Teşhir Yönetimi';
$pdo = db();
$duzenle = in_array($_SESSION['rol'] ?? '', ['yonetici','depo'], true);

$uyariGun = max(7, (int)ayar('tesir_uyari_gun', '90'));
$indirimOran = (float)ayar('tesir_indirim', '10');

// ── POST işlemleri (yalnızca yönetici/depo) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    if (!$duzenle) { flash('hata', 'Teşhir düzenleme yetkiniz yok.'); header('Location: tesir.php'); exit; }

    // Seri nosuz ürün teşhir adedi
    if (isset($_POST['tesir_guncelle'])) {
        $urun_id    = (int)$_POST['urun_id'];
        $yeni_tesir = (int)$_POST['tesir_adedi'];
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT stok_adedi, tesir_adedi, ad FROM urunler WHERE id=? FOR UPDATE");
        $stmt->execute([$urun_id]); $urun = $stmt->fetch();
        if ($urun) {
            $yeni_tesir = max(0, min($yeni_tesir, $urun['stok_adedi']));
            if ($yeni_tesir === (int)$urun['tesir_adedi']) {
                $pdo->rollBack();
                flash('uyari', '"' . $urun['ad'] . '" için değişiklik yok.');
            } else {
                $pdo->prepare("UPDATE urunler SET tesir_adedi=? WHERE id=?")->execute([$yeni_tesir, $urun_id]);
                $pdo->commit();
                logla('tesir_guncelle', 'stok', $urun_id, $urun['ad'] . ' | Teşhir: ' . $urun['tesir_adedi'] . ' → ' . $yeni_tesir);
                flash('basari', '"' . $urun['ad'] . '" teşhir adedi güncellendi.');
            }
        } else $pdo->rollBack();
        header('Location: tesir.php'); exit;
    }

    // Tek/toplu seri durum değişimi (stokta ↔ tesirde)
    if (isset($_POST['seri_durum']) || isset($_POST['toplu_seri'])) {
        $yeni_dur = $_POST['yeni_durum'] ?? '';
        $seri_ids = isset($_POST['toplu_seri'])
            ? array_values(array_filter(array_map('intval', (array)($_POST['seri_ids'] ?? []))))
            : array_filter([(int)($_POST['seri_id'] ?? 0)]);
        if (!in_array($yeni_dur, ['stokta','tesirde'], true) || !$seri_ids) {
            flash('hata', 'Geçersiz istek.'); header('Location: tesir.php'); exit;
        }
        $degisen = 0; $atlanan = 0;
        $pdo->beginTransaction();
        try {
            foreach ($seri_ids as $sid) {
                $stmt = $pdo->prepare("SELECT urun_id, durum FROM seri_numaralari WHERE id=? FOR UPDATE");
                $stmt->execute([$sid]); $seri = $stmt->fetch();
                // Yalnızca stokta↔tesirde geçişi; aynı duruma tekrar basmak sayaç kaydırmasın
                if (!$seri || !in_array($seri['durum'], ['stokta','tesirde'], true) || $seri['durum'] === $yeni_dur) { $atlanan++; continue; }
                $pdo->prepare("UPDATE seri_numaralari SET durum=?, tesir_tarihi=? WHERE id=?")
                    ->execute([$yeni_dur, $yeni_dur === 'tesirde' ? date('Y-m-d H:i:s') : null, $sid]);
                $fark = ($yeni_dur === 'tesirde' ? 1 : -1);
                $pdo->prepare("UPDATE urunler SET tesir_adedi = GREATEST(0, tesir_adedi + ?) WHERE id=?")
                    ->execute([$fark, $seri['urun_id']]);
                $degisen++;
            }
            $pdo->commit();
            logla(isset($_POST['toplu_seri']) ? 'tesir_toplu' : 'tesir_seri', 'stok', 0,
                "$degisen seri → $yeni_dur" . ($atlanan ? " ($atlanan atlandı)" : ''));
            flash($degisen ? 'basari' : 'uyari', "$degisen seri no güncellendi." . ($atlanan ? " $atlanan atlandı (zaten güncel)." : ''));
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('hata', 'Güncelleme sırasında hata: ' . $e->getMessage());
        }
        header('Location: tesir.php'); exit;
    }

    // Seri sayacı senkronu: tesir_adedi'ni gerçek 'tesirde' seri sayısına eşitle
    if (isset($_POST['senkron'])) {
        $sayi = $pdo->exec("UPDATE urunler u SET u.tesir_adedi =
                (SELECT COUNT(*) FROM seri_numaralari sn WHERE sn.urun_id=u.id AND sn.durum='tesirde')
            WHERE u.seri_no_takip=1");
        // Serisizlerde teşhir stok üstünde kalamaz
        $pdo->exec("UPDATE urunler SET tesir_adedi = LEAST(tesir_adedi, stok_adedi) WHERE seri_no_takip=0");
        logla('tesir_senkron', 'stok', 0, 'Teşhir sayaçları seri kayıtlarından yeniden hesaplandı');
        flash('basari', 'Teşhir sayaçları senkronlandı.');
        header('Location: tesir.php'); exit;
    }

    // Arızalı/iadedeki serinin stoğa dönüşü (tamir/anlaşma sonrası)
    if (isset($_POST['ariza_geri'])) {
        $sid = (int)$_POST['seri_id'];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT sn.*, u.ad AS urun_adi FROM seri_numaralari sn JOIN urunler u ON sn.urun_id=u.id WHERE sn.id=? FOR UPDATE");
            $stmt->execute([$sid]); $seri = $stmt->fetch();
            if (!$seri || !in_array($seri['durum'], ['ariza','iade'], true)) throw new Exception('Seri no arıza/iade durumunda değil.');
            $pdo->prepare("UPDATE seri_numaralari SET durum='stokta', tesir_tarihi=NULL WHERE id=?")->execute([$sid]);
            // Çıkışta stok düşmüştü; dönüşte iade girişi olarak stok artar
            stokGuncelle((int)$seri['urun_id'], 1, 'iade_giris', '', ($seri['durum'] === 'ariza' ? 'Arızadan' : 'İadeden') . ' dönüş: ' . $seri['seri_no']);
            $pdo->commit();
            logla('tesir_ariza_geri', 'stok', $sid, $seri['urun_adi'] . ' | ' . $seri['seri_no'] . ' stoğa döndü (' . $seri['durum'] . ')');
            flash('basari', $seri['seri_no'] . ' stoğa geri alındı (+1 stok).');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('hata', $e->getMessage());
        }
        header('Location: tesir.php?sekme=ariza'); exit;
    }
    header('Location: tesir.php'); exit;
}

// ── Filtreler ────────────────────────────────────────────────
$ara    = trim($_GET['ara'] ?? '');
$kat    = (int)($_GET['kat'] ?? 0);
$sadece = !empty($_GET['sadece']); // sadece teşhirdekiler
$sekme  = $_GET['sekme'] ?? 'teshir';
if (!in_array($sekme, ['teshir','ariza','satislar'], true)) $sekme = 'teshir';
$sayfa  = max(1, (int)($_GET['s'] ?? 1));
$limit  = 50;

$where = "WHERE u.aktif=1 AND u.stok_adedi > 0";
$params = [];
if ($ara) {
    $where .= " AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ?
        OR EXISTS (SELECT 1 FROM seri_numaralari snx WHERE snx.urun_id=u.id AND snx.seri_no LIKE ?))";
    $params = array_fill(0, 4, likeParam($ara));
}
if ($kat)    { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params[] = $kat; $params[] = $kat; }
if ($sadece) { $where .= " AND u.tesir_adedi > 0"; }

$urunler = $pdo->prepare("SELECT u.*, k.ad AS kategori FROM urunler u
    LEFT JOIN kategoriler k ON u.kategori_id=k.id $where
    ORDER BY u.seri_no_takip DESC, u.tesir_adedi DESC, u.ad");
$urunler->execute($params);
$urunler = $urunler->fetchAll();

$serili  = array_values(array_filter($urunler, fn($u) => $u['seri_no_takip']));
$serisiz = array_values(array_filter($urunler, fn($u) => !$u['seri_no_takip']));
$serisizToplam = count($serisiz);
$sayfaSayisi = max(1, ceil($serisizToplam / $limit));
$serisiz = array_slice($serisiz, ($sayfa - 1) * $limit, $limit);

// Tüm serileri TEK sorguda çek (N+1 yerine) ve ürüne göre grupla
$seriMap = [];
foreach ($pdo->query("SELECT sn.*, DATEDIFF(NOW(), sn.tesir_tarihi) AS tesir_gun
        FROM seri_numaralari sn JOIN urunler u ON sn.urun_id=u.id
        WHERE sn.durum IN ('stokta','tesirde') AND u.aktif=1
        ORDER BY sn.durum DESC, sn.seri_no")->fetchAll() as $sn) {
    $seriMap[$sn['urun_id']][] = $sn;
}

// Özet
$ozet = $pdo->query("SELECT COUNT(CASE WHEN tesir_adedi>0 THEN 1 END) AS cesit,
    COALESCE(SUM(tesir_adedi),0) AS adet, COALESCE(SUM(tesir_adedi*satis_fiyati),0) AS deger
    FROM urunler WHERE aktif=1")->fetch();
$arizaSayi = (int)$pdo->query("SELECT COUNT(*) FROM seri_numaralari WHERE durum IN ('ariza','iade')")->fetchColumn();

// Uzun süredir teşhirde olanlar
$uzunSure = $pdo->prepare("SELECT sn.*, u.ad AS urun_adi, u.id AS uid, u.satis_fiyati, DATEDIFF(NOW(), sn.tesir_tarihi) AS gun
    FROM seri_numaralari sn JOIN urunler u ON sn.urun_id=u.id
    WHERE sn.durum='tesirde' AND sn.tesir_tarihi IS NOT NULL AND sn.tesir_tarihi <= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY sn.tesir_tarihi");
$uzunSure->execute([$uyariGun]);
$uzunSure = $uzunSure->fetchAll();

// Sayaç tutarsızlığı (serili ürünlerde tesir_adedi != gerçek tesirde sayısı)
$tutarsiz = $pdo->query("SELECT u.id, u.kod, u.ad, u.tesir_adedi,
        (SELECT COUNT(*) FROM seri_numaralari sn WHERE sn.urun_id=u.id AND sn.durum='tesirde') AS gercek
    FROM urunler u WHERE u.aktif=1 AND u.seri_no_takip=1
    HAVING u.tesir_adedi != gercek")->fetchAll();

// Teşhiri boş ana kategoriler
$bosKategoriler = $pdo->query("SELECT k.ad FROM kategoriler k WHERE k.ust_id IS NULL
    AND NOT EXISTS (SELECT 1 FROM urunler u LEFT JOIN kategoriler ka ON u.kategori_id=ka.id
        WHERE u.aktif=1 AND u.tesir_adedi > 0 AND (u.kategori_id=k.id OR ka.ust_id=k.id))
    ORDER BY k.sira, k.ad")->fetchAll(PDO::FETCH_COLUMN);

// Son teşhir hareketleri
$sonHareketler = $pdo->query("SELECT a.*, ku.ad_soyad FROM aktivite_loglari a
    LEFT JOIN kullanicilar ku ON a.kullanici_id=ku.id
    WHERE a.aksiyon IN ('tesir_guncelle','tesir_seri','tesir_toplu','tesir_senkron','tesir_ariza_geri')
    ORDER BY a.id DESC LIMIT 10")->fetchAll();

// CSV export (teşhirdekiler)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tesir_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kod','Ürün','Kategori','Teşhir Adedi','Seri No','Teşhir Tarihi','Gün','Satış Fiyatı'], ';');
    $rows = $pdo->query("SELECT u.kod, u.ad, k.ad AS kategori, u.tesir_adedi, u.satis_fiyati, u.seri_no_takip, u.id
        FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id
        WHERE u.aktif=1 AND u.tesir_adedi > 0 ORDER BY u.ad")->fetchAll();
    foreach ($rows as $r) {
        if ($r['seri_no_takip'] && !empty($seriMap[$r['id']])) {
            foreach ($seriMap[$r['id']] as $sn) {
                if ($sn['durum'] !== 'tesirde') continue;
                fputcsv($out, [csvHucre($r['kod']), csvHucre($r['ad']), csvHucre($r['kategori'] ?? ''), 1,
                    csvHucre($sn['seri_no']), $sn['tesir_tarihi'] ?? '', $sn['tesir_gun'] ?? '',
                    number_format($r['satis_fiyati'], 2, ',', '.')], ';');
            }
        } else {
            fputcsv($out, [csvHucre($r['kod']), csvHucre($r['ad']), csvHucre($r['kategori'] ?? ''), $r['tesir_adedi'],
                '', '', '', number_format($r['satis_fiyati'], 2, ',', '.')], ';');
        }
    }
    fclose($out); exit;
}

// Arıza/iade listesi + teşhirden satışlar (sekmeler)
$arizalar = [];
if ($sekme === 'ariza') {
    $arizalar = $pdo->query("SELECT sn.*, u.ad AS urun_adi, u.kod FROM seri_numaralari sn
        JOIN urunler u ON sn.urun_id=u.id WHERE sn.durum IN ('ariza','iade') ORDER BY sn.id DESC LIMIT 200")->fetchAll();
}
$tesirSatislar = []; $tesirSatisOzet = null;
if ($sekme === 'satislar') {
    $tesirSatislar = $pdo->query("SELECT sk.*, s.fatura_no, s.tarih, s.durum AS satis_durum, s.id AS sid, u.ad AS urun_adi, u.kod
        FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id JOIN urunler u ON sk.urun_id=u.id
        WHERE sk.tesir_satis=1 ORDER BY sk.id DESC LIMIT 100")->fetchAll();
    $tesirSatisOzet = $pdo->query("SELECT COUNT(*) AS islem, COALESCE(SUM(sk.miktar),0) AS adet, COALESCE(SUM(sk.toplam),0) AS ciro
        FROM satis_kalemleri sk JOIN satislar s ON sk.satis_id=s.id
        WHERE sk.tesir_satis=1 AND s.durum!='iptal'")->fetch();
}

function tqs(array $d = []): string {
    $q = array_merge(['ara'=>$_GET['ara'] ?? '', 'kat'=>$_GET['kat'] ?? '', 'sadece'=>$_GET['sadece'] ?? '',
        'sekme'=>$_GET['sekme'] ?? '', 's'=>$_GET['s'] ?? ''], $d);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}

$kategoriler = $pdo->query("SELECT * FROM kategoriler ORDER BY ust_id IS NOT NULL, sira, ad")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h4 class="mb-0"><i class="bi bi-shop-window text-primary"></i> Teşhir Yönetimi</h4>
    <div class="d-flex flex-wrap gap-2 d-print-none">
        <a href="?<?= tqs(['export'=>'csv']) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV</a>
        <button class="btn btn-sm btn-outline-dark" onclick="window.print()"><i class="bi bi-printer"></i> Yazdır</button>
        <?php if ($duzenle): ?>
        <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="senkron" value="1">
            <button class="btn btn-sm btn-outline-warning" title="Teşhir sayaçlarını seri kayıtlarından yeniden hesapla"
                    onclick="return confirm('Tüm teşhir sayaçları seri kayıtlarından yeniden hesaplanacak. Devam?')">
                <i class="bi bi-arrow-repeat"></i> Sayaçları Senkronla</button>
        </form>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">← Stok</a>
    </div>
</div>

<!-- Özet kartları -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Teşhirde</div>
            <div class="fw-bold"><?= (int)$ozet['cesit'] ?> çeşit · <?= (int)$ozet['adet'] ?> adet</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted">Teşhir Değeri (Satış)</div>
            <div class="fw-bold text-success"><?= para($ozet['deger']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100 <?= $uzunSure ? 'border-warning' : '' ?>"><div class="card-body py-2">
            <div class="small text-muted"><?= $uyariGun ?>+ Gün Teşhirde</div>
            <div class="fw-bold text-warning"><?= count($uzunSure) ?> cihaz</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <a href="?sekme=ariza" class="text-decoration-none">
        <div class="card shadow-sm h-100 <?= $arizaSayi ? 'border-danger' : '' ?>"><div class="card-body py-2">
            <div class="small text-muted">Arıza / İadede</div>
            <div class="fw-bold text-danger"><?= $arizaSayi ?> cihaz</div>
        </div></div></a>
    </div>
</div>

<?php if ($tutarsiz): ?>
<div class="alert alert-danger py-2 d-print-none">
    <i class="bi bi-exclamation-octagon-fill"></i> <strong>Sayaç tutarsızlığı:</strong>
    <?php foreach ($tutarsiz as $t): ?>
    <code><?= escH($t['kod']) ?></code> (sayaç: <?= $t['tesir_adedi'] ?>, gerçek teşhirde: <?= $t['gercek'] ?>)
    <?php endforeach; ?>
    <?php if ($duzenle): ?>— yukarıdaki <strong>"Sayaçları Senkronla"</strong> ile düzeltin.<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($bosKategoriler && $sekme === 'teshir'): ?>
<div class="alert alert-secondary py-2 d-print-none">
    <i class="bi bi-shop"></i> Teşhirde hiç ürünü olmayan kategoriler: <strong><?= escH(implode(', ', $bosKategoriler)) ?></strong>
</div>
<?php endif; ?>

<!-- Sekmeler -->
<ul class="nav nav-tabs mb-3 d-print-none">
    <li class="nav-item"><a class="nav-link <?= $sekme==='teshir'?'active':'' ?>" href="?<?= tqs(['sekme'=>'teshir','s'=>1]) ?>"><i class="bi bi-shop-window"></i> Teşhir</a></li>
    <li class="nav-item"><a class="nav-link <?= $sekme==='ariza'?'active':'' ?>" href="?sekme=ariza"><i class="bi bi-wrench"></i> Arıza / İade (<?= $arizaSayi ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $sekme==='satislar'?'active':'' ?>" href="?sekme=satislar"><i class="bi bi-receipt"></i> Teşhirden Satışlar</a></li>
</ul>

<?php if ($sekme === 'ariza'): ?>
<!-- ═══ ARIZA / İADE SEKMESİ ═══ -->
<div class="card shadow-sm">
    <div class="card-body p-0">
    <table class="table table-hover table-sm mb-0 align-middle">
        <thead class="table-light"><tr><th>Seri No</th><th>Ürün</th><th class="text-center">Durum</th><th>Kayıt</th><th class="d-print-none"></th></tr></thead>
        <tbody>
        <?php if (!$arizalar): ?><tr><td colspan="5" class="text-center text-muted py-4">Arıza/iadede cihaz yok</td></tr><?php endif; ?>
        <?php foreach ($arizalar as $a): ?>
        <tr>
            <td><code><?= escH($a['seri_no']) ?></code></td>
            <td><a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $a['urun_id'] ?>" class="text-decoration-none">
                <?= escH($a['urun_adi']) ?></a> <small class="text-muted"><?= escH($a['kod']) ?></small></td>
            <td class="text-center"><span class="badge bg-<?= $a['durum']==='ariza'?'danger':'warning text-dark' ?>"><?= $a['durum']==='ariza'?'Arızalı':'İade' ?></span></td>
            <td class="small text-muted"><?= tarihSaat($a['created_at']) ?></td>
            <td class="text-end d-print-none">
                <?php if ($duzenle): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('<?= escH($a['seri_no']) ?> stoğa geri alınacak (+1 stok, iade girişi). Emin misiniz?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="ariza_geri" value="1">
                    <input type="hidden" name="seri_id" value="<?= $a['id'] ?>">
                    <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-arrow-counterclockwise"></i> Stoğa Geri Al</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php elseif ($sekme === 'satislar'): ?>
<!-- ═══ TEŞHİRDEN SATIŞLAR SEKMESİ ═══ -->
<div class="row g-2 mb-3">
    <div class="col-4"><div class="card shadow-sm"><div class="card-body py-2">
        <div class="small text-muted">İşlem</div><div class="fw-bold"><?= (int)$tesirSatisOzet['islem'] ?></div></div></div></div>
    <div class="col-4"><div class="card shadow-sm"><div class="card-body py-2">
        <div class="small text-muted">Adet</div><div class="fw-bold"><?= (int)$tesirSatisOzet['adet'] ?></div></div></div></div>
    <div class="col-4"><div class="card shadow-sm"><div class="card-body py-2">
        <div class="small text-muted">Ciro</div><div class="fw-bold text-success"><?= para($tesirSatisOzet['ciro']) ?></div></div></div></div>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
    <table class="table table-hover table-sm mb-0 align-middle">
        <thead class="table-light"><tr><th>Tarih</th><th>Fatura</th><th>Ürün</th>
            <th class="text-center">Miktar</th><th class="text-end">Tutar</th><th>Durum</th></tr></thead>
        <tbody>
        <?php if (!$tesirSatislar): ?><tr><td colspan="6" class="text-center text-muted py-4">Teşhirden satış kaydı yok</td></tr><?php endif; ?>
        <?php foreach ($tesirSatislar as $ts): ?>
        <tr>
            <td class="text-nowrap"><?= tarih($ts['tarih']) ?></td>
            <td><a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $ts['sid'] ?>"><code><?= escH($ts['fatura_no']) ?></code></a></td>
            <td><?= escH($ts['urun_adi']) ?> <small class="text-muted"><?= escH($ts['kod']) ?></small></td>
            <td class="text-center"><?= $ts['miktar'] ?></td>
            <td class="text-end fw-semibold"><?= para($ts['toplam']) ?></td>
            <td><span class="badge bg-<?= ['tamamlandi'=>'success','bekliyor'=>'warning','iptal'=>'danger'][$ts['satis_durum']] ?? 'secondary' ?>"><?= escH($ts['satis_durum']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php else: ?>
<!-- ═══ TEŞHİR SEKMESİ ═══ -->

<?php if ($uzunSure): ?>
<div class="card shadow-sm mb-3 border-warning">
    <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-hourglass-bottom text-warning"></i>
        <?= $uyariGun ?>+ Gündür Teşhirde — İndirim Adayları (öneri: %<?= $indirimOran ?>)</div>
    <div class="card-body p-0">
    <table class="table table-sm mb-0 align-middle">
        <tbody>
        <?php foreach ($uzunSure as $us): ?>
        <tr>
            <td><code><?= escH($us['seri_no']) ?></code></td>
            <td><?= escH($us['urun_adi']) ?></td>
            <td class="text-center"><span class="badge bg-warning text-dark"><?= $us['gun'] ?> gün</span></td>
            <td class="text-end"><?= para($us['satis_fiyati']) ?> →
                <strong class="text-danger"><?= para($us['satis_fiyati'] * (1 - $indirimOran / 100)) ?></strong></td>
            <td class="text-end d-print-none">
                <?php if (($_SESSION['rol'] ?? '') === 'yonetici'): ?>
                <a href="<?= BASE_URL ?>/modules/urunler/toplu_fiyat.php?ids=<?= $us['uid'] ?>" class="btn btn-sm btn-outline-warning py-0">
                    <i class="bi bi-percent"></i> İndirim Uygula</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Filtre + barkod -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form class="row g-2 align-items-center" method="get">
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <input type="text" name="ara" id="tesirAra" class="form-control" placeholder="Ürün adı, kod, barkod veya seri no..." value="<?= escH($ara) ?>">
                    <button type="button" class="btn btn-outline-success" title="Kamera ile tara"
                            onclick="BarcodeScanner.start(v => { document.getElementById('tesirAra').value = v; document.getElementById('tesirAra').form.submit(); })">
                        <i class="bi bi-camera"></i>
                    </button>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="kat" class="form-select form-select-sm">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach ($kategoriler as $k): ?>
                    <option value="<?= $k['id'] ?>" <?= $kat==$k['id']?'selected':'' ?>><?= $k['ust_id'] ? '-- ' : '' ?><?= escH($k['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="sadece" id="sadeceTesir" value="1" <?= $sadece?'checked':'' ?>>
                    <label class="form-check-label small" for="sadeceTesir">Sadece teşhirdekiler</label>
                </div>
            </div>
            <div class="col-12 col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i> Ara</button>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info d-flex gap-2 align-items-start d-print-none">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>Teşhirdeki ürünler toplam stoktan <strong>düşülmez</strong> — sadece işaretlenir. Satışta "Teşhir ürününden sat" seçilince teşhir adedi azalır.
        <strong>Seri no'lu ürünlerde</strong> hangi cihazın teşhirde olduğu ve süresi seri no üzerinden izlenir.</div>
</div>

<!-- ── Seri No'lu Ürünler ─── -->
<?php if (!empty($serili)): ?>
<?php if ($duzenle): ?>
<!-- Toplu seri formu: satır formlarıyla çakışmamak için ayrı; checkboxlar form="" ile bağlı -->
<form method="post" id="topluSeriForm">
    <?= csrfField() ?>
    <input type="hidden" name="toplu_seri" value="1">
</form>
<div class="card shadow-sm mb-2 d-none d-print-none" id="topluSeriBar">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold small"><span id="seriSeciliAdet">0</span> seri no seçili:</span>
        <button type="submit" form="topluSeriForm" name="yeni_durum" value="tesirde" class="btn btn-sm btn-warning"
                onclick="return confirm('Seçili seriler teşhire alınacak. Emin misiniz?')">
            <i class="bi bi-shop-window"></i> Teşhire Al</button>
        <button type="submit" form="topluSeriForm" name="yeni_durum" value="stokta" class="btn btn-sm btn-outline-secondary"
                onclick="return confirm('Seçili seriler depoya alınacak. Emin misiniz?')">
            <i class="bi bi-archive"></i> Depoya Al</button>
    </div>
</div>
<?php endif; ?>
<h6 class="fw-bold mt-3 mb-2"><i class="bi bi-upc text-primary"></i> Seri Numaralı Ürünler</h6>
<div class="row g-3 mb-4">
<?php foreach ($serili as $u):
    $seriler = $seriMap[$u['id']] ?? [];
    $tesirdeki = array_filter($seriler, fn($s) => $s['durum'] === 'tesirde');
?>
<div class="col-md-6 col-xl-4">
    <div class="card shadow-sm h-100 <?= count($tesirdeki)>0 ? 'border-warning' : '' ?>">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
            <div>
                <a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $u['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= escH($u['ad']) ?></a>
                <br><small class="text-muted"><?= escH($u['kod']) ?></small>
            </div>
            <div class="text-end">
                <span class="badge bg-secondary">Toplam: <?= $u['stok_adedi'] ?></span>
                <span class="badge bg-warning text-dark">Teşhir: <?= $u['tesir_adedi'] ?></span>
                <div class="mt-1 d-print-none">
                    <a href="<?= BASE_URL ?>/modules/urunler/etiket.php?ids=<?= $u['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark py-0 px-1" title="Etiket"><i class="bi bi-upc"></i></a>
                    <a href="giris.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-success py-0 px-1" title="Stok Giriş"><i class="bi bi-plus"></i></a>
                    <a href="cikis.php?urun_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-1" title="Çıkış"><i class="bi bi-dash"></i></a>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
        <?php if (empty($seriler)): ?>
            <div class="text-center text-muted py-3 small">Stokta seri no yok</div>
        <?php else: ?>
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light"><tr>
                <?php if ($duzenle): ?><th style="width:24px" class="d-print-none"></th><?php endif; ?>
                <th>Seri No</th><th class="text-center">Durum</th><th class="d-print-none"></th></tr></thead>
            <tbody>
            <?php foreach ($seriler as $s): ?>
            <tr class="<?= $s['durum']==='tesirde' ? 'table-warning' : '' ?>">
                <?php if ($duzenle): ?>
                <td class="d-print-none"><input type="checkbox" class="form-check-input seri-sec" name="seri_ids[]" form="topluSeriForm" value="<?= $s['id'] ?>" onclick="seriSayac()"></td>
                <?php endif; ?>
                <td class="fw-semibold small"><?= escH($s['seri_no']) ?></td>
                <td class="text-center">
                    <?php if ($s['durum'] === 'tesirde'): ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-shop-window"></i> Teşhirde</span>
                    <?php if ($s['tesir_gun'] !== null): ?>
                    <br><small class="<?= $s['tesir_gun'] >= $uyariGun ? 'text-danger fw-bold' : 'text-muted' ?>"><?= (int)$s['tesir_gun'] ?> gün</small>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="badge bg-success"><i class="bi bi-archive"></i> Depoda</span>
                    <?php endif; ?>
                </td>
                <td class="text-end d-print-none">
                    <?php if ($duzenle): ?>
                    <form method="post" class="d-inline" onsubmit="this.querySelector('button').disabled=true">
                        <?= csrfField() ?>
                        <input type="hidden" name="seri_durum" value="1">
                        <input type="hidden" name="seri_id" value="<?= $s['id'] ?>">
                        <?php if ($s['durum'] === 'stokta'): ?>
                        <input type="hidden" name="yeni_durum" value="tesirde">
                        <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-2"><i class="bi bi-shop-window"></i> Teşhire</button>
                        <?php else: ?>
                        <input type="hidden" name="yeni_durum" value="stokta">
                        <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2"><i class="bi bi-archive"></i> Depoya</button>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Seri Nosuz Ürünler ─── -->
<?php if (!empty($serisiz)): ?>
<h6 class="fw-bold mt-2 mb-2"><i class="bi bi-box-seam text-primary"></i> Diğer Ürünler</h6>
<div class="card shadow-sm">
    <div class="card-body p-0">
    <table class="table table-hover table-sm mb-0 align-middle">
        <thead class="table-light">
            <tr>
                <th>Ürün</th><th>Kategori</th>
                <th class="text-center">Toplam Stok</th><th class="text-center">Depoda</th><th class="text-center">Teşhirde</th>
                <?php if ($duzenle): ?><th class="text-center d-print-none" style="width:260px">Teşhir Adedini Ayarla</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($serisiz as $u):
            $depoda = $u['stok_adedi'] - $u['tesir_adedi'];
        ?>
        <tr class="<?= $u['tesir_adedi']>0 ? 'table-warning bg-opacity-50' : '' ?>">
            <td>
                <a href="<?= BASE_URL ?>/modules/urunler/detay.php?id=<?= $u['id'] ?>" class="fw-semibold text-decoration-none"><?= escH($u['ad']) ?></a>
                <br><small class="text-muted"><?= escH($u['kod']) ?></small>
            </td>
            <td class="small text-muted"><?= escH($u['kategori'] ?? '-') ?></td>
            <td class="text-center fw-bold"><?= $u['stok_adedi'] ?></td>
            <td class="text-center"><span class="badge bg-success depoda-rozet" data-stok="<?= $u['stok_adedi'] ?>"><?= $depoda ?></span></td>
            <td class="text-center">
                <?php if ($u['tesir_adedi'] > 0): ?>
                <span class="badge bg-warning text-dark"><i class="bi bi-shop-window"></i> <?= $u['tesir_adedi'] ?></span>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <?php if ($duzenle): ?>
            <td class="d-print-none">
                <form method="post" class="d-flex align-items-center gap-2 justify-content-center" onsubmit="this.querySelector('.kaydet-btn').disabled=true">
                    <?= csrfField() ?>
                    <input type="hidden" name="tesir_guncelle" value="1">
                    <input type="hidden" name="urun_id" value="<?= $u['id'] ?>">
                    <div class="input-group input-group-sm" style="max-width:140px">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="tesirAyar(this.nextElementSibling, -1)">−</button>
                        <input type="number" name="tesir_adedi" class="form-control text-center tesir-input"
                               value="<?= $u['tesir_adedi'] ?>" min="0" max="<?= $u['stok_adedi'] ?>" oninput="depodaGuncelle(this)">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="tesirAyar(this.previousElementSibling, 1)">+</button>
                    </div>
                    <?php if ($u['tesir_adedi'] > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2" title="Hepsini depoya al"
                            onclick="const i=this.closest('form').querySelector('.tesir-input'); i.value=0; depodaGuncelle(i);">
                        <i class="bi bi-archive"></i>0</button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-sm btn-primary px-2 kaydet-btn" title="Kaydet"><i class="bi bi-save"></i></button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($sayfaSayisi > 1): ?>
    <div class="card-footer bg-white d-print-none">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end flex-wrap">
            <?php for ($i=1; $i<=$sayfaSayisi; $i++): ?>
            <li class="page-item <?= $i==$sayfa?'active':'' ?>"><a class="page-link" href="?<?= tqs(['s'=>$i]) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($urunler)): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
    Bu filtrelerle ürün bulunamadı.
</div>
<?php endif; ?>

<!-- Son teşhir hareketleri -->
<?php if ($sonHareketler): ?>
<div class="card shadow-sm mt-3 d-print-none">
    <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-clock-history text-primary"></i> Son Teşhir Hareketleri</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($sonHareketler as $h): ?>
        <li class="list-group-item py-1 small d-flex justify-content-between">
            <span><?= escH($h['detay'] ?? $h['aksiyon']) ?></span>
            <span class="text-muted text-nowrap ms-2"><?= tarihSaat($h['created_at']) ?><?= $h['ad_soyad'] ? ' · ' . escH($h['ad_soyad']) : '' ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function seriSayac() {
    const adet = document.querySelectorAll('.seri-sec:checked').length;
    const bar = document.getElementById('topluSeriBar');
    if (bar) {
        document.getElementById('seriSeciliAdet').textContent = adet;
        bar.classList.toggle('d-none', adet === 0);
    }
}
function tesirAyar(input, fark) {
    input.value = Math.max(0, Math.min(parseInt(input.max), (parseInt(input.value) || 0) + fark));
    depodaGuncelle(input);
}
function depodaGuncelle(input) {
    // Depoda rozetini canlı güncelle
    const tr = input.closest('tr');
    const rozet = tr.querySelector('.depoda-rozet');
    if (rozet) rozet.textContent = Math.max(0, parseInt(rozet.dataset.stok) - (parseInt(input.value) || 0));
}
</script>
<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
