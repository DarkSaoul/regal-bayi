<?php
// Kısmi iade / ürün değişimi — kalem ve miktar bazlı iade
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$pdo = db();

// Kasiyer için iade yetkisi kullanıcı bazlı kısıtlanabilir (yönetici her zaman yapabilir)
if (($_SESSION['rol'] ?? '') === 'kasiyer') {
    $izin = $pdo->prepare("SELECT izin_iade_yapabilir FROM kullanicilar WHERE id=?");
    $izin->execute([$_SESSION['kullanici_id']]);
    if ((int)$izin->fetchColumn() === 0) {
        flash('hata', 'İade/değişim işlemi yapma yetkiniz bulunmuyor.');
        header('Location: ' . BASE_URL . '/modules/satislar/'); exit;
    }
}

$id = (int)($_REQUEST['id'] ?? 0);

$satis = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id WHERE s.id=?");
$satis->execute([$id]); $satis = $satis->fetch();
if (!$satis) { flash('hata', 'Satış bulunamadı.'); header('Location: index.php'); exit; }
if ($satis['durum'] === 'iptal') { flash('hata', 'İptal edilmiş satışta iade yapılamaz.'); header('Location: detay.php?id=' . $id); exit; }
if (!$satis['stok_dusuldu']) { flash('hata', 'Teslim edilmemiş ön siparişte iade yapılamaz — satışı iptal edin.'); header('Location: detay.php?id=' . $id); exit; }

$kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod, u.seri_no_takip FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.satis_id=?");
$kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $adetler   = $_POST['iade_adet'] ?? [];
    $durumlar  = $_POST['iade_durum'] ?? [];
    $yontem    = in_array($_POST['iade_yontem'] ?? '', ['nakit','kredi_karti','havale']) ? $_POST['iade_yontem'] : 'nakit';
    $aciklama  = mb_substr(trim($_POST['aciklama'] ?? ''), 0, 255);
    $degisim   = !empty($_POST['degisim_baslat']);

    $pdo->beginTransaction();
    try {
        // Satışı kilitle — çift iade önleme
        $sk = $pdo->prepare("SELECT * FROM satislar WHERE id=? AND durum!='iptal' FOR UPDATE");
        $sk->execute([$id]); $satisRow = $sk->fetch();
        if (!$satisRow) throw new RuntimeException('Satış bulunamadı veya iptal edilmiş.');

        $kStmt = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi FROM satis_kalemleri sk JOIN urunler u ON u.id=sk.urun_id WHERE sk.satis_id=? FOR UPDATE");
        $kStmt->execute([$id]); $dbKalemler = $kStmt->fetchAll();

        $iadeToplam = 0; $iadeSatirlar = [];
        foreach ($dbKalemler as $k) {
            $adet = max(0, (int)($adetler[$k['id']] ?? 0));
            if ($adet === 0) continue;
            $kalanAdet = (int)$k['miktar'] - (int)$k['iade_miktar'];
            if ($adet > $kalanAdet) {
                throw new RuntimeException('"' . $k['urun_adi'] . '" için en fazla ' . $kalanAdet . ' adet iade edilebilir.');
            }
            $durum = in_array($durumlar[$k['id']] ?? '', ['saglam','hasarli','tesir']) ? $durumlar[$k['id']] : 'saglam';
            // Kaleme düşen iade tutarı: satır toplamının (KDV ve indirim yansımış) adet payı
            $tutar = round((float)$k['toplam'] / (int)$k['miktar'] * $adet, 2);
            $iadeToplam += $tutar;
            $iadeSatirlar[] = ['kalem' => $k, 'adet' => $adet, 'tutar' => $tutar, 'durum' => $durum];
        }
        if (empty($iadeSatirlar)) throw new RuntimeException('İade edilecek ürün seçmediniz.');
        $iadeToplam = round($iadeToplam, 2);

        // Önce açık borçtan düş, artan kısım para iadesi olur
        $borcDusum  = min($iadeToplam, (float)$satisRow['kalan_tutar']);
        $paraIade   = round($iadeToplam - $borcDusum, 2);

        // İade başlığı
        $pdo->prepare("INSERT INTO satis_iadeleri (satis_id,tarih,tutar,nakit_iade,borc_dusum,aciklama,kullanici_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$id, date('Y-m-d'), $iadeToplam, $paraIade, $borcDusum,
                       ($aciklama ? $aciklama . ' | ' : '') . 'İade yöntemi: ' . $yontem, $_SESSION['kullanici_id']]);
        $iade_id = (int)$pdo->lastInsertId();

        foreach ($iadeSatirlar as $s) {
            $k = $s['kalem']; $adet = $s['adet'];
            $pdo->prepare("INSERT INTO satis_iade_kalemleri (iade_id,satis_kalem_id,urun_id,miktar,tutar,urun_durum) VALUES (?,?,?,?,?,?)")
                ->execute([$iade_id, $k['id'], $k['urun_id'], $adet, $s['tutar'], $s['durum']]);
            $pdo->prepare("UPDATE satis_kalemleri SET iade_miktar = iade_miktar + ? WHERE id=?")
                ->execute([$adet, $k['id']]);

            // Stok: her durumda iade girişi; hasarlıysa ardından fire çıkışı (kayıp raporlanır)
            stokGuncelle($k['urun_id'], $adet, 'iade_giris', $satisRow['fatura_no'], 'Müşteri iadesi (' . $s['durum'] . ')');
            if ($s['durum'] === 'hasarli') {
                stokGuncelle($k['urun_id'], -$adet, 'fire', $satisRow['fatura_no'], 'Hasarlı müşteri iadesi',
                             null, $k['birim_maliyet'] !== null ? (float)$k['birim_maliyet'] : null);
            } elseif ($s['durum'] === 'tesir') {
                $pdo->prepare("UPDATE urunler SET tesir_adedi = tesir_adedi + ? WHERE id=?")->execute([$adet, $k['urun_id']]);
            }

            // Bu satışa bağlı satılmış seri no'ları güncelle
            $seriDurum = ['saglam' => 'stokta', 'hasarli' => 'ariza', 'tesir' => 'tesirde'][$s['durum']];
            $tesirTarih = $s['durum'] === 'tesir' ? date('Y-m-d H:i:s') : null;
            $pdo->prepare("UPDATE seri_numaralari SET durum=?, satis_id=NULL, tesir_tarihi=? WHERE urun_id=? AND satis_id=? AND durum='satildi' LIMIT " . $adet)
                ->execute([$seriDurum, $tesirTarih, $k['urun_id'], $id]);
        }

        // Satış tutarlarını güncelle
        $yeniIadeToplam = round((float)$satisRow['iade_toplam'] + $iadeToplam, 2);
        $yeniOdenen     = round((float)$satisRow['odenen_tutar'] - $paraIade, 2);
        $yeniKalan      = max(0, round((float)$satisRow['genel_toplam'] - $yeniIadeToplam - $yeniOdenen, 2));
        $yeniDurum      = ($satisRow['durum'] === 'bekliyor' && $yeniKalan <= 0.005) ? 'tamamlandi' : $satisRow['durum'];
        $pdo->prepare("UPDATE satislar SET iade_toplam=?, odenen_tutar=?, kalan_tutar=?, durum=? WHERE id=?")
            ->execute([$yeniIadeToplam, $yeniOdenen, $yeniKalan, $yeniDurum, $id]);

        // Ödenmemiş taksitleri sondan azalt/sil (borçtan düşülen kısım kadar)
        if ($borcDusum > 0) {
            $tpStmt = $pdo->prepare("SELECT id, tutar FROM taksit_plani WHERE satis_id=? AND odendi=0 ORDER BY taksit_no DESC FOR UPDATE");
            $tpStmt->execute([$id]);
            $kalanDusum = $borcDusum;
            foreach ($tpStmt->fetchAll() as $tp) {
                if ($kalanDusum <= 0.005) break;
                if ((float)$tp['tutar'] <= $kalanDusum + 0.005) {
                    $pdo->prepare("DELETE FROM taksit_plani WHERE id=?")->execute([$tp['id']]);
                    $kalanDusum = round($kalanDusum - (float)$tp['tutar'], 2);
                } else {
                    $pdo->prepare("UPDATE taksit_plani SET tutar = tutar - ? WHERE id=?")->execute([$kalanDusum, $tp['id']]);
                    $kalanDusum = 0;
                }
            }
        }

        // Para iadesi: nakit → kasa hesabından, kart/havale → banka hesabından çıkar
        if ($paraIade > 0) {
            $hesap = $yontem === 'nakit' ? 'kasa' : 'banka';
            $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,hesap,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([date('Y-m-d'), 'cikis', $hesap, $paraIade, 'Kısmi iade: ' . $satisRow['fatura_no'], 'İade', $_SESSION['kullanici_id']]);
        }

        musteriBorcuYenile($satisRow['musteri_id'] ? (int)$satisRow['musteri_id'] : null);

        $pdo->commit();
        logla('satis_iade', 'satislar', $id, 'Fatura: ' . $satisRow['fatura_no']
            . ' | İade: ' . para($iadeToplam)
            . ($paraIade > 0 ? ' | Para iadesi (' . $yontem . '): ' . para($paraIade) : '')
            . ($borcDusum > 0 ? ' | Borç düşümü: ' . para($borcDusum) : ''));
        flash('basari', 'İade işlendi. Toplam: ' . para($iadeToplam)
            . ($paraIade > 0 ? ' — ' . para($paraIade) . ' para iadesi yapıldı (' . str_replace('_', ' ', $yontem) . ').' : '')
            . ($borcDusum > 0 ? ' — ' . para($borcDusum) . ' açık borçtan düşüldü.' : ''));
        if ($degisim) { header('Location: yeni.php?degisim=' . $id); exit; }
        header('Location: detay.php?id=' . $id); exit;
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        $hata = $e->getMessage();
    } catch (Exception $e) {
        $pdo->rollBack();
        $hata = 'İade sırasında hata: ' . $e->getMessage();
    }
    // Hata sonrası güncel kalemleri yeniden çek
    $kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod, u.seri_no_takip FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.satis_id=?");
    $kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();
}

$sayfa_basligi = 'İade: ' . $satis['fatura_no'];
require_once __DIR__ . '/../../includes/header.php';

$iadeEdilebilir = array_filter($kalemler, fn($k) => (int)$k['miktar'] - (int)$k['iade_miktar'] > 0);
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-arrow-return-left text-danger"></i> Kısmi İade — <?= escH($satis['fatura_no']) ?></h4>
    <a href="detay.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">← Satış Detayı</a>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-cart-x text-danger"></i> İade Edilecek Ürünler
                <span class="text-muted small ms-2">Müşteri: <?= escH($satis['musteri_adi'] ?: 'Perakende') ?> • <?= tarih($satis['tarih']) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ürün</th>
                            <th class="text-center">Satılan</th>
                            <th class="text-center">Önceki İade</th>
                            <th class="text-center" style="width:110px">İade Adedi</th>
                            <th style="width:170px">Ürün Durumu</th>
                            <th class="text-end">Birim İade</th>
                            <th class="text-end">İade Tutarı</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($iadeEdilebilir)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Bu satışta iade edilebilecek ürün kalmadı.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($kalemler as $k):
                        $kalanAdet = (int)$k['miktar'] - (int)$k['iade_miktar'];
                        $birim = (int)$k['miktar'] > 0 ? (float)$k['toplam'] / (int)$k['miktar'] : 0;
                    ?>
                    <tr class="<?= $kalanAdet <= 0 ? 'table-secondary' : '' ?>">
                        <td>
                            <strong><?= escH($k['urun_adi']) ?></strong>
                            <?php if ($k['seri_no_takip']): ?><span class="badge bg-info text-dark ms-1">Seri No</span><?php endif; ?>
                            <br><small class="text-muted"><?= escH($k['kod']) ?></small>
                        </td>
                        <td class="text-center"><?= $k['miktar'] ?></td>
                        <td class="text-center"><?= $k['iade_miktar'] ?: '-' ?></td>
                        <td class="text-center">
                            <?php if ($kalanAdet > 0): ?>
                            <input type="number" name="iade_adet[<?= $k['id'] ?>]" class="form-control form-control-sm text-center iade-adet"
                                   min="0" max="<?= $kalanAdet ?>" value="0" data-birim="<?= $birim ?>">
                            <?php else: ?><span class="text-muted">Tamamı iade</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($kalanAdet > 0): ?>
                            <select name="iade_durum[<?= $k['id'] ?>]" class="form-select form-select-sm">
                                <option value="saglam">✅ Sağlam → Stoğa</option>
                                <option value="tesir">🏬 Kutusu açık → Teşhire</option>
                                <option value="hasarli">🔴 Hasarlı → Fire</option>
                            </select>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= para($birim) ?></td>
                        <td class="text-end fw-bold text-danger iade-satir-toplam">-</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold mb-1">Para İadesi Yöntemi</label>
                        <select name="iade_yontem" class="form-select form-select-sm">
                            <option value="nakit">💵 Nakit (kasadan çıkar)</option>
                            <option value="kredi_karti">💳 Kart iadesi</option>
                            <option value="havale">🏦 Havale</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-semibold mb-1">Açıklama</label>
                        <input type="text" name="aciklama" class="form-control form-control-sm" maxlength="255" placeholder="İade nedeni...">
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mt-3 border-danger">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="text-muted small">Toplam İade Tutarı</div>
                    <div class="fs-4 fw-bold text-danger" id="iadeToplam">0,00 ₺</div>
                    <div class="small text-muted">Önce açık borçtan düşülür (<?= para($satis['kalan_tutar']) ?> açık), artan kısım para iadesi olur.</div>
                </div>
                <div class="d-flex gap-2">
                    <?php if (!empty($iadeEdilebilir)): ?>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Seçilen ürünler iade edilecek. Onaylıyor musunuz?')">
                        <i class="bi bi-arrow-return-left"></i> İadeyi İşle
                    </button>
                    <button type="submit" name="degisim_baslat" value="1" class="btn btn-info"
                            onclick="return confirm('İade işlenecek ve değişim için yeni satış ekranı açılacak. Onaylıyor musunuz?')"
                            title="İadeyi işler ve aynı müşteriyle bağlantılı yeni satış (değişim) başlatır">
                        <i class="bi bi-arrow-left-right"></i> İade + Değişim Başlat
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-info-circle text-primary"></i> Satış Özeti</div>
            <div class="card-body p-2">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Genel Toplam</td><td class="text-end fw-bold"><?= para($satis['genel_toplam']) ?></td></tr>
                    <tr><td class="text-muted">Ödenen</td><td class="text-end text-success"><?= para($satis['odenen_tutar']) ?></td></tr>
                    <tr><td class="text-muted">Açık Borç</td><td class="text-end text-danger"><?= para($satis['kalan_tutar']) ?></td></tr>
                    <?php if ($satis['iade_toplam'] > 0): ?>
                    <tr><td class="text-muted">Önceki İadeler</td><td class="text-end text-danger">- <?= para($satis['iade_toplam']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <div class="alert alert-light border small mt-3">
            <div class="fw-semibold mb-1"><i class="bi bi-lightbulb text-warning"></i> Ürün durumu ne yapar?</div>
            <div>✅ <strong>Sağlam:</strong> stok adedine geri eklenir.</div>
            <div>🏬 <strong>Kutusu açık:</strong> stoğa girer ve teşhir sayacına eklenir.</div>
            <div>🔴 <strong>Hasarlı:</strong> iade girişi + fire çıkışı yazılır (kayıp raporunda görünür), satılabilir stoğa girmez.</div>
            <div class="mt-1">Seri no'lu ürünlerde bu satışa bağlı seriler otomatik güncellenir.</div>
        </div>
    </div>
</div>

<script>
function iadeHesapla() {
    let t = 0;
    document.querySelectorAll('.iade-adet').forEach(i => {
        const adet = parseInt(i.value) || 0;
        const birim = parseFloat(i.dataset.birim) || 0;
        const satir = adet * birim;
        t += satir;
        i.closest('tr').querySelector('.iade-satir-toplam').textContent =
            satir > 0 ? new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2}).format(satir) + ' ₺' : '-';
    });
    document.getElementById('iadeToplam').textContent =
        new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2}).format(t) + ' ₺';
}
document.addEventListener('input', e => { if (e.target.classList.contains('iade-adet')) iadeHesapla(); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
