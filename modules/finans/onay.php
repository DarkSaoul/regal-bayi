<?php
// Kasiyerin girdiği, onay limitini aşan giderlerin onay/red işlemleri
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Gider Onayları';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $id = (int)($_POST['id'] ?? 0);
    $aksiyon = $_POST['aksiyon'] ?? '';
    $h = $pdo->prepare("SELECT * FROM kasa_hareketleri WHERE id=? AND onay_durumu='bekliyor' FOR UPDATE");

    $pdo->beginTransaction();
    try {
        $h->execute([$id]); $h = $h->fetch();
        if (!$h) throw new RuntimeException('Onay bekleyen kayıt bulunamadı (belki zaten işlendi).');

        if ($aksiyon === 'onayla') {
            $pdo->prepare("UPDATE kasa_hareketleri SET onay_durumu='onaylandi', onaylayan_id=? WHERE id=?")
                ->execute([$_SESSION['kullanici_id'], $id]);
            logla('kasa_onay', 'finans', $id, 'Onaylandı: ' . para($h['tutar']) . ' | ' . $h['kategori']);
            flash('basari', 'Gider onaylandı, kasa bakiyesine yansıdı.');
        } elseif ($aksiyon === 'reddet') {
            $pdo->prepare("UPDATE kasa_hareketleri SET onay_durumu='reddedildi', onaylayan_id=? WHERE id=?")
                ->execute([$_SESSION['kullanici_id'], $id]);
            logla('kasa_red', 'finans', $id, 'Reddedildi: ' . para($h['tutar']) . ' | ' . $h['kategori']);
            flash('basari', 'Gider reddedildi.');
        }
        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        flash('hata', $e->getMessage());
    }
    header('Location: onay.php'); exit;
}

$bekleyenler = $pdo->query("SELECT k.*, ku.ad_soyad AS kullanici FROM kasa_hareketleri k
    LEFT JOIN kullanicilar ku ON k.kullanici_id=ku.id
    WHERE k.onay_durumu='bekliyor' ORDER BY k.created_at")->fetchAll();

$gecmis = $pdo->query("SELECT k.*, ku.ad_soyad AS kullanici, on2.ad_soyad AS onaylayan FROM kasa_hareketleri k
    LEFT JOIN kullanicilar ku ON k.kullanici_id=ku.id
    LEFT JOIN kullanicilar on2 ON k.onaylayan_id=on2.id
    WHERE k.onay_durumu != 'bekliyor' AND k.onaylayan_id IS NOT NULL
    ORDER BY k.id DESC LIMIT 20")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-check2-square text-primary"></i> Gider Onayları</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Kasa & Finans</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold py-2 text-warning">
        <i class="bi bi-hourglass-split"></i> Onay Bekleyenler
        <?php if (!empty($bekleyenler)): ?><span class="badge bg-warning text-dark ms-1"><?= count($bekleyenler) ?></span><?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Tarih</th><th>Kategori</th><th>Tutar</th><th>Açıklama</th><th>Giren</th><th>Belge</th><th style="width:170px">İşlem</th></tr></thead>
            <tbody>
            <?php if (empty($bekleyenler)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Onay bekleyen gider yok</td></tr>
            <?php endif; ?>
            <?php foreach ($bekleyenler as $b): ?>
            <tr>
                <td><?= tarih($b['tarih']) ?></td>
                <td><?= escH($b['kategori']) ?></td>
                <td class="fw-bold text-danger"><?= para($b['tutar']) ?></td>
                <td class="small"><?= escH($b['aciklama'] ?: '-') ?></td>
                <td class="small"><?= escH($b['kullanici'] ?: '-') ?></td>
                <td>
                    <?php if ($b['belge']): ?>
                    <a href="<?= BASE_URL ?>/uploads/kasa/<?= escH($b['belge']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-paperclip"></i></a>
                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                </td>
                <td>
                    <form method="post" class="d-flex gap-1">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                        <button type="submit" name="aksiyon" value="onayla" class="btn btn-sm btn-success">Onayla</button>
                        <button type="submit" name="aksiyon" value="reddet" class="btn btn-sm btn-outline-danger" onclick="return confirm('Bu gider reddedilsin mi?')">Reddet</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold py-2">Son Onay/Red İşlemleri</div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tarih</th><th>Kategori</th><th>Tutar</th><th>Durum</th><th>Giren</th><th>İşleyen</th></tr></thead>
            <tbody>
            <?php if (empty($gecmis)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Kayıt yok</td></tr>
            <?php endif; ?>
            <?php foreach ($gecmis as $g): ?>
            <tr>
                <td><?= tarih($g['tarih']) ?></td>
                <td><?= escH($g['kategori']) ?></td>
                <td><?= para($g['tutar']) ?></td>
                <td><span class="badge bg-<?= $g['onay_durumu']==='onaylandi'?'success':'danger' ?>"><?= $g['onay_durumu']==='onaylandi'?'Onaylandı':'Reddedildi' ?></span></td>
                <td class="small"><?= escH($g['kullanici'] ?: '-') ?></td>
                <td class="small"><?= escH($g['onaylayan'] ?: '-') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
