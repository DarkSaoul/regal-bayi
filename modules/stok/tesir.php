<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','depo']);
$sayfa_basligi = 'Teşhir Yönetimi';
$pdo = db();

// ── POST: Seri nosuz ürün teşhir güncelle ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tesir_guncelle'])) {
    csrfVerify();
    $urun_id     = (int)$_POST['urun_id'];
    $yeni_tesir  = (int)$_POST['tesir_adedi'];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT stok_adedi, tesir_adedi, ad FROM urunler WHERE id=? FOR UPDATE");
    $stmt->execute([$urun_id]); $urun = $stmt->fetch();

    if ($urun) {
        $yeni_tesir = max(0, min($yeni_tesir, $urun['stok_adedi']));
        $pdo->prepare("UPDATE urunler SET tesir_adedi=? WHERE id=?")
            ->execute([$yeni_tesir, $urun_id]);
        $pdo->commit();
        logla('tesir_guncelle', 'stok', $urun_id,
            $urun['ad'] . ' | Teşhir: ' . $urun['tesir_adedi'] . ' → ' . $yeni_tesir);
        flash('basari', '"' . $urun['ad'] . '" teşhir adedi güncellendi.');
    } else {
        $pdo->rollBack();
    }
    header('Location: tesir.php'); exit;
}

// ── POST: Seri no durumu değiştir ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seri_durum'])) {
    csrfVerify();
    $seri_id  = (int)$_POST['seri_id'];
    $yeni_dur = $_POST['yeni_durum'] ?? '';
    $urun_id  = (int)$_POST['urun_id'];

    $izinli = ['stokta', 'tesirde'];
    if (in_array($yeni_dur, $izinli)) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT durum FROM seri_numaralari WHERE id=? AND urun_id=? FOR UPDATE");
            $stmt->execute([$seri_id, $urun_id]); $eski = $stmt->fetchColumn();

            // Yalnızca stokta↔tesirde geçişine izin ver; aynı duruma tekrar
            // basmak (çift submit) sayaçları kaydırmasın
            if ($eski !== false && in_array($eski, $izinli) && $eski !== $yeni_dur) {
                $pdo->prepare("UPDATE seri_numaralari SET durum=? WHERE id=?")->execute([$yeni_dur, $seri_id]);

                $fark = ($yeni_dur === 'tesirde' ? 1 : -1);
                $pdo->prepare("UPDATE urunler SET tesir_adedi = GREATEST(0, tesir_adedi + ?) WHERE id=?")
                    ->execute([$fark, $urun_id]);

                $pdo->commit();
                logla('tesir_seri', 'stok', $seri_id, "Seri #$seri_id: $eski → $yeni_dur");
                flash('basari', 'Seri no durumu güncellendi.');
            } else {
                $pdo->rollBack();
                flash('uyari', 'Durum zaten güncel veya bu geçişe izin verilmiyor.');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('hata', 'Güncelleme sırasında hata: ' . $e->getMessage());
        }
    }
    header('Location: tesir.php'); exit;
}

// ── Veri çek ─────────────────────────────────────────────────
$urunler = $pdo->query("
    SELECT u.*, k.ad AS kategori
    FROM urunler u
    LEFT JOIN kategoriler k ON u.kategori_id = k.id
    WHERE u.aktif = 1 AND u.stok_adedi > 0
    ORDER BY u.seri_no_takip DESC, u.tesir_adedi DESC, u.ad
")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-shop-window text-primary"></i> Teşhir Yönetimi</h4>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">← Stok Listesi</a>
    </div>
</div>

<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>
        Teşhirdeki ürünler toplam stoktan <strong>düşülmez</strong> — sadece işaretlenir.
        Satış sırasında "Teşhir ürününden sat" seçilince teşhir adedi azalır.
        <br><strong>Seri no'lu ürünler:</strong> hangi cihazın teşhirde olduğu seri no üzerinden takip edilir.
    </div>
</div>

<?php
$serili    = array_filter($urunler, fn($u) => $u['seri_no_takip']);
$serisiz   = array_filter($urunler, fn($u) => !$u['seri_no_takip']);
?>

<!-- ── Seri No'lu Ürünler ──────────────────────────────────── -->
<?php if (!empty($serili)): ?>
<h6 class="fw-bold mt-3 mb-2"><i class="bi bi-upc text-primary"></i> Seri Numaralı Ürünler</h6>
<div class="row g-3 mb-4">
<?php foreach ($serili as $u):
    $seriler = $pdo->prepare("SELECT * FROM seri_numaralari WHERE urun_id=? AND durum IN ('stokta','tesirde') ORDER BY durum DESC, seri_no");
    $seriler->execute([$u['id']]); $seriler = $seriler->fetchAll();
    $tesirdeki = array_filter($seriler, fn($s) => $s['durum'] === 'tesirde');
?>
<div class="col-md-6 col-xl-4">
    <div class="card shadow-sm h-100 <?= count($tesirdeki)>0 ? 'border-warning' : '' ?>">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
            <div>
                <span class="fw-semibold"><?= escH($u['ad']) ?></span>
                <br><small class="text-muted"><?= escH($u['kod']) ?></small>
            </div>
            <div class="text-end">
                <span class="badge bg-secondary">Toplam: <?= $u['stok_adedi'] ?></span><br>
                <span class="badge bg-warning text-dark">Teşhir: <?= $u['tesir_adedi'] ?></span>
            </div>
        </div>
        <div class="card-body p-0">
        <?php if (empty($seriler)): ?>
            <div class="text-center text-muted py-3 small">Stokta seri no yok</div>
        <?php else: ?>
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Seri No</th><th class="text-center">Durum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($seriler as $s): ?>
            <tr class="<?= $s['durum']==='tesirde' ? 'table-warning' : '' ?>">
                <td class="fw-semibold small"><?= escH($s['seri_no']) ?></td>
                <td class="text-center">
                    <?php if ($s['durum'] === 'tesirde'): ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-shop-window"></i> Teşhirde</span>
                    <?php else: ?>
                    <span class="badge bg-success"><i class="bi bi-archive"></i> Depoda</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="seri_durum" value="1">
                        <input type="hidden" name="seri_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="urun_id" value="<?= $u['id'] ?>">
                        <?php if ($s['durum'] === 'stokta'): ?>
                        <input type="hidden" name="yeni_durum" value="tesirde">
                        <button type="submit" class="btn btn-xs btn-outline-warning btn-sm py-0 px-2"
                                onclick="return confirm('Bu ürünü teşhire al?')">
                            <i class="bi bi-shop-window"></i> Teşhire Al
                        </button>
                        <?php else: ?>
                        <input type="hidden" name="yeni_durum" value="stokta">
                        <button type="submit" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2"
                                onclick="return confirm('Bu ürünü depoye al?')" >
                            <i class="bi bi-archive"></i> Depoya Al
                        </button>
                        <?php endif; ?>
                    </form>
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

<!-- ── Seri Nosuz Ürünler ──────────────────────────────────── -->
<?php if (!empty($serisiz)): ?>
<h6 class="fw-bold mt-2 mb-2"><i class="bi bi-box-seam text-primary"></i> Diğer Ürünler</h6>
<div class="card shadow-sm">
    <div class="card-body p-0">
    <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
            <tr>
                <th>Ürün</th>
                <th>Kategori</th>
                <th class="text-center">Toplam Stok</th>
                <th class="text-center">Depoda</th>
                <th class="text-center">Teşhirde</th>
                <th class="text-center" style="width:220px">Teşhir Adedini Ayarla</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($serisiz as $u):
            $depoda = $u['stok_adedi'] - $u['tesir_adedi'];
        ?>
        <tr class="<?= $u['tesir_adedi']>0 ? 'table-warning bg-opacity-50' : '' ?>">
            <td>
                <div class="fw-semibold"><?= escH($u['ad']) ?></div>
                <small class="text-muted"><?= escH($u['kod']) ?></small>
            </td>
            <td class="small text-muted"><?= escH($u['kategori'] ?? '-') ?></td>
            <td class="text-center fw-bold"><?= $u['stok_adedi'] ?></td>
            <td class="text-center">
                <span class="badge bg-success"><?= $depoda ?></span>
            </td>
            <td class="text-center">
                <?php if ($u['tesir_adedi'] > 0): ?>
                <span class="badge bg-warning text-dark">
                    <i class="bi bi-shop-window"></i> <?= $u['tesir_adedi'] ?>
                </span>
                <?php else: ?>
                <span class="text-muted small">—</span>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" class="d-flex align-items-center gap-2 justify-content-center">
                    <?= csrfField() ?>
                    <input type="hidden" name="tesir_guncelle" value="1">
                    <input type="hidden" name="urun_id" value="<?= $u['id'] ?>">
                    <div class="input-group input-group-sm" style="max-width:140px">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="this.nextElementSibling.value=Math.max(0,parseInt(this.nextElementSibling.value)-1)">−</button>
                        <input type="number" name="tesir_adedi" class="form-control text-center"
                               value="<?= $u['tesir_adedi'] ?>" min="0" max="<?= $u['stok_adedi'] ?>">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="this.previousElementSibling.value=Math.min(<?= $u['stok_adedi'] ?>,parseInt(this.previousElementSibling.value)+1)">+</button>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary px-2"
                            title="Kaydet"><i class="bi bi-save"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($urunler)): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
    Stokta ürün bulunmuyor.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
