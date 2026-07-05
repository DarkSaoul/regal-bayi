<?php
// Tekrarlayan gider şablonları (kira, elektrik/su vb. her ay/hafta tekrar eden giderler)
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
moduleKontrol('gider_sablonlari', 'Tekrarlayan Gider Şablonları');
$sayfa_basligi = 'Tekrarlayan Giderler';
$pdo = db();

$kategoriler = $pdo->query("SELECT * FROM kasa_kategoriler WHERE aktif=1 AND tip IN ('cikis','ikisi') ORDER BY sira, ad")->fetchAll();

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'ekle') {
        $ad = mb_substr(trim($_POST['ad'] ?? ''), 0, 150);
        $kategoriId = (int)($_POST['kategori_id'] ?? 0);
        $tutar = round((float)($_POST['tutar'] ?? 0), 2);
        $periyot = in_array($_POST['periyot'] ?? '', ['aylik','haftalik'], true) ? $_POST['periyot'] : 'aylik';
        $gun = max(1, min($periyot === 'aylik' ? 28 : 7, (int)($_POST['gun'] ?? 1)));
        if ($ad === '' || !$kategoriId || $tutar <= 0) {
            $hata = 'Ad, kategori ve tutar zorunludur.';
        } else {
            $pdo->prepare("INSERT INTO gider_sablonlari (ad,kategori_id,tutar,periyot,gun,kullanici_id) VALUES (?,?,?,?,?,?)")
                ->execute([$ad, $kategoriId, $tutar, $periyot, $gun, $_SESSION['kullanici_id']]);
            flash('basari', 'Gider şablonu eklendi.');
            header('Location: gider_sablonlari.php'); exit;
        }
    } elseif ($aksiyon === 'sil') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM gider_sablonlari WHERE id=?")->execute([$id]);
        flash('basari', 'Şablon silindi.');
        header('Location: gider_sablonlari.php'); exit;
    } elseif ($aksiyon === 'aktiflik') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE gider_sablonlari SET aktif = NOT aktif WHERE id=?")->execute([$id]);
        header('Location: gider_sablonlari.php'); exit;
    } elseif ($aksiyon === 'olustur') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $pdo->prepare("SELECT gs.*, kk.ad AS kategori_adi FROM gider_sablonlari gs JOIN kasa_kategoriler kk ON gs.kategori_id=kk.id WHERE gs.id=? AND gs.aktif=1");
        $s->execute([$id]); $s = $s->fetch();
        if (!$s) {
            flash('hata', 'Şablon bulunamadı.');
        } else {
            $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,hesap,tutar,aciklama,kategori,onay_durumu,onaylayan_id,kullanici_id) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([date('Y-m-d'), 'cikis', 'kasa', $s['tutar'], $s['ad'] . ' (tekrarlayan gider)', $s['kategori_adi'], 'onaylandi', $_SESSION['kullanici_id'], $_SESSION['kullanici_id']]);
            $pdo->prepare("UPDATE gider_sablonlari SET son_olusturma=? WHERE id=?")->execute([date('Y-m-d'), $id]);
            logla('gider_sablon_olustur', 'finans', $id, $s['ad'] . ' | ' . para($s['tutar']));
            flash('basari', 'Gider kaydı oluşturuldu: ' . para($s['tutar']));
        }
        header('Location: gider_sablonlari.php'); exit;
    }
}

$sablonlar = $pdo->query("SELECT gs.*, kk.ad AS kategori_adi FROM gider_sablonlari gs
    JOIN kasa_kategoriler kk ON gs.kategori_id=kk.id ORDER BY gs.aktif DESC, gs.ad")->fetchAll();
$vadesiGelenIdler = array_column(giderSablonlariVadesiGelenler(), 'id');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-arrow-repeat text-primary"></i> Tekrarlayan Giderler</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Kasa & Finans</a>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">Şablonlar</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Ad</th><th>Kategori</th><th>Tutar</th><th>Periyot</th><th>Son Oluşturma</th><th class="text-center">Aktif</th><th style="width:160px">İşlem</th></tr></thead>
                    <tbody>
                    <?php if (empty($sablonlar)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Şablon yok</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sablonlar as $s): $vadeGeldi = in_array($s['id'], $vadesiGelenIdler); ?>
                    <tr class="<?= $vadeGeldi ? 'table-warning' : '' ?>">
                        <td><?= escH($s['ad']) ?><?php if ($vadeGeldi): ?><span class="badge bg-warning text-dark ms-1">Vadesi Geldi</span><?php endif; ?></td>
                        <td class="small text-muted"><?= escH($s['kategori_adi']) ?></td>
                        <td class="fw-bold"><?= para($s['tutar']) ?></td>
                        <td class="small">
                            <?= $s['periyot']==='aylik' ? 'Aylık — ayın ' . $s['gun'] . '.' : 'Haftalık — ' . ['','Pzt','Sal','Çar','Per','Cum','Cmt','Paz'][$s['gun']] ?>
                        </td>
                        <td class="small"><?= $s['son_olusturma'] ? tarih($s['son_olusturma']) : '-' ?></td>
                        <td class="text-center">
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="aksiyon" value="aktiflik">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox" onchange="this.form.submit()" <?= $s['aktif'] ? 'checked' : '' ?>>
                                </div>
                            </form>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if ($vadeGeldi): ?>
                                <form method="post" onsubmit="return confirm('<?= para($s['tutar']) ?> tutarında gider kaydı oluşturulsun mu?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="aksiyon" value="olustur">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">Şimdi Oluştur</button>
                                </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Bu şablon silinsin mi?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="aksiyon" value="sil">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">Yeni Şablon</div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksiyon" value="ekle">
                    <label class="form-label small fw-semibold mb-1">Ad</label>
                    <input type="text" name="ad" class="form-control mb-2" maxlength="150" required placeholder="Örn: Dükkan Kirası">
                    <label class="form-label small fw-semibold mb-1">Kategori</label>
                    <select name="kategori_id" class="form-select mb-2" required>
                        <?php foreach ($kategoriler as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= escH($k['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label small fw-semibold mb-1">Tutar (₺)</label>
                    <input type="number" name="tutar" class="form-control mb-2" step="0.01" min="0.01" required>
                    <label class="form-label small fw-semibold mb-1">Periyot</label>
                    <select name="periyot" id="periyotSec" class="form-select mb-2" onchange="periyotDegisti()">
                        <option value="aylik">Aylık</option>
                        <option value="haftalik">Haftalık</option>
                    </select>
                    <div id="gunAylik">
                        <label class="form-label small fw-semibold mb-1">Ayın Kaçıncı Günü</label>
                        <input type="number" name="gun" class="form-control mb-2" min="1" max="28" value="1">
                    </div>
                    <div id="gunHaftalik" style="display:none">
                        <label class="form-label small fw-semibold mb-1">Haftanın Günü</label>
                        <select name="gun_haftalik" class="form-select mb-2">
                            <option value="1">Pazartesi</option><option value="2">Salı</option><option value="3">Çarşamba</option>
                            <option value="4">Perşembe</option><option value="5">Cuma</option><option value="6">Cumartesi</option><option value="7">Pazar</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> Ekle</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function periyotDegisti() {
    const aylik = document.getElementById('periyotSec').value === 'aylik';
    document.getElementById('gunAylik').style.display = aylik ? '' : 'none';
    document.getElementById('gunHaftalik').style.display = aylik ? 'none' : '';
    document.querySelector('#gunAylik input').name = aylik ? 'gun' : '_gun_disabled';
    document.querySelector('#gunHaftalik select').name = aylik ? '_gun_disabled2' : 'gun';
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
