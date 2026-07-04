<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$rol = $_SESSION['rol'] ?? '';
$isYon = $rol === 'yonetici'; // finansal mutasyonlar yalnızca yönetici

$t = $pdo->prepare("SELECT * FROM tedarikciler WHERE id=?");
$t->execute([$id]); $t = $t->fetch();
if (!$t) { flash('hata', 'Tedarikçi bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = $t['ad'];

// ── Finansal işlemler (yalnızca yönetici) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    if (!$isYon) {
        flash('hata', 'Bu işlem için yetkiniz yok.');
        header('Location: detay.php?id=' . $id); exit;
    }

    // 1) Tedarikçiye ödeme
    if (isset($_POST['odeme_yap'])) {
        $tutar    = round((float)($_POST['odeme_tutar'] ?? 0), 2);
        $tip      = in_array($_POST['odeme_tipi'] ?? '', ['nakit','havale','kredi_karti'], true) ? $_POST['odeme_tipi'] : 'nakit';
        $tarih    = gecerliTarih($_POST['odeme_tarih'] ?? '', date('Y-m-d'));
        $aciklama = trim($_POST['odeme_aciklama'] ?? '');

        $pdo->beginTransaction();
        try {
            $tRow = $pdo->prepare("SELECT toplam_borc FROM tedarikciler WHERE id=? FOR UPDATE");
            $tRow->execute([$id]);
            $mevcutBorc = $tRow->fetchColumn();
            if ($mevcutBorc === false)          throw new RuntimeException('Tedarikçi bulunamadı.');
            if ($tutar <= 0)                    throw new RuntimeException('Ödeme tutarı 0\'dan büyük olmalıdır.');
            $tutar = min($tutar, (float)$mevcutBorc);
            if ($tutar <= 0)                    throw new RuntimeException('Bu tedarikçinin ödenecek borcu bulunmuyor.');

            $pdo->prepare("INSERT INTO tedarikci_odemeleri (tedarikci_id,tarih,tutar,odeme_tipi,aciklama,kullanici_id) VALUES (?,?,?,?,?,?)")
                ->execute([$id, $tarih, $tutar, $tip, $aciklama ?: null, $_SESSION['kullanici_id']]);
            $pdo->prepare("UPDATE tedarikciler SET toplam_borc = GREATEST(0, toplam_borc - ?) WHERE id=?")
                ->execute([$tutar, $id]);
            $pdo->commit();
            logla('tedarikci_odeme', 'tedarikciler', $id, para($tutar) . ' ödeme yapıldı');
            flash('basari', para($tutar) . ' tedarikçi ödemesi kaydedildi.');
        } catch (RuntimeException $e) { $pdo->rollBack(); flash('hata', $e->getMessage()); }
        catch (Exception $e) { $pdo->rollBack(); flash('hata', 'Ödeme sırasında hata: ' . $e->getMessage()); }
        header('Location: detay.php?id=' . $id); exit;
    }

    // 2) Manuel borç / açılış bakiyesi
    if (isset($_POST['borc_ekle'])) {
        $tutar    = round((float)($_POST['borc_tutar'] ?? 0), 2);
        $tarih    = gecerliTarih($_POST['borc_tarih'] ?? '', date('Y-m-d'));
        $vade     = ($_POST['borc_vade'] ?? '') !== '' ? gecerliTarih($_POST['borc_vade'], $tarih) : null;
        $aciklama = trim($_POST['borc_aciklama'] ?? '');

        if ($tutar <= 0) { flash('hata', 'Borç tutarı 0\'dan büyük olmalıdır.'); header('Location: detay.php?id=' . $id); exit; }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO tedarikci_borclar (tedarikci_id,tarih,tutar,aciklama,vade_tarihi,kullanici_id) VALUES (?,?,?,?,?,?)")
                ->execute([$id, $tarih, $tutar, $aciklama ?: null, $vade, $_SESSION['kullanici_id']]);
            $pdo->prepare("UPDATE tedarikciler SET toplam_borc = toplam_borc + ? WHERE id=?")->execute([$tutar, $id]);
            $pdo->commit();
            logla('tedarikci_borc', 'tedarikciler', $id, 'Manuel borç: ' . para($tutar) . ($aciklama ? " ($aciklama)" : ''));
            flash('basari', para($tutar) . ' borç kaydı eklendi.');
        } catch (Exception $e) { $pdo->rollBack(); flash('hata', 'Borç eklenirken hata: ' . $e->getMessage()); }
        header('Location: detay.php?id=' . $id); exit;
    }

    // 3) Ödeme silme (borcu geri ekler)
    if (isset($_POST['odeme_sil'])) {
        $oid = (int)($_POST['odeme_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("SELECT toplam_borc FROM tedarikciler WHERE id=? FOR UPDATE")->execute([$id]);
            $o = $pdo->prepare("SELECT tutar FROM tedarikci_odemeleri WHERE id=? AND tedarikci_id=?");
            $o->execute([$oid, $id]); $tutar = $o->fetchColumn();
            if ($tutar === false) throw new RuntimeException('Ödeme kaydı bulunamadı.');
            $pdo->prepare("DELETE FROM tedarikci_odemeleri WHERE id=?")->execute([$oid]);
            $pdo->prepare("UPDATE tedarikciler SET toplam_borc = toplam_borc + ? WHERE id=?")->execute([$tutar, $id]);
            $pdo->commit();
            logla('tedarikci_odeme_sil', 'tedarikciler', $id, para($tutar) . ' ödeme silindi (borç geri eklendi)');
            flash('basari', 'Ödeme silindi, borç geri eklendi.');
        } catch (RuntimeException $e) { $pdo->rollBack(); flash('hata', $e->getMessage()); }
        catch (Exception $e) { $pdo->rollBack(); flash('hata', 'Silme sırasında hata: ' . $e->getMessage()); }
        header('Location: detay.php?id=' . $id); exit;
    }

    // 4) Manuel borç kalemi silme (borcu düşürür)
    if (isset($_POST['borc_sil'])) {
        $bid = (int)($_POST['borc_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("SELECT toplam_borc FROM tedarikciler WHERE id=? FOR UPDATE")->execute([$id]);
            $b = $pdo->prepare("SELECT tutar FROM tedarikci_borclar WHERE id=? AND tedarikci_id=?");
            $b->execute([$bid, $id]); $tutar = $b->fetchColumn();
            if ($tutar === false) throw new RuntimeException('Borç kaydı bulunamadı.');
            $pdo->prepare("DELETE FROM tedarikci_borclar WHERE id=?")->execute([$bid]);
            $pdo->prepare("UPDATE tedarikciler SET toplam_borc = GREATEST(0, toplam_borc - ?) WHERE id=?")->execute([$tutar, $id]);
            $pdo->commit();
            logla('tedarikci_borc_sil', 'tedarikciler', $id, para($tutar) . ' borç kaydı silindi');
            flash('basari', 'Borç kaydı silindi.');
        } catch (RuntimeException $e) { $pdo->rollBack(); flash('hata', $e->getMessage()); }
        catch (Exception $e) { $pdo->rollBack(); flash('hata', 'Silme sırasında hata: ' . $e->getMessage()); }
        header('Location: detay.php?id=' . $id); exit;
    }
}

// ── Veriler ──────────────────────────────────────────────────
// Stok hareketleri (giriş geçmişi)
$hareketler = $pdo->prepare("
    SELECT sh.*, u.ad AS urun_adi, u.kod, k.ad_soyad AS kullanici
    FROM stok_hareketleri sh
    JOIN urunler u ON sh.urun_id = u.id
    LEFT JOIN kullanicilar k ON sh.kullanici_id = k.id
    WHERE sh.tedarikci_id = ?
    ORDER BY sh.created_at DESC LIMIT 100
");
$hareketler->execute([$id]); $hareketler = $hareketler->fetchAll();

// Ödemeler
$odemeler = $pdo->prepare("SELECT * FROM tedarikci_odemeleri WHERE tedarikci_id=? ORDER BY tarih DESC, id DESC");
$odemeler->execute([$id]); $odemeler = $odemeler->fetchAll();
$toplamOdenen = array_sum(array_column($odemeler, 'tutar'));

// Manuel borç kalemleri
$borclar = $pdo->prepare("SELECT * FROM tedarikci_borclar WHERE tedarikci_id=? ORDER BY tarih DESC, id DESC");
$borclar->execute([$id]); $borclar = $borclar->fetchAll();

// ── Cari ekstre (birleşik, kronolojik, yürüyen bakiye) ───────
$ekstre = [];
foreach ($hareketler as $h) {
    if ((float)($h['toplam_maliyet'] ?? 0) <= 0) continue; // maliyetsiz giriş borç oluşturmaz
    $ekstre[] = [
        'tarih'    => substr($h['created_at'], 0, 10),
        'sira'     => $h['created_at'],
        'tip'      => 'borc',
        'aciklama' => 'Stok girişi — ' . $h['urun_adi'] . ' (' . $h['miktar'] . ' adet)' . ($h['belge_no'] ? ' · ' . $h['belge_no'] : ''),
        'tutar'    => (float)$h['toplam_maliyet'],
        'vade'     => null, 'sil' => null,
    ];
}
foreach ($borclar as $b) {
    $ekstre[] = [
        'tarih'    => $b['tarih'],
        'sira'     => $b['tarih'] . ' ' . str_pad($b['id'], 6, '0', STR_PAD_LEFT),
        'tip'      => 'borc',
        'aciklama' => $b['aciklama'] ?: 'Manuel borç kaydı',
        'tutar'    => (float)$b['tutar'],
        'vade'     => $b['vade_tarihi'],
        'sil'      => ['tur' => 'borc', 'id' => $b['id']],
    ];
}
foreach ($odemeler as $o) {
    $ekstre[] = [
        'tarih'    => $o['tarih'],
        'sira'     => $o['tarih'] . ' ' . str_pad($o['id'], 6, '0', STR_PAD_LEFT),
        'tip'      => 'odeme',
        'aciklama' => 'Ödeme (' . str_replace('_', ' ', $o['odeme_tipi']) . ')' . ($o['aciklama'] ? ' — ' . $o['aciklama'] : ''),
        'tutar'    => (float)$o['tutar'],
        'vade'     => null,
        'sil'      => ['tur' => 'odeme', 'id' => $o['id']],
    ];
}
usort($ekstre, fn($a, $b) => strcmp($a['sira'], $b['sira']));

// Ekstre CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tedarikci_ekstre_' . $id . '_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tarih', 'Açıklama', 'Borç', 'Ödeme', 'Bakiye', 'Vade'], ';');
    $bak = 0;
    foreach ($ekstre as $e) {
        if ($e['tip'] === 'borc') { $bak += $e['tutar']; $borc = number_format($e['tutar'],2,',','.'); $ode=''; }
        else                      { $bak -= $e['tutar']; $ode  = number_format($e['tutar'],2,',','.'); $borc=''; }
        fputcsv($out, [$e['tarih'], csvHucre($e['aciklama']), $borc, $ode, number_format($bak,2,',','.'), $e['vade'] ?: ''], ';');
    }
    fputcsv($out, ['', 'GÜNCEL BORÇ', '', '', number_format((float)$t['toplam_borc'],2,',','.'), ''], ';');
    fclose($out); exit;
}

// İstatistikler
$istatistik = $pdo->prepare("
    SELECT COUNT(*) AS toplam_islem, COALESCE(SUM(miktar),0) AS toplam_adet,
           COUNT(DISTINCT urun_id) AS urun_cesidi, MAX(created_at) AS son_giris
    FROM stok_hareketleri WHERE tedarikci_id = ?
");
$istatistik->execute([$id]); $ist = $istatistik->fetch();

// En çok gelen ürünler
$enCokUrunler = $pdo->prepare("
    SELECT u.ad, u.kod, SUM(sh.miktar) AS toplam_adet
    FROM stok_hareketleri sh JOIN urunler u ON sh.urun_id = u.id
    WHERE sh.tedarikci_id = ? GROUP BY u.id ORDER BY toplam_adet DESC LIMIT 5
");
$enCokUrunler->execute([$id]); $enCokUrunler = $enCokUrunler->fetchAll();

// Bekleyen siparişler
$bekleyenSiparisler = $pdo->prepare("SELECT * FROM tedarikci_siparisleri WHERE tedarikci_id=? AND durum='bekliyor' ORDER BY tarih DESC LIMIT 10");
$bekleyenSiparisler->execute([$id]); $bekleyenSiparisler = $bekleyenSiparisler->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4><i class="bi bi-truck text-primary"></i> <?= escH($t['ad']) ?></h4>
        <?php if ($t['yetkili']): ?>
        <span class="text-muted"><i class="bi bi-person"></i> <?= escH($t['yetkili']) ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a href="siparis_ekle.php?tedarikci_id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-clipboard-plus"></i> Sipariş Ver
        </a>
        <a href="duzenle.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pencil"></i> Düzenle
        </a>
        <a href="<?= BASE_URL ?>/modules/stok/giris.php?tedarikci_id=<?= $id ?>" class="btn btn-success">
            <i class="bi bi-box-arrow-in-down"></i> Stok Girişi
        </a>
    </div>
</div>

<div class="row g-3">
    <!-- Sol: Bilgiler + Borç -->
    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle text-primary"></i> Firma Bilgileri
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><th class="ps-3 text-muted fw-normal" style="width:120px">Telefon</th>
                        <td><?php if ($t['telefon']): ?><a href="tel:<?= escH($t['telefon']) ?>"><?= escH($t['telefon']) ?></a><?php else: ?>-<?php endif; ?></td></tr>
                    <tr><th class="ps-3 text-muted fw-normal">E-posta</th>
                        <td><?php if ($t['email']): ?><a href="mailto:<?= escH($t['email']) ?>"><?= escH($t['email']) ?></a><?php else: ?>-<?php endif; ?></td></tr>
                    <tr><th class="ps-3 text-muted fw-normal">Vergi No</th><td><?= escH($t['vergi_no'] ?: '-') ?></td></tr>
                    <tr><th class="ps-3 text-muted fw-normal">Vergi Dairesi</th><td><?= escH($t['vergi_dairesi'] ?: '-') ?></td></tr>
                    <tr><th class="ps-3 text-muted fw-normal">IBAN</th>
                        <td><?php if ($t['iban']): ?><code class="small"><?= escH($t['iban']) ?></code>
                            <button class="btn btn-sm btn-link p-0 ms-1" title="Kopyala" onclick="navigator.clipboard.writeText('<?= escH($t['iban']) ?>')"><i class="bi bi-clipboard"></i></button>
                        <?php else: ?>-<?php endif; ?></td></tr>
                    <tr><th class="ps-3 text-muted fw-normal">Adres</th><td class="small"><?= escH($t['adres'] ?: '-') ?></td></tr>
                    <tr><th class="ps-3 text-muted fw-normal">Kayıt</th><td><?= tarih($t['created_at']) ?></td></tr>
                </table>
                <?php if ($t['notlar']): ?>
                <div class="mx-3 my-2 p-2 bg-light rounded small"><i class="bi bi-sticky text-warning"></i> <?= escH($t['notlar']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Borç Durumu -->
        <div class="card shadow-sm mb-3 <?= $t['toplam_borc']>0?'border-danger':'' ?>">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-credit-card text-danger"></i> Borç Durumu</span>
                <?php if ($isYon): ?>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#borcModal" title="Borç/açılış ekle">
                        <i class="bi bi-plus-lg"></i> Borç
                    </button>
                    <?php if ($t['toplam_borc']>0): ?>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#odemeModal">
                        <i class="bi bi-cash-coin"></i> Ödeme
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Güncel Borç</td>
                        <td class="fw-bold text-end <?= $t['toplam_borc']>0?'text-danger':'text-success' ?>"><?= para($t['toplam_borc']) ?></td></tr>
                    <tr><td class="text-muted">Toplam Ödenen</td>
                        <td class="fw-bold text-end text-success"><?= para($toplamOdenen) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- İstatistikler -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart text-primary"></i> İstatistikler</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Toplam İşlem</td><td class="fw-bold text-end"><?= $ist['toplam_islem'] ?></td></tr>
                    <tr><td class="text-muted">Toplam Gelen Ürün</td><td class="fw-bold text-end"><?= number_format($ist['toplam_adet']) ?> adet</td></tr>
                    <tr><td class="text-muted">Ürün Çeşidi</td><td class="fw-bold text-end"><?= $ist['urun_cesidi'] ?></td></tr>
                    <tr><td class="text-muted">Son Giriş</td><td class="fw-bold text-end"><?= $ist['son_giris'] ? tarih($ist['son_giris']) : '-' ?></td></tr>
                </table>
            </div>
        </div>

        <?php if (!empty($enCokUrunler)): ?>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-trophy text-warning"></i> En Çok Gelen Ürünler</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                <?php foreach ($enCokUrunler as $u): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <div><div class="small fw-semibold"><?= escH($u['ad']) ?></div>
                         <div class="text-muted" style="font-size:.75rem"><?= escH($u['kod']) ?></div></div>
                    <span class="badge bg-primary"><?= $u['toplam_adet'] ?> adet</span>
                </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sağ: sekmeler -->
    <div class="col-md-8">
        <?php if (!empty($bekleyenSiparisler)): ?>
        <div class="card shadow-sm mb-3 border-primary">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock text-primary"></i> Bekleyen Siparişler</div>
            <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>Sipariş No</th><th>Tarih</th><th>Beklenen</th><th class="text-end">Tutar</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bekleyenSiparisler as $s): ?>
                <tr>
                    <td class="fw-semibold"><?= escH($s['siparis_no']) ?></td>
                    <td><?= tarih($s['tarih']) ?></td>
                    <td><?= $s['beklenen_tarih'] ? tarih($s['beklenen_tarih']) : '-' ?></td>
                    <td class="text-end"><?= para($s['toplam_tutar']) ?></td>
                    <td><a href="siparis_detay.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-ekstre"><i class="bi bi-card-list"></i> Cari Ekstre</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-stok"><i class="bi bi-box-seam"></i> Stok Geçmişi <span class="badge bg-secondary ms-1"><?= count($hareketler) ?></span></button></li>
        </ul>

        <div class="tab-content">
            <!-- Cari Ekstre -->
            <div class="tab-pane fade show active" id="tab-ekstre">
                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-card-list text-primary"></i> Cari Hesap Ekstresi</span>
                        <a href="?id=<?= $id ?>&export=1" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> İndir</a>
                    </div>
                    <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr>
                            <th>Tarih</th><th>Açıklama</th><th class="text-end">Borç</th>
                            <th class="text-end">Ödeme</th><th class="text-end">Bakiye</th><th>Vade</th>
                            <?php if ($isYon): ?><th></th><?php endif; ?>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($ekstre)): ?>
                            <tr><td colspan="<?= $isYon?7:6 ?>" class="text-center text-muted py-4">Hareket yok</td></tr>
                        <?php else: $bak = 0; foreach ($ekstre as $e):
                            if ($e['tip']==='borc') $bak += $e['tutar']; else $bak -= $e['tutar'];
                            $vadeGecti = $e['vade'] && strtotime($e['vade']) < strtotime(date('Y-m-d')) && $t['toplam_borc']>0;
                        ?>
                        <tr class="<?= $e['tip']==='odeme'?'table-success bg-opacity-25':'' ?>">
                            <td class="text-nowrap small"><?= tarih($e['tarih']) ?></td>
                            <td class="small"><?= escH($e['aciklama']) ?></td>
                            <td class="text-end text-danger"><?= $e['tip']==='borc'?para($e['tutar']):'' ?></td>
                            <td class="text-end text-success"><?= $e['tip']==='odeme'?para($e['tutar']):'' ?></td>
                            <td class="text-end fw-bold <?= $bak>0?'text-danger':($bak<0?'text-success':'') ?>"><?= para($bak) ?></td>
                            <td class="small <?= $vadeGecti?'text-danger fw-bold':'text-muted' ?>">
                                <?= $e['vade'] ? tarih($e['vade']) . ($vadeGecti?' <i class="bi bi-exclamation-circle"></i>':'') : '' ?>
                            </td>
                            <?php if ($isYon): ?>
                            <td class="text-end">
                                <?php if ($e['sil']): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= $e['sil']['tur']==='odeme'?'Ödeme silinince borç geri eklenir. Emin misiniz?':'Bu borç kaydı silinecek. Emin misiniz?' ?>')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="<?= $e['sil']['tur']==='odeme'?'odeme_sil':'borc_sil' ?>" value="1">
                                    <input type="hidden" name="<?= $e['sil']['tur']==='odeme'?'odeme_id':'borc_id' ?>" value="<?= $e['sil']['id'] ?>">
                                    <button class="btn btn-xs btn-outline-danger btn-sm py-0 px-1" title="Sil"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold table-light">
                            <td colspan="4" class="text-end">GÜNCEL BORÇ</td>
                            <td class="text-end <?= $t['toplam_borc']>0?'text-danger':'text-success' ?>"><?= para($t['toplam_borc']) ?></td>
                            <td<?= $isYon?' colspan="2"':'' ?>></td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                </div>
            </div>

            <!-- Stok Geçmişi -->
            <div class="tab-pane fade" id="tab-stok">
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                    <?php if (empty($hareketler)): ?>
                        <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>Henüz stok hareketi yok</div>
                    <?php else: ?>
                    <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light"><tr><th>Tarih</th><th>Ürün</th><th class="text-center">Miktar</th><th>Belge</th><th>Açıklama</th><th>Kullanıcı</th></tr></thead>
                        <tbody>
                        <?php foreach ($hareketler as $h): ?>
                        <tr>
                            <td class="text-nowrap small"><?= tarihSaat($h['created_at']) ?></td>
                            <td><strong><?= escH($h['urun_adi']) ?></strong><br><small class="text-muted"><?= escH($h['kod']) ?></small></td>
                            <td class="text-center"><span class="badge bg-success"><?= $h['miktar'] ?></span></td>
                            <td class="small"><?= escH($h['belge_no'] ?: '-') ?></td>
                            <td class="small"><?= escH($h['aciklama'] ?: '-') ?></td>
                            <td class="small"><?= escH($h['kullanici'] ?: '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isYon): ?>
<!-- Ödeme Modal -->
<div class="modal fade" id="odemeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="odeme_yap" value="1">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-cash-coin text-success"></i> Tedarikçi Ödemesi — <?= escH($t['ad']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2">Güncel borç: <strong><?= para($t['toplam_borc']) ?></strong></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ödeme Tutarı <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="odeme_tutar" class="form-control" step="0.01" min="0.01" value="<?= $t['toplam_borc'] ?>" required>
                            <span class="input-group-text"><?= escH(ayar('para_sembol','₺')) ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ödeme Tipi</label>
                        <select name="odeme_tipi" class="form-select">
                            <option value="nakit">Nakit</option>
                            <option value="havale">Havale / EFT</option>
                            <option value="kredi_karti">Kredi Kartı</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label fw-semibold">Tarih</label>
                        <input type="date" name="odeme_tarih" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Açıklama</label>
                        <input type="text" name="odeme_aciklama" class="form-control" placeholder="Fatura no, irsaliye no..."></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Ödemeyi Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Borç / Açılış Modal -->
<div class="modal fade" id="borcModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="borc_ekle" value="1">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-journal-plus text-danger"></i> Borç / Açılış Bakiyesi — <?= escH($t['ad']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small">
                        Sisteme geçişte devir borcu, fatura borcu veya düzeltme kalemi eklemek için kullanın.
                        Stok girişindeki maliyet zaten otomatik borç oluşturur.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Borç Tutarı <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="borc_tutar" class="form-control" step="0.01" min="0.01" required>
                            <span class="input-group-text"><?= escH(ayar('para_sembol','₺')) ?></span>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-3"><label class="form-label fw-semibold">Tarih</label>
                            <input type="date" name="borc_tarih" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6 mb-3"><label class="form-label fw-semibold">Vade <small class="text-muted">(ops.)</small></label>
                            <input type="date" name="borc_vade" class="form-control"></div>
                    </div>
                    <div class="mb-3"><label class="form-label fw-semibold">Açıklama</label>
                        <input type="text" name="borc_aciklama" class="form-control" placeholder="Örn: Açılış bakiyesi, Fatura #123..."></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-check-circle"></i> Borç Ekle</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
