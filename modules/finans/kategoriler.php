<?php
// Kasa kategorileri yönetimi + aylık bütçe limiti
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Kasa Kategorileri';
$pdo = db();

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'ekle') {
        $ad = mb_substr(trim($_POST['ad'] ?? ''), 0, 100);
        $tip = in_array($_POST['tip'] ?? '', ['giris','cikis','ikisi'], true) ? $_POST['tip'] : 'cikis';
        $limit = trim($_POST['aylik_limit'] ?? '') !== '' ? round((float)$_POST['aylik_limit'], 2) : null;
        if ($ad === '') {
            $hata = 'Kategori adı zorunludur.';
        } else {
            $var = $pdo->prepare("SELECT id FROM kasa_kategoriler WHERE ad=?");
            $var->execute([$ad]);
            if ($var->fetch()) {
                $hata = 'Bu isimde bir kategori zaten var.';
            } else {
                $sira = (int)$pdo->query("SELECT COALESCE(MAX(sira),0)+1 FROM kasa_kategoriler")->fetchColumn();
                $pdo->prepare("INSERT INTO kasa_kategoriler (ad,tip,aylik_limit,sira) VALUES (?,?,?,?)")
                    ->execute([$ad, $tip, $limit, $sira]);
                logla('kasa_kategori_ekle', 'finans', (int)$pdo->lastInsertId(), $ad);
                flash('basari', 'Kategori eklendi.');
                header('Location: kategoriler.php'); exit;
            }
        }
    } elseif ($aksiyon === 'guncelle') {
        $id = (int)($_POST['id'] ?? 0);
        $limit = trim($_POST['aylik_limit'] ?? '') !== '' ? round((float)$_POST['aylik_limit'], 2) : null;
        $aktif = !empty($_POST['aktif']) ? 1 : 0;
        $pdo->prepare("UPDATE kasa_kategoriler SET aylik_limit=?, aktif=? WHERE id=? AND sistem=0")
            ->execute([$limit, $aktif, $id]);
        flash('basari', 'Kategori güncellendi.');
        header('Location: kategoriler.php'); exit;
    } elseif ($aksiyon === 'sil') {
        $id = (int)($_POST['id'] ?? 0);
        $kat = $pdo->prepare("SELECT * FROM kasa_kategoriler WHERE id=?");
        $kat->execute([$id]); $kat = $kat->fetch();
        if (!$kat) {
            flash('hata', 'Kategori bulunamadı.');
        } elseif ($kat['sistem']) {
            flash('hata', 'Sistem kategorileri silinemez.');
        } else {
            $kullanim = $pdo->prepare("SELECT COUNT(*) FROM kasa_hareketleri WHERE kategori=?");
            $kullanim->execute([$kat['ad']]);
            $kullanimSayisi = (int)$kullanim->fetchColumn();
            if ($kullanimSayisi > 0) {
                $hata = 'Bu kategori ' . $kullanimSayisi . ' hareket tarafından kullanılıyor, silinemez. Bunun yerine pasif yapın.';
            } else {
                $pdo->prepare("DELETE FROM kasa_kategoriler WHERE id=?")->execute([$id]);
                logla('kasa_kategori_sil', 'finans', $id, $kat['ad']);
                flash('basari', 'Kategori silindi.');
                header('Location: kategoriler.php'); exit;
            }
        }
    }
}

$kategoriler = $pdo->query("SELECT * FROM kasa_kategoriler ORDER BY sira, ad")->fetchAll();

// Bu ayki gider toplamları (limit takibi için)
$buAy = date('Y-m');
$aylikGiderler = $pdo->prepare("SELECT kategori, SUM(tutar) AS toplam FROM kasa_hareketleri
    WHERE tip='cikis' AND onay_durumu='onaylandi' AND DATE_FORMAT(tarih,'%Y-%m')=? GROUP BY kategori");
$aylikGiderler->execute([$buAy]);
$aylikGiderler = $aylikGiderler->fetchAll(PDO::FETCH_KEY_PAIR);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-tags text-primary"></i> Kasa Kategorileri</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Kasa & Finans</a>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">Kategoriler</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Kategori</th><th>Tip</th><th>Aylık Limit</th><th>Bu Ay Harcanan</th><th class="text-center">Aktif</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kategoriler as $k):
                        $harcanan = (float)($aylikGiderler[$k['ad']] ?? 0);
                        $asim = $k['aylik_limit'] && $harcanan > (float)$k['aylik_limit'];
                    ?>
                    <tr class="<?= $asim ? 'table-danger' : '' ?>">
                        <td>
                            <?= escH($k['ad']) ?>
                            <?php if ($k['sistem']): ?><span class="badge bg-secondary ms-1" title="Sistem kategorisi — elle seçilemez, silinemez">Sistem</span><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= ['giris'=>'Giriş','cikis'=>'Çıkış','ikisi'=>'İkisi de'][$k['tip']] ?></td>
                        <td>
                            <?php if (!$k['sistem']): ?>
                            <form method="post" class="d-flex gap-1 align-items-center">
                                <?= csrfField() ?>
                                <input type="hidden" name="aksiyon" value="guncelle">
                                <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                <input type="number" name="aylik_limit" class="form-control form-control-sm" style="width:110px" step="0.01" min="0" value="<?= $k['aylik_limit'] !== null ? $k['aylik_limit'] : '' ?>" placeholder="Sınırsız">
                                <input type="hidden" name="aktif" value="<?= $k['aktif'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary py-0" title="Kaydet"><i class="bi bi-check"></i></button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?= $asim ? 'text-danger fw-bold' : '' ?>">
                            <?= $harcanan > 0 ? para($harcanan) : '-' ?>
                            <?php if ($asim): ?><div class="small text-danger"><i class="bi bi-exclamation-triangle"></i> Limit aşıldı!</div><?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (!$k['sistem']): ?>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="aksiyon" value="guncelle">
                                <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                <input type="hidden" name="aylik_limit" value="<?= $k['aylik_limit'] !== null ? $k['aylik_limit'] : '' ?>">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox" name="aktif" value="1" onchange="this.form.submit()" <?= $k['aktif'] ? 'checked' : '' ?>>
                                </div>
                            </form>
                            <?php else: ?>
                            <i class="bi bi-check-circle text-success"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$k['sistem']): ?>
                            <form method="post" onsubmit="return confirm('Bu kategori silinsin mi?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="aksiyon" value="sil">
                                <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
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
            <div class="card-header bg-white fw-semibold py-2">Yeni Kategori</div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksiyon" value="ekle">
                    <label class="form-label small fw-semibold mb-1">Ad</label>
                    <input type="text" name="ad" class="form-control mb-2" maxlength="100" required placeholder="Örn: Yakıt">
                    <label class="form-label small fw-semibold mb-1">Tip</label>
                    <select name="tip" class="form-select mb-2">
                        <option value="cikis">Çıkış (Gider)</option>
                        <option value="giris">Giriş (Gelir)</option>
                        <option value="ikisi">İkisi de</option>
                    </select>
                    <label class="form-label small fw-semibold mb-1">Aylık Bütçe Limiti (₺) <span class="text-muted">(opsiyonel)</span></label>
                    <input type="number" name="aylik_limit" class="form-control mb-2" step="0.01" min="0" placeholder="Sınırsız">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> Ekle</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
