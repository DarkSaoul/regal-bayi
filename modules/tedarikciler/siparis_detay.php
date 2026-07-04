<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$s = $pdo->prepare("SELECT s.*, t.ad AS tedarikci_adi FROM tedarikci_siparisleri s JOIN tedarikciler t ON s.tedarikci_id=t.id WHERE s.id=?");
$s->execute([$id]); $s = $s->fetch();
if (!$s) { flash('hata', 'Sipariş bulunamadı.'); header('Location: siparisler.php'); exit; }
$sayfa_basligi = 'Sipariş ' . $s['siparis_no'];

// ── Aksiyonlar ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    // Teslim al → stok girişi + tedarikçi borcu
    if ($aksiyon === 'teslim') {
        $pdo->beginTransaction();
        try {
            $row = $pdo->prepare("SELECT * FROM tedarikci_siparisleri WHERE id=? FOR UPDATE");
            $row->execute([$id]); $sip = $row->fetch();
            if (!$sip || $sip['durum'] !== 'bekliyor') throw new RuntimeException('Bu sipariş teslim alınamaz (zaten işlenmiş veya iptal).');

            $kalemler = $pdo->prepare("SELECT * FROM siparis_kalemleri WHERE siparis_id=?");
            $kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();
            if (empty($kalemler)) throw new RuntimeException('Siparişte kalem yok.');

            $borcArtis = 0;
            foreach ($kalemler as $k) {
                $maliyet = (float)$k['birim_fiyat'] > 0 ? (float)$k['birim_fiyat'] : null;
                stokGuncelle((int)$k['urun_id'], (int)$k['miktar'], 'giris', $sip['siparis_no'],
                             'Sipariş teslim: ' . $sip['siparis_no'], (int)$sip['tedarikci_id'], $maliyet);
                if ($maliyet !== null) $borcArtis += $k['miktar'] * $maliyet;
            }
            if ($borcArtis > 0) {
                $pdo->prepare("UPDATE tedarikciler SET toplam_borc = toplam_borc + ? WHERE id=?")
                    ->execute([round($borcArtis, 2), $sip['tedarikci_id']]);
            }
            $pdo->prepare("UPDATE tedarikci_siparisleri SET durum='teslim_alindi', teslim_tarihi=NOW() WHERE id=?")->execute([$id]);
            $pdo->commit();
            logla('siparis_teslim', 'tedarikciler', $id, 'Sipariş teslim alındı: ' . $sip['siparis_no'] . ' | borç +' . para($borcArtis));
            flash('basari', 'Sipariş teslim alındı, stoklar güncellendi' . ($borcArtis>0 ? ' ve ' . para($borcArtis) . ' tedarikçi borcu eklendi.' : '.'));
        } catch (RuntimeException $e) { $pdo->rollBack(); flash('hata', $e->getMessage()); }
        catch (Exception $e) { $pdo->rollBack(); flash('hata', 'Teslim sırasında hata: ' . $e->getMessage()); }
        header('Location: siparis_detay.php?id=' . $id); exit;
    }

    // İptal
    if ($aksiyon === 'iptal') {
        $upd = $pdo->prepare("UPDATE tedarikci_siparisleri SET durum='iptal' WHERE id=? AND durum='bekliyor'");
        $upd->execute([$id]);
        if ($upd->rowCount() > 0) { logla('siparis_iptal','tedarikciler',$id,'Sipariş iptal: '.$s['siparis_no']); flash('basari', 'Sipariş iptal edildi.'); }
        else flash('hata', 'Yalnızca bekleyen siparişler iptal edilebilir.');
        header('Location: siparis_detay.php?id=' . $id); exit;
    }
}

$kalemler = $pdo->prepare("SELECT sk.*, u.ad AS urun_adi, u.kod, u.stok_adedi FROM siparis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id WHERE sk.siparis_id=?");
$kalemler->execute([$id]); $kalemler = $kalemler->fetchAll();

$renk = $s['durum']==='teslim_alindi'?'success':($s['durum']==='iptal'?'danger':'warning');
$lbl  = $s['durum']==='teslim_alindi'?'Teslim Alındı':($s['durum']==='iptal'?'İptal':'Bekliyor');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4><i class="bi bi-clipboard-check text-primary"></i> <?= escH($s['siparis_no']) ?>
            <span class="badge bg-<?= $renk ?> ms-2"><?= $lbl ?></span></h4>
        <small class="text-muted"><i class="bi bi-truck"></i> <?= escH($s['tedarikci_adi']) ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="siparisler.php" class="btn btn-outline-secondary btn-sm">← Siparişler</a>
        <?php if ($s['durum']==='bekliyor'): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Sipariş teslim alınacak: ürünler stoğa eklenecek ve maliyetli kalemler tedarikçi borcuna yansıyacak. Onaylıyor musunuz?')">
            <?= csrfField() ?><input type="hidden" name="aksiyon" value="teslim">
            <button class="btn btn-success"><i class="bi bi-box-arrow-in-down"></i> Teslim Al</button>
        </form>
        <form method="post" class="d-inline" onsubmit="return confirm('Sipariş iptal edilecek. Emin misiniz?')">
            <?= csrfField() ?><input type="hidden" name="aksiyon" value="iptal">
            <button class="btn btn-outline-danger"><i class="bi bi-x-circle"></i> İptal Et</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary"></i> Sipariş Bilgileri</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted ps-3">Tedarikçi</td><td><a href="detay.php?id=<?= $s['tedarikci_id'] ?>"><?= escH($s['tedarikci_adi']) ?></a></td></tr>
                    <tr><td class="text-muted ps-3">Sipariş Tarihi</td><td><?= tarih($s['tarih']) ?></td></tr>
                    <tr><td class="text-muted ps-3">Beklenen Teslim</td><td><?= $s['beklenen_tarih']?tarih($s['beklenen_tarih']):'-' ?></td></tr>
                    <?php if ($s['teslim_tarihi']): ?>
                    <tr><td class="text-muted ps-3">Teslim Tarihi</td><td><?= tarihSaat($s['teslim_tarihi']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted ps-3">Toplam Tutar</td><td class="fw-bold"><?= para($s['toplam_tutar']) ?></td></tr>
                </table>
                <?php if ($s['notlar']): ?>
                <div class="mx-3 my-2 p-2 bg-light rounded small"><i class="bi bi-sticky text-warning"></i> <?= escH($s['notlar']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-box-seam text-primary"></i> Sipariş Kalemleri</div>
            <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr>
                    <th>Ürün</th><th class="text-center">Miktar</th><th class="text-end">Birim Fiyat</th>
                    <th class="text-end">Satır Toplamı</th><th class="text-center">Mevcut Stok</th>
                </tr></thead>
                <tbody>
                <?php foreach ($kalemler as $k): ?>
                <tr>
                    <td><strong><?= escH($k['urun_adi']) ?></strong><br><small class="text-muted"><?= escH($k['kod']) ?></small></td>
                    <td class="text-center fw-bold"><?= $k['miktar'] ?></td>
                    <td class="text-end"><?= para($k['birim_fiyat']) ?></td>
                    <td class="text-end fw-bold"><?= para($k['miktar']*$k['birim_fiyat']) ?></td>
                    <td class="text-center"><span class="badge bg-secondary"><?= $k['stok_adedi'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <tr class="fw-bold table-light">
                    <td colspan="3" class="text-end">TOPLAM</td>
                    <td class="text-end"><?= para($s['toplam_tutar']) ?></td><td></td>
                </tr>
                </tbody>
            </table>
            </div>
            </div>
        </div>
        <?php if ($s['durum']==='teslim_alindi'): ?>
        <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-circle"></i> Bu sipariş teslim alındı; ürünler stoğa işlendi.</div>
        <?php elseif ($s['durum']==='bekliyor'): ?>
        <div class="alert alert-info mt-3 mb-0"><i class="bi bi-info-circle"></i> Sipariş henüz teslim alınmadı. "Teslim Al" ile ürünler stoğa eklenecek.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
