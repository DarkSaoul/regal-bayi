<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$rol = $_SESSION['rol'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);

// ── POST aksiyonları: teslimat güncelle / ön sipariş teslim ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($rol, ['yonetici','kasiyer'], true)) {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'teslimat') {
        $durum = in_array($_POST['teslimat_durum'] ?? '', ['yok','hazirlaniyor','yolda','teslim_edildi'], true)
               ? $_POST['teslimat_durum'] : 'yok';
        $ttarih = !empty($_POST['teslimat_tarihi']) ? gecerliTarih($_POST['teslimat_tarihi'], date('Y-m-d')) : null;
        $mtarih = !empty($_POST['montaj_tarihi'])   ? gecerliTarih($_POST['montaj_tarihi'], date('Y-m-d'))   : null;
        $pdo->prepare("UPDATE satislar SET teslimat_tarihi=?, teslimat_adresi=?, teslimat_durum=?, montaj_tarihi=?, montaj_notu=? WHERE id=?")
            ->execute([$ttarih, mb_substr(trim($_POST['teslimat_adresi'] ?? ''), 0, 1000),
                       $durum, $mtarih, mb_substr(trim($_POST['montaj_notu'] ?? ''), 0, 255) ?: null, $id]);
        logla('teslimat_guncelle', 'satislar', $id, 'Durum: ' . $durum);
        flash('basari', 'Teslimat bilgileri güncellendi.');
        header('Location: detay.php?id=' . $id); exit;
    }

    if ($aksiyon === 'on_teslim') {
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("SELECT * FROM satislar WHERE id=? AND tip='on_siparis' AND stok_dusuldu=0 AND durum!='iptal' FOR UPDATE");
            $s->execute([$id]); $s = $s->fetch();
            if (!$s) throw new RuntimeException('Teslim edilecek ön sipariş bulunamadı.');
            $ks = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi FROM satis_kalemleri sk JOIN urunler u ON u.id=sk.urun_id WHERE sk.satis_id=?");
            $ks->execute([$id]);
            foreach ($ks->fetchAll() as $k) {
                $st = $pdo->prepare("SELECT ad, stok_adedi FROM urunler WHERE id=? FOR UPDATE");
                $st->execute([$k['urun_id']]); $u = $st->fetch();
                if (!$u || $u['stok_adedi'] < $k['miktar']) {
                    throw new RuntimeException('"' . ($u['ad'] ?? $k['urun_adi']) . '" için stok yetersiz'
                        . ' (Gereken: ' . $k['miktar'] . ', Mevcut: ' . ($u['stok_adedi'] ?? 0) . '). Önce stok girişi yapın.');
                }
                stokGuncelle($k['urun_id'], -$k['miktar'], 'cikis', $s['fatura_no'], 'Ön sipariş teslimi');
            }
            $pdo->prepare("UPDATE satislar SET stok_dusuldu=1, teslimat_durum=IF(teslimat_durum='yok','teslim_edildi',teslimat_durum) WHERE id=?")->execute([$id]);
            $pdo->commit();
            logla('on_siparis_teslim', 'satislar', $id, 'Fatura: ' . $s['fatura_no']);
            flash('basari', 'Ön sipariş teslim edildi, stoktan düşüldü.');
        } catch (RuntimeException $e) {
            $pdo->rollBack(); flash('hata', $e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack(); flash('hata', 'Teslim sırasında hata: ' . $e->getMessage());
        }
        header('Location: detay.php?id=' . $id); exit;
    }
}

$satis = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.telefon, m.adres, m.tc_no,
    k.ad_soyad AS satici FROM satislar s
    LEFT JOIN musteriler m ON s.musteri_id=m.id
    LEFT JOIN kullanicilar k ON s.kullanici_id=k.id WHERE s.id=?");
$satis->execute([$id]); $satis = $satis->fetch();
if (!$satis) { flash('hata','Satış bulunamadı.'); header('Location: index.php'); exit; }

$kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.satis_id=?");
$kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();
$tesirSatis = !empty(array_filter($kalemler, fn($k) => $k['tesir_satis']));

$odemeler = $pdo->prepare("SELECT * FROM odemeler WHERE satis_id=? ORDER BY tarih, id");
$odemeler->execute([$id]); $odemeler = $odemeler->fetchAll();

// Taksit planı
$taksitPlani = $pdo->prepare("SELECT * FROM taksit_plani WHERE satis_id=? ORDER BY taksit_no");
$taksitPlani->execute([$id]); $taksitPlani = $taksitPlani->fetchAll();

// İadeler
$iadeler = $pdo->prepare("SELECT i.*, k.ad_soyad FROM satis_iadeleri i LEFT JOIN kullanicilar k ON i.kullanici_id=k.id WHERE i.satis_id=? ORDER BY i.id");
$iadeler->execute([$id]); $iadeler = $iadeler->fetchAll();
$iadeKalemleri = [];
if ($iadeler) {
    $ik = $pdo->prepare("SELECT ik.*, u.ad AS urun_adi FROM satis_iade_kalemleri ik JOIN urunler u ON u.id=ik.urun_id
                         WHERE ik.iade_id IN (SELECT id FROM satis_iadeleri WHERE satis_id=?)");
    $ik->execute([$id]);
    foreach ($ik->fetchAll() as $r) $iadeKalemleri[$r['iade_id']][] = $r;
}

// Değişim bağlantıları (iki yönlü)
$degisimKaynagi = null; // bu satış hangi satışın değişimi
if ($satis['degisim_satis_id']) {
    $dg = $pdo->prepare("SELECT id, fatura_no FROM satislar WHERE id=?");
    $dg->execute([$satis['degisim_satis_id']]); $degisimKaynagi = $dg->fetch();
}
$degisimYeni = $pdo->prepare("SELECT id, fatura_no FROM satislar WHERE degisim_satis_id=?");
$degisimYeni->execute([$id]); $degisimYeni = $degisimYeni->fetchAll();

// Zaman çizelgesi (aktivite logları)
$aktiviteler = $pdo->prepare("SELECT a.*, k.ad_soyad FROM aktivite_loglari a LEFT JOIN kullanicilar k ON a.kullanici_id=k.id
    WHERE a.modul='satislar' AND a.hedef_id=? ORDER BY a.id");
$aktiviteler->execute([$id]); $aktiviteler = $aktiviteler->fetchAll();

// Kârlılık (yalnızca yönetici)
$karToplam = null;
if ($rol === 'yonetici') {
    $karToplam = ['maliyet' => 0, 'net' => 0, 'kar' => 0, 'eksik' => false];
    foreach ($kalemler as $k) {
        $netAdet = (int)$k['miktar'] - (int)$k['iade_miktar'];
        $net = (float)$k['toplam'] - (float)$k['kdv_tutar']; // KDV hariç satış
        $net = (int)$k['miktar'] > 0 ? $net / (int)$k['miktar'] * $netAdet : 0;
        if ($k['birim_maliyet'] === null) { $karToplam['eksik'] = true; continue; }
        $m = (float)$k['birim_maliyet'] * $netAdet;
        $karToplam['maliyet'] += $m;
        $karToplam['net'] += $net;
        $karToplam['kar'] += $net - $m;
    }
}

// Taksit hesaplamaları
$taksitli = $satis['odeme_tipi'] === 'taksitli' && $satis['taksit_sayisi'] > 1;
$aylik_taksit  = $taksitli ? ($satis['genel_toplam'] / $satis['taksit_sayisi']) : 0;
$odenen_taksit = 0;
if ($taksitli) {
    $odenen_taksit = count(array_filter($taksitPlani, fn($t) => $t['odendi']));
}
$kalan_taksit  = $taksitli ? (count($taksitPlani) - $odenen_taksit) : 0;
$taksit_ilerleme = $taksitli && count($taksitPlani) > 0
    ? round(($odenen_taksit / count($taksitPlani)) * 100)
    : 0;

// WhatsApp fatura mesajı
$waLink = null;
if (!empty($satis['telefon'])) {
    $waMesaj = ayar('firma_adi', 'Regal Bayi') . "\n"
        . "Fatura: " . $satis['fatura_no'] . "\n"
        . "Tarih: " . tarih($satis['tarih']) . "\n"
        . "Tutar: " . para($satis['genel_toplam'])
        . ($satis['kalan_tutar'] > 0 ? "\nKalan: " . para($satis['kalan_tutar']) : '')
        . ($taksitli ? "\n" . $satis['taksit_sayisi'] . " taksit × " . para($aylik_taksit) : '')
        . "\nBizi tercih ettiğiniz için teşekkür ederiz.";
    $waLink = whatsappLink($satis['telefon'], $waMesaj);
}

$sayfa_basligi = 'Fatura: ' . $satis['fatura_no'];
require_once __DIR__ . '/../../includes/header.php';
$duzenleyebilir = in_array($rol, ['yonetici','kasiyer'], true);
?>
<div class="page-header d-flex justify-content-between flex-wrap gap-2 no-print">
    <h4>
        <i class="bi bi-receipt text-primary"></i> <?= escH($satis['fatura_no']) ?>
        <?php if ($satis['tip'] === 'on_siparis'): ?>
        <span class="badge bg-info text-dark ms-1"><i class="bi bi-bag-plus"></i> Ön Sipariş<?= $satis['stok_dusuldu'] ? ' (Teslim Edildi)' : '' ?></span>
        <?php endif; ?>
        <?php if ($tesirSatis): ?>
        <span class="badge bg-warning text-dark ms-1" title="Bu satışta teşhir ürünü var">
            <i class="bi bi-shop-window"></i> Teşhir Satışı
        </span>
        <?php endif; ?>
        <?php if ($satis['iade_toplam'] > 0): ?>
        <span class="badge bg-danger ms-1"><i class="bi bi-arrow-return-left"></i> İade: <?= para($satis['iade_toplam']) ?></span>
        <?php endif; ?>
        <?php if ($degisimKaynagi): ?>
        <a href="detay.php?id=<?= $degisimKaynagi['id'] ?>" class="badge bg-secondary text-decoration-none ms-1"
           title="Bu satış bir değişim işlemidir"><i class="bi bi-arrow-left-right"></i> Değişim ← <?= escH($degisimKaynagi['fatura_no']) ?></a>
        <?php endif; ?>
        <?php foreach ($degisimYeni as $dy): ?>
        <a href="detay.php?id=<?= $dy['id'] ?>" class="badge bg-secondary text-decoration-none ms-1"
           title="Bu satıştan değişim yapıldı"><i class="bi bi-arrow-left-right"></i> Değişim → <?= escH($dy['fatura_no']) ?></a>
        <?php endforeach; ?>
    </h4>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($satis['kalan_tutar']>0 && $satis['durum'] !== 'iptal' && $duzenleyebilir): ?>
        <a href="<?= BASE_URL ?>/modules/finans/tahsilat.php?satis_id=<?= $id ?>" class="btn btn-success">
            <i class="bi bi-cash-coin"></i> Tahsilat Al
        </a>
        <?php endif; ?>
        <?php if ($satis['tip'] === 'on_siparis' && !$satis['stok_dusuldu'] && $satis['durum'] !== 'iptal' && $duzenleyebilir): ?>
        <form method="post" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="aksiyon" value="on_teslim">
            <button type="submit" class="btn btn-info fw-semibold"
                onclick="return confirm('Ürünler stoktan düşülüp ön sipariş teslim edilecek. Onaylıyor musunuz?')">
                <i class="bi bi-box-arrow-right"></i> Teslim Et
            </button>
        </form>
        <?php endif; ?>
        <div class="btn-group">
            <a href="yazdir.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Fatura</a>
            <a href="fis.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary" title="80mm termal fiş"><i class="bi bi-receipt-cutoff"></i> Fiş</a>
            <?php if ($taksitli): ?>
            <a href="sozlesme.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary" title="Taksitli satış sözleşmesi"><i class="bi bi-file-earmark-text"></i> Sözleşme</a>
            <?php endif; ?>
        </div>
        <?php if ($waLink): ?>
        <a href="<?= escH($waLink) ?>" target="_blank" class="btn btn-outline-success" title="Fatura özetini WhatsApp ile gönder">
            <i class="bi bi-whatsapp"></i></a>
        <?php endif; ?>
        <?php if ($duzenleyebilir): ?>
        <a href="yeni.php?tekrar=<?= $id ?>" class="btn btn-outline-primary" title="Aynı ürünlerle yeni satış başlat (güncel fiyatlarla)">
            <i class="bi bi-arrow-repeat"></i> Tekrarla</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">← Satışlar</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <!-- Fatura -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <div><h5 class="mb-0"><?= $satis['tip'] === 'on_siparis' ? 'ÖN SİPARİŞ' : 'SATIŞ FATURASI' ?></h5><small><?= escH($satis['fatura_no']) ?></small></div>
                <div class="text-end">
                    <div><?= tarih($satis['tarih']) ?></div>
                    <span class="badge bg-<?= $satis['durum']==='tamamlandi'?'success':($satis['durum']==='iptal'?'danger':'warning') ?>">
                        <?= $satis['durum']==='tamamlandi'?'Tamamlandı':($satis['durum']==='iptal'?'İptal':'Bekliyor') ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-6">
                        <strong>SATICI</strong><br>
                        <?= escH(ayar('firma_adi','Regal Bayi')) ?><br>
                        <?php if (ayar('firma_telefon')): ?><small><?= escH(ayar('firma_telefon')) ?></small><br><?php endif; ?>
                        <?php if (ayar('firma_email')): ?><small class="text-muted"><?= escH(ayar('firma_email')) ?></small><br><?php endif; ?>
                        <?php if (ayar('firma_adres')): ?><small class="text-muted"><?= escH(ayar('firma_adres')) ?></small><?php endif; ?>
                    </div>
                    <div class="col-6 text-end">
                        <strong>MÜŞTERİ</strong><br>
                        <?= escH($satis['musteri_adi'] ?: 'Perakende') ?><br>
                        <small class="text-muted"><?= escH($satis['telefon']??'') ?></small>
                    </div>
                </div>
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr><th>#</th><th>Ürün</th><th>Miktar</th><th>Birim Fiyat</th><th>KDV</th><th>İndirim</th><th>Toplam</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kalemler as $i => $k): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td>
                        <strong><?= escH($k['urun_adi']) ?></strong>
                        <?php if ($k['tesir_satis']): ?>
                        <span class="badge bg-warning text-dark ms-1" title="Teşhir ürününden satıldı">
                            <i class="bi bi-shop-window"></i> Teşhir
                        </span>
                        <?php endif; ?>
                        <?php if ($k['iade_miktar'] > 0): ?>
                        <span class="badge bg-danger ms-1" title="Bu kalemden iade yapıldı">
                            <i class="bi bi-arrow-return-left"></i> <?= $k['iade_miktar'] ?> iade
                        </span>
                        <?php endif; ?>
                        <br><small><?= escH($k['kod']) ?></small>
                    </td>
                        <td><?= $k['miktar'] ?></td>
                        <td><?= para($k['birim_fiyat']) ?></td>
                        <td>%<?= $k['kdv_orani'] ?><br><small><?= para($k['kdv_tutar']) ?></small></td>
                        <td><?= $k['indirim']>0 ? para($k['indirim']) : '-' ?></td>
                        <td class="fw-bold"><?= para($k['toplam']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="6" class="text-end">Ara Toplam</td><td><?= para($satis['ara_toplam']) ?></td></tr>
                        <tr><td colspan="6" class="text-end">KDV Toplam</td><td><?= para($satis['kdv_toplam']) ?></td></tr>
                        <?php if ($satis['indirim_toplam']>0): ?>
                        <tr><td colspan="6" class="text-end">İndirim</td><td class="text-danger">- <?= para($satis['indirim_toplam']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="fw-bold fs-5"><td colspan="6" class="text-end">GENEL TOPLAM</td><td><?= para($satis['genel_toplam']) ?></td></tr>
                        <?php if ($satis['iade_toplam'] > 0): ?>
                        <tr class="text-danger"><td colspan="6" class="text-end">İadeler</td><td>- <?= para($satis['iade_toplam']) ?></td></tr>
                        <tr class="fw-bold"><td colspan="6" class="text-end">NET</td><td><?= para($satis['genel_toplam'] - $satis['iade_toplam']) ?></td></tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
                <div class="row mt-3 g-2">
                    <div class="col-md-6">
                        <div class="p-2 bg-light rounded">
                            <div class="small text-muted mb-1">Ödeme Tipi</div>
                            <strong><?= $satis['odeme_tipi']==='bolunmus' ? 'Bölünmüş Ödeme' : ucfirst(str_replace('_',' ',$satis['odeme_tipi'])) ?></strong>
                            <?php if ($taksitli): ?>
                            <span class="badge bg-primary ms-1"><?= $satis['taksit_sayisi'] ?> Taksit</span>
                            <div class="small text-muted mt-1">Aylık taksit: <strong><?= para($aylik_taksit) ?></strong></div>
                            <?php endif; ?>
                            <div class="small text-muted mt-1">Satışı yapan: <?= escH($satis['satici'] ?: '-') ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 bg-success bg-opacity-10 rounded text-center">
                            <div class="small text-muted">Ödenen</div>
                            <div class="fw-bold text-success"><?= para($satis['odenen_tutar']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 <?= $satis['kalan_tutar']>0 ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10' ?> rounded text-center">
                            <div class="small text-muted">Kalan</div>
                            <div class="fw-bold <?= $satis['kalan_tutar']>0 ? 'text-danger' : 'text-success' ?>">
                                <?= $satis['kalan_tutar']>0 ? para($satis['kalan_tutar']) : 'Tahsil Edildi ✓' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($satis['notlar']): ?>
                <div class="mt-2 p-2 bg-light rounded small">Not: <?= escH($satis['notlar']) ?></div>
                <?php endif; ?>

                <!-- İadeler -->
                <?php if (!empty($iadeler)): ?>
                <div class="mt-3">
                    <h6 class="fw-semibold text-danger"><i class="bi bi-arrow-return-left"></i> İadeler</h6>
                    <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr><th>Tarih</th><th>Ürünler</th><th>Tutar</th><th>Para İadesi</th><th>Borç Düşümü</th><th>İşleyen</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($iadeler as $iade): ?>
                        <tr>
                            <td><?= tarih($iade['tarih']) ?></td>
                            <td class="small">
                                <?php foreach ($iadeKalemleri[$iade['id']] ?? [] as $ik):
                                    $durumEtiket = ['saglam'=>'Sağlam','hasarli'=>'Hasarlı','tesir'=>'Teşhire'][$ik['urun_durum']] ?? $ik['urun_durum']; ?>
                                    <div><?= $ik['miktar'] ?>× <?= escH($ik['urun_adi']) ?>
                                        <span class="badge bg-<?= $ik['urun_durum']==='hasarli'?'danger':($ik['urun_durum']==='tesir'?'warning text-dark':'success') ?>"><?= $durumEtiket ?></span></div>
                                <?php endforeach; ?>
                                <?php if ($iade['aciklama']): ?><div class="text-muted"><?= escH($iade['aciklama']) ?></div><?php endif; ?>
                            </td>
                            <td class="fw-bold text-danger"><?= para($iade['tutar']) ?></td>
                            <td><?= $iade['nakit_iade'] > 0 ? para($iade['nakit_iade']) : '-' ?></td>
                            <td><?= $iade['borc_dusum'] > 0 ? para($iade['borc_dusum']) : '-' ?></td>
                            <td class="small"><?= escH($iade['ad_soyad'] ?: '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Taksit Takvimi -->
                <?php if (!empty($taksitPlani)): ?>
                <div class="mt-3">
                    <h6 class="fw-semibold"><i class="bi bi-calendar-week text-primary"></i> Taksit Ödeme Takvimi</h6>
                    <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr><th>#</th><th>Vade Tarihi</th><th>Tutar</th><th>Durum</th><th>Ödeme Tarihi</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($taksitPlani as $tp):
                            $gecmis = !$tp['odendi'] && strtotime($tp['vade_tarihi']) < time();
                        ?>
                        <tr class="<?= $gecmis ? 'table-danger' : ($tp['odendi'] ? 'table-success' : '') ?>">
                            <td><?= $tp['taksit_no'] ?>.</td>
                            <td><?= tarih($tp['vade_tarihi']) ?>
                                <?php if ($gecmis): ?><span class="badge bg-danger ms-1">Gecikmiş</span><?php endif; ?>
                            </td>
                            <td class="fw-bold"><?= para($tp['tutar']) ?></td>
                            <td>
                                <?php if ($tp['odendi']): ?>
                                    <span class="badge bg-success">Ödendi ✓</span>
                                <?php elseif ($gecmis): ?>
                                    <span class="badge bg-danger">Bekliyor</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Bekliyor</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $tp['odeme_tarihi'] ? tarih($tp['odeme_tarihi']) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Zaman çizelgesi -->
        <?php if (!empty($aktiviteler)): ?>
        <div class="card shadow-sm mt-3 no-print">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-clock-history text-primary"></i> İşlem Geçmişi</div>
            <div class="card-body py-2">
                <?php
                $aksiyonEtiket = [
                    'satis_olustur' => ['success', 'bi-plus-circle', 'Satış oluşturuldu'],
                    'satis_iptal'   => ['danger',  'bi-x-circle', 'Satış iptal edildi'],
                    'satis_iade'    => ['danger',  'bi-arrow-return-left', 'İade işlendi'],
                    'tahsilat'      => ['success', 'bi-cash-coin', 'Tahsilat alındı'],
                    'teslimat_guncelle' => ['info', 'bi-truck', 'Teslimat güncellendi'],
                    'on_siparis_teslim' => ['info', 'bi-box-arrow-right', 'Ön sipariş teslim edildi'],
                ];
                foreach ($aktiviteler as $a):
                    [$renk, $ikon, $etiket] = $aksiyonEtiket[$a['aksiyon']] ?? ['secondary', 'bi-dot', $a['aksiyon']];
                ?>
                <div class="d-flex align-items-start gap-2 border-bottom py-2">
                    <span class="badge bg-<?= $renk ?>"><i class="bi <?= $ikon ?>"></i></span>
                    <div class="flex-grow-1">
                        <span class="fw-semibold"><?= escH($etiket) ?></span>
                        <?php if ($a['detay']): ?><span class="text-muted small"> — <?= escH($a['detay']) ?></span><?php endif; ?>
                        <div class="small text-muted"><?= tarihSaat($a['created_at']) ?> • <?= escH($a['ad_soyad'] ?: 'Sistem') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sağ panel -->
    <div class="col-md-4 no-print">

        <!-- Kârlılık (yalnızca yönetici) -->
        <?php if ($karToplam !== null): ?>
        <div class="card shadow-sm mb-3 border-success">
            <div class="card-header bg-success text-white fw-semibold py-2">
                <i class="bi bi-graph-up-arrow"></i> Kârlılık (KDV hariç, iadeler düşülmüş)
            </div>
            <div class="card-body p-2">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted small">Net Satış</td><td class="text-end fw-bold"><?= para($karToplam['net']) ?></td></tr>
                    <tr><td class="text-muted small">Maliyet</td><td class="text-end"><?= para($karToplam['maliyet']) ?></td></tr>
                    <tr class="<?= $karToplam['kar'] >= 0 ? 'table-success' : 'table-danger' ?>">
                        <td class="small fw-semibold">Brüt Kâr</td>
                        <td class="text-end fw-bold"><?= para($karToplam['kar']) ?>
                            <?php if ($karToplam['net'] > 0): ?>
                            <span class="small">(%<?= number_format($karToplam['kar'] / $karToplam['net'] * 100, 1, ',', '.') ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php if ($karToplam['eksik']): ?>
                <div class="small text-muted mt-1"><i class="bi bi-info-circle"></i> Bazı kalemlerde maliyet kaydı yok (eski satış) — kâr eksik hesaplanmış olabilir.</div>
                <?php endif; ?>
                <div class="mt-2 small">
                    <div class="fw-semibold text-muted mb-1">Kalem bazında:</div>
                    <?php foreach ($kalemler as $k):
                        if ($k['birim_maliyet'] === null) continue;
                        $netAdet = (int)$k['miktar'] - (int)$k['iade_miktar'];
                        if ($netAdet <= 0) continue;
                        $kNet = ((float)$k['toplam'] - (float)$k['kdv_tutar']) / (int)$k['miktar'] * $netAdet;
                        $kKar = $kNet - (float)$k['birim_maliyet'] * $netAdet;
                    ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <span class="text-truncate me-2"><?= escH($k['urun_adi']) ?> ×<?= $netAdet ?></span>
                        <span class="fw-semibold <?= $kKar >= 0 ? 'text-success' : 'text-danger' ?>"><?= para($kKar) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Teslimat / Montaj -->
        <?php if ($satis['durum'] !== 'iptal'): ?>
        <div class="card shadow-sm mb-3 <?= $satis['teslimat_durum'] !== 'yok' ? 'border-info' : '' ?>">
            <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-truck text-info"></i> Teslimat / Montaj</span>
                <?php
                $tdEtiket = ['yok'=>'—','hazirlaniyor'=>'Hazırlanıyor','yolda'=>'Yolda','teslim_edildi'=>'Teslim Edildi'];
                $tdRenk   = ['yok'=>'secondary','hazirlaniyor'=>'warning text-dark','yolda'=>'info text-dark','teslim_edildi'=>'success'];
                ?>
                <span class="badge bg-<?= $tdRenk[$satis['teslimat_durum']] ?>"><?= $tdEtiket[$satis['teslimat_durum']] ?></span>
            </div>
            <div class="card-body p-2">
                <?php if ($satis['teslimat_durum'] !== 'yok' || $satis['teslimat_tarihi']): ?>
                <table class="table table-sm mb-2">
                    <tr><td class="text-muted small">Teslimat Tarihi</td><td class="fw-semibold"><?= tarih($satis['teslimat_tarihi']) ?></td></tr>
                    <?php if ($satis['teslimat_adresi']): ?>
                    <tr><td class="text-muted small">Adres</td><td class="small"><?= nl2br(escH($satis['teslimat_adresi'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($satis['montaj_tarihi']): ?>
                    <tr><td class="text-muted small">Montaj</td><td class="fw-semibold"><?= tarih($satis['montaj_tarihi']) ?>
                        <?php if ($satis['montaj_notu']): ?><div class="small text-muted"><?= escH($satis['montaj_notu']) ?></div><?php endif; ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php else: ?>
                <div class="text-muted small mb-2">Bu satış için teslimat planlanmadı.</div>
                <?php endif; ?>
                <?php if ($duzenleyebilir): ?>
                <button type="button" class="btn btn-sm btn-outline-info w-100" data-bs-toggle="collapse" data-bs-target="#teslimatForm">
                    <i class="bi bi-pencil"></i> Teslimat Bilgilerini Düzenle
                </button>
                <div class="collapse mt-2" id="teslimatForm">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="aksiyon" value="teslimat">
                        <label class="form-label small mb-1">Durum</label>
                        <select name="teslimat_durum" class="form-select form-select-sm mb-2">
                            <?php foreach ($tdEtiket as $tdk => $tdv): ?>
                            <option value="<?= $tdk ?>" <?= $satis['teslimat_durum'] === $tdk ? 'selected' : '' ?>><?= $tdv === '—' ? 'Teslimat yok' : $tdv ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label small mb-1">Teslimat Tarihi</label>
                        <input type="date" name="teslimat_tarihi" class="form-control form-control-sm mb-2" value="<?= escH($satis['teslimat_tarihi'] ?? '') ?>">
                        <label class="form-label small mb-1">Adres</label>
                        <textarea name="teslimat_adresi" class="form-control form-control-sm mb-2" rows="2"><?= escH($satis['teslimat_adresi'] ?? '') ?></textarea>
                        <label class="form-label small mb-1">Montaj Tarihi</label>
                        <input type="date" name="montaj_tarihi" class="form-control form-control-sm mb-2" value="<?= escH($satis['montaj_tarihi'] ?? '') ?>">
                        <input type="text" name="montaj_notu" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Montaj notu" value="<?= escH($satis['montaj_notu'] ?? '') ?>">
                        <button type="submit" class="btn btn-sm btn-info w-100">Kaydet</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Taksit Durumu (sadece taksitli satışlarda) -->
        <?php if ($taksitli): ?>
        <div class="card shadow-sm mb-3 border-primary">
            <div class="card-header bg-primary text-white fw-semibold py-2">
                <i class="bi bi-calendar-week"></i> Taksit Durumu
            </div>
            <div class="card-body">
                <!-- İlerleme çubuğu -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-success fw-semibold"><?= $odenen_taksit ?> taksit ödendi</span>
                        <span class="text-danger fw-semibold"><?= $kalan_taksit ?> taksit kaldı</span>
                    </div>
                    <div class="progress" style="height:12px; border-radius:8px">
                        <div class="progress-bar bg-success" style="width:<?= $taksit_ilerleme ?>%">
                            <?php if ($taksit_ilerleme >= 20): ?><?= $taksit_ilerleme ?>%<?php endif; ?>
                        </div>
                    </div>
                    <div class="text-center text-muted small mt-1">
                        <?= $odenen_taksit ?> / <?= count($taksitPlani) ?> taksit
                    </div>
                </div>

                <!-- Taksit detay tablosu -->
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <td class="text-muted small">Toplam Taksit</td>
                        <td class="fw-bold text-center"><?= count($taksitPlani) ?> taksit</td>
                    </tr>
                    <tr>
                        <td class="text-muted small">Aylık Taksit</td>
                        <td class="fw-bold text-primary text-center"><?= para($aylik_taksit) ?></td>
                    </tr>
                    <tr class="table-success">
                        <td class="small">Ödenen Taksit</td>
                        <td class="fw-bold text-success text-center"><?= $odenen_taksit ?> taksit</td>
                    </tr>
                    <tr class="<?= $kalan_taksit > 0 ? 'table-danger' : 'table-success' ?>">
                        <td class="small">Kalan Taksit</td>
                        <td class="fw-bold text-center <?= $kalan_taksit > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= $kalan_taksit > 0 ? $kalan_taksit . ' taksit' : '✓ Tamamlandı' ?>
                        </td>
                    </tr>
                    <?php if ($kalan_taksit > 0): ?>
                    <tr>
                        <td class="text-muted small">Kalan Tutar</td>
                        <td class="fw-bold text-danger text-center"><?= para($satis['kalan_tutar']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ödeme geçmişi -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Ödeme Geçmişi</div>
            <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr>
                    <th>Tarih</th>
                    <?php if ($taksitli): ?><th class="text-center">Taksit</th><?php endif; ?>
                    <th>Tutar</th>
                    <th>Tip</th>
                </tr></thead>
                <tbody>
                <?php if (empty($odemeler)): ?>
                    <tr><td colspan="<?= $taksitli ? 4 : 3 ?>" class="text-center text-muted">Ödeme yok</td></tr>
                <?php else: ?>
                <?php foreach ($odemeler as $o): ?>
                <tr>
                    <td><?= tarih($o['tarih']) ?></td>
                    <?php if ($taksitli): ?>
                    <td class="text-center">
                        <?php if ($o['taksit_no']): ?>
                            <span class="badge bg-primary"><?= $o['taksit_no'] ?>. taksit</span>
                        <?php else: ?>
                            <span class="text-muted small">Peşin</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td class="fw-bold text-success"><?= para($o['tutar']) ?></td>
                    <td><?= ucfirst(str_replace('_',' ',$o['odeme_tipi'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($satis['durum'] !== 'iptal' && $duzenleyebilir): ?>
        <!-- İade / Değişim -->
        <?php if ($satis['stok_dusuldu'] && !empty(array_filter($kalemler, fn($k) => (int)$k['miktar'] - (int)$k['iade_miktar'] > 0))): ?>
        <div class="d-grid gap-2 mb-2">
            <a href="iade.php?id=<?= $id ?>" class="btn btn-outline-warning">
                <i class="bi bi-arrow-return-left"></i> Kısmi İade / Değişim
            </a>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($satis['durum'] !== 'iptal' && $rol === 'yonetici'): ?>
        <!-- İptal -->
        <form method="post" action="iptal.php">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-outline-danger w-100"
                onclick="return confirm('Bu satışı iptal etmek istediğinize emin misiniz? Stok iadesi yapılacak<?= $satis['odenen_tutar']>0 ? ' ve ödenen tutar kasadan iade edilecek' : '' ?>.')">
                <i class="bi bi-x-circle"></i> Satışı İptal Et
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
