<?php
// Teslimat / montaj takibi — bayi kendi ürününü dış servis firmasına teslim eder,
// servis firması müşteriye götürür. Servis firması bu sisteme giriş yapmaz;
// burası yalnızca bayi içi bir kayıt/takip mekanizmasıdır.
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Teslimatlar';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $id = (int)($_POST['id'] ?? 0);
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'servise_teslim') {
        $firma = mb_substr(trim($_POST['servis_firma'] ?? ''), 0, 150);
        if ($firma === '') {
            flash('hata', 'Servis firması adı zorunludur.');
        } else {
            $eleman  = mb_substr(trim($_POST['servis_eleman'] ?? ''), 0, 100) ?: null;
            $telefon = mb_substr(trim($_POST['servis_telefon'] ?? ''), 0, 20) ?: null;
            $pdo->prepare("UPDATE satislar SET teslimat_durum='serviste', servis_firma=?, servis_eleman=?, servis_telefon=?, servis_alis_tarihi=NOW()
                           WHERE id=? AND teslimat_durum='hazirlaniyor'")
                ->execute([$firma, $eleman, $telefon, $id]);
            logla('teslimat_guncelle', 'satislar', $id, "Servise teslim edildi: $firma" . ($eleman ? " ($eleman)" : ''));
            flash('basari', 'Ürün servis firmasına teslim edildi olarak işaretlendi.');
        }
    } elseif ($aksiyon === 'teslim_edildi') {
        $not = mb_substr(trim($_POST['teslim_onay_notu'] ?? ''), 0, 255) ?: null;
        $pdo->prepare("UPDATE satislar SET teslimat_durum='teslim_edildi', teslim_onay_tarihi=CURDATE(), teslim_onay_notu=?
                       WHERE id=? AND teslimat_durum IN ('hazirlaniyor','serviste')")
            ->execute([$not, $id]);
        logla('teslimat_guncelle', 'satislar', $id, 'Müşteriye teslim edildi' . ($not ? " — $not" : ''));
        flash('basari', 'Teslimat tamamlandı olarak işaretlendi.');
    }
    header('Location: teslimatlar.php' . (isset($_GET['durum']) ? '?durum=' . urlencode($_GET['durum']) : '')); exit;
}

$durumF = in_array($_GET['durum'] ?? '', ['hazirlaniyor','serviste','teslim_edildi'], true) ? $_GET['durum'] : '';

$where = "WHERE s.teslimat_durum != 'yok' AND s.durum != 'iptal'";
$params = [];
if ($durumF) { $where .= " AND s.teslimat_durum=?"; $params[] = $durumF; }

$teslimatlar = $pdo->prepare("SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi, m.telefon
    FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id
    $where ORDER BY FIELD(s.teslimat_durum,'hazirlaniyor','serviste','teslim_edildi'), s.teslimat_tarihi ASC");
$teslimatlar->execute($params); $teslimatlar = $teslimatlar->fetchAll();

$ozet = $pdo->query("SELECT teslimat_durum, COUNT(*) AS adet FROM satislar WHERE teslimat_durum!='yok' AND durum!='iptal' GROUP BY teslimat_durum")->fetchAll(PDO::FETCH_KEY_PAIR);
$bugunTeslimat = (int)$pdo->query("SELECT COUNT(*) FROM satislar WHERE teslimat_tarihi=CURDATE() AND teslimat_durum NOT IN ('yok','teslim_edildi') AND durum!='iptal'")->fetchColumn();
$servisFirmalari = $pdo->query("SELECT DISTINCT servis_firma FROM satislar WHERE servis_firma IS NOT NULL AND servis_firma != '' ORDER BY servis_firma")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../../includes/header.php';
$tdEtiket = ['hazirlaniyor'=>'Hazırlanıyor','serviste'=>'Serviste','teslim_edildi'=>'Teslim Edildi'];
$tdRenk   = ['hazirlaniyor'=>'warning text-dark','serviste'=>'info text-dark','teslim_edildi'=>'success'];
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-truck text-primary"></i> Teslimatlar</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Satışlar</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-warning">
            <div class="card-body py-2"><div class="small">Hazırlanıyor</div><div class="fw-bold fs-5"><?= (int)($ozet['hazirlaniyor'] ?? 0) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-info">
            <div class="card-body py-2"><div class="small">Serviste</div><div class="fw-bold fs-5"><?= (int)($ozet['serviste'] ?? 0) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body py-2"><div class="small">Teslim Edildi</div><div class="fw-bold fs-5"><?= (int)($ozet['teslim_edildi'] ?? 0) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body py-2"><div class="small">Bugün Planlı</div><div class="fw-bold fs-5"><?= $bugunTeslimat ?></div></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="btn-group btn-group-sm">
            <a href="?" class="btn btn-outline-secondary <?= !$durumF?'active':'' ?>">Tümü</a>
            <a href="?durum=hazirlaniyor" class="btn btn-outline-warning <?= $durumF==='hazirlaniyor'?'active':'' ?>">Hazırlanıyor</a>
            <a href="?durum=serviste" class="btn btn-outline-info <?= $durumF==='serviste'?'active':'' ?>">Serviste</a>
            <a href="?durum=teslim_edildi" class="btn btn-outline-success <?= $durumF==='teslim_edildi'?'active':'' ?>">Teslim Edildi</a>
        </div>
    </div>
</div>

<datalist id="servisFirmalariListe">
    <?php foreach ($servisFirmalari as $sf): ?>
    <option value="<?= escH($sf) ?>">
    <?php endforeach; ?>
</datalist>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Fatura</th><th>Müşteri</th><th>Teslimat Tarihi</th><th>Adres</th><th>Servis Firması</th><th>Durum</th><th style="width:180px">İşlem</th>
            </tr></thead>
            <tbody>
            <?php if (empty($teslimatlar)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Kayıt bulunamadı</td></tr>
            <?php endif; ?>
            <?php foreach ($teslimatlar as $t):
                $gecikmis = $t['teslimat_durum'] !== 'teslim_edildi' && $t['teslimat_tarihi'] && strtotime($t['teslimat_tarihi']) < strtotime(date('Y-m-d'));
            ?>
            <tr class="<?= $gecikmis ? 'table-danger' : '' ?>">
                <td><a href="detay.php?id=<?= $t['id'] ?>" class="fw-bold text-decoration-none"><?= escH($t['fatura_no']) ?></a></td>
                <td><?= escH($t['musteri_adi'] ?: 'Perakende') ?><?php if ($t['telefon']): ?><div class="small text-muted"><?= escH($t['telefon']) ?></div><?php endif; ?></td>
                <td><?= tarih($t['teslimat_tarihi']) ?><?php if ($gecikmis): ?><span class="badge bg-danger ms-1">Gecikmiş</span><?php endif; ?>
                    <?php if ($t['montaj_tarihi']): ?><div class="small text-muted">Montaj: <?= tarih($t['montaj_tarihi']) ?></div><?php endif; ?>
                </td>
                <td class="small text-truncate" style="max-width:200px"><?= escH($t['teslimat_adresi'] ?: '-') ?></td>
                <td class="small">
                    <?php if ($t['servis_firma']): ?>
                        <div class="fw-semibold"><?= escH($t['servis_firma']) ?></div>
                        <?php if ($t['servis_eleman']): ?><div class="text-muted"><?= escH($t['servis_eleman']) ?><?= $t['servis_telefon'] ? ' • '.escH($t['servis_telefon']) : '' ?></div><?php endif; ?>
                        <?php if ($t['servis_alis_tarihi']): ?><div class="text-muted">Alım: <?= tarihSaat($t['servis_alis_tarihi']) ?></div><?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                    <?php if ($t['teslim_onay_notu']): ?><div class="text-success"><i class="bi bi-check-circle"></i> <?= escH($t['teslim_onay_notu']) ?></div><?php endif; ?>
                </td>
                <td><span class="badge bg-<?= $tdRenk[$t['teslimat_durum']] ?>"><?= $tdEtiket[$t['teslimat_durum']] ?></span></td>
                <td>
                    <?php if ($t['teslimat_durum'] === 'hazirlaniyor'): ?>
                    <div class="d-flex flex-column gap-1">
                        <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#servisModal<?= $t['id'] ?>">
                            <i class="bi bi-truck"></i> Servise Teslim Et
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#teslimModal<?= $t['id'] ?>">
                            Doğrudan Teslim Edildi
                        </button>
                    </div>
                    <?php elseif ($t['teslimat_durum'] === 'serviste'): ?>
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#teslimModal<?= $t['id'] ?>">
                        <i class="bi bi-check-circle"></i> Teslim Edildi
                    </button>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Modallar (tbody dışında — geçerli HTML için tablo bittikten sonra render edilir) -->
<?php foreach ($teslimatlar as $t): ?>
<div class="modal fade" id="servisModal<?= $t['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <input type="hidden" name="aksiyon" value="servise_teslim">
                <div class="modal-header py-2">
                    <h6 class="modal-title"><i class="bi bi-truck text-info"></i> Servise Teslim — <?= escH($t['fatura_no']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small fw-semibold mb-1">Servis Firması <span class="text-danger">*</span></label>
                    <input type="text" name="servis_firma" class="form-control mb-2" list="servisFirmalariListe" required maxlength="150" placeholder="Örn: Hızlı Nakliyat">
                    <label class="form-label small fw-semibold mb-1">Teslim Alan Eleman</label>
                    <input type="text" name="servis_eleman" class="form-control mb-2" maxlength="100" placeholder="Ad Soyad">
                    <label class="form-label small fw-semibold mb-1">Telefon</label>
                    <input type="text" name="servis_telefon" class="form-control" maxlength="20" placeholder="05XX XXX XX XX">
                    <div class="form-text mt-2">Alım tarihi/saati otomatik olarak şimdi olarak kaydedilecek.</div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-info btn-sm">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="teslimModal<?= $t['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <input type="hidden" name="aksiyon" value="teslim_edildi">
                <div class="modal-header py-2">
                    <h6 class="modal-title"><i class="bi bi-check-circle text-success"></i> Teslim Onayı — <?= escH($t['fatura_no']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($t['servis_firma']): ?>
                    <div class="alert alert-light border py-2 small mb-2">
                        <strong><?= escH($t['servis_firma']) ?></strong><?= $t['servis_eleman'] ? ' — '.escH($t['servis_eleman']) : '' ?> tarafından teslim alındı.
                    </div>
                    <?php endif; ?>
                    <label class="form-label small fw-semibold mb-1">Teslim Notu <span class="text-muted">(opsiyonel)</span></label>
                    <input type="text" name="teslim_onay_notu" class="form-control" maxlength="255" placeholder="Örn: Müşteri teslim aldı, kurulum tamamlandı">
                    <div class="form-text mt-2">Teslim tarihi bugün olarak kaydedilecek.</div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-success btn-sm">Teslim Edildi Olarak İşaretle</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
