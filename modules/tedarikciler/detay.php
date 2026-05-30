<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$t = $pdo->prepare("SELECT * FROM tedarikciler WHERE id=?");
$t->execute([$id]); $t = $t->fetch();
if (!$t) { flash('hata', 'Tedarikçi bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = $t['ad'];

// Stok hareketleri
$hareketler = $pdo->prepare("
    SELECT sh.*, u.ad AS urun_adi, u.kod, k.ad_soyad AS kullanici
    FROM stok_hareketleri sh
    JOIN urunler u ON sh.urun_id = u.id
    LEFT JOIN kullanicilar k ON sh.kullanici_id = k.id
    WHERE sh.tedarikci_id = ?
    ORDER BY sh.created_at DESC
    LIMIT 100
");
$hareketler->execute([$id]);
$hareketler = $hareketler->fetchAll();

// Tedarikçi ödeme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['odeme_yap'])) {
    csrfVerify();
    $tutar    = (float)$_POST['odeme_tutar'];
    $tip      = $_POST['odeme_tipi'] ?? 'nakit';
    $tarih    = $_POST['odeme_tarih'] ?: date('Y-m-d');
    $aciklama = trim($_POST['odeme_aciklama'] ?? '');
    if ($tutar > 0) {
        $pdo->prepare("INSERT INTO tedarikci_odemeleri (tedarikci_id,tarih,tutar,odeme_tipi,aciklama,kullanici_id) VALUES (?,?,?,?,?,?)")
            ->execute([$id, $tarih, $tutar, $tip, $aciklama ?: null, $_SESSION['kullanici_id']]);
        $pdo->prepare("UPDATE tedarikciler SET toplam_borc = GREATEST(0, toplam_borc - ?) WHERE id=?")
            ->execute([$tutar, $id]);
        logla('tedarikci_odeme', 'tedarikciler', $id, para($tutar) . ' ödeme yapıldı');
        flash('basari', para($tutar) . ' tedarikçi ödemesi kaydedildi.');
    }
    header('Location: detay.php?id=' . $id); exit;
}

// Özet istatistikler
$istatistik = $pdo->prepare("
    SELECT
        COUNT(*) AS toplam_islem,
        COALESCE(SUM(miktar), 0) AS toplam_adet,
        COUNT(DISTINCT urun_id) AS urun_cesidi,
        MAX(created_at) AS son_giris
    FROM stok_hareketleri
    WHERE tedarikci_id = ?
");
$istatistik->execute([$id]);
$ist = $istatistik->fetch();

// Ödeme geçmişi
$odemeler = $pdo->prepare("SELECT * FROM tedarikci_odemeleri WHERE tedarikci_id=? ORDER BY tarih DESC LIMIT 20");
$odemeler->execute([$id]); $odemeler = $odemeler->fetchAll();
$toplamOdenen = array_sum(array_column($odemeler, 'tutar'));

// Tedarikçiden en çok gelen ürünler
$enCokUrunler = $pdo->prepare("
    SELECT u.ad, u.kod, SUM(sh.miktar) AS toplam_adet
    FROM stok_hareketleri sh
    JOIN urunler u ON sh.urun_id = u.id
    WHERE sh.tedarikci_id = ?
    GROUP BY u.id
    ORDER BY toplam_adet DESC
    LIMIT 5
");
$enCokUrunler->execute([$id]);
$enCokUrunler = $enCokUrunler->fetchAll();

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
        <a href="duzenle.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil"></i> Düzenle
        </a>
        <a href="<?= BASE_URL ?>/modules/stok/giris.php?tedarikci_id=<?= $id ?>" class="btn btn-success">
            <i class="bi bi-box-arrow-in-down"></i> Stok Girişi Yap
        </a>
    </div>
</div>

<div class="row g-3">
    <!-- Sol: Bilgiler -->
    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle text-primary"></i> Firma Bilgileri
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="text-muted fw-normal" style="width:110px">Telefon</th>
                        <td>
                            <?php if ($t['telefon']): ?>
                            <a href="tel:<?= escH($t['telefon']) ?>"><?= escH($t['telefon']) ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">E-posta</th>
                        <td>
                            <?php if ($t['email']): ?>
                            <a href="mailto:<?= escH($t['email']) ?>"><?= escH($t['email']) ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Vergi No</th>
                        <td><?= escH($t['vergi_no'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Adres</th>
                        <td><?= escH($t['adres'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Kayıt Tarihi</th>
                        <td><?= tarih($t['created_at']) ?></td>
                    </tr>
                </table>
                <?php if ($t['notlar']): ?>
                <div class="mt-2 p-2 bg-light rounded small">
                    <i class="bi bi-sticky text-warning"></i> <?= escH($t['notlar']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Borç Durumu -->
        <div class="card shadow-sm mb-3 <?= $t['toplam_borc']>0?'border-danger':'' ?>">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-credit-card text-danger"></i> Borç Durumu</span>
                <?php if ($t['toplam_borc']>0): ?>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#odemeModal">
                    <i class="bi bi-cash-coin"></i> Ödeme Yap
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Toplam Borç</td>
                        <td class="fw-bold text-end <?= $t['toplam_borc']>0?'text-danger':'' ?>"><?= para($t['toplam_borc']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Toplam Ödenen</td>
                        <td class="fw-bold text-end text-success"><?= para($toplamOdenen) ?></td>
                    </tr>
                </table>
                <?php if (!empty($odemeler)): ?>
                <div class="px-3 pb-2">
                    <div class="small text-muted mt-2 mb-1">Son Ödemeler</div>
                    <?php foreach (array_slice($odemeler,0,3) as $o): ?>
                    <div class="d-flex justify-content-between small border-bottom py-1">
                        <span><?= tarih($o['tarih']) ?> — <?= ucfirst($o['odeme_tipi']) ?></span>
                        <span class="text-success fw-bold"><?= para($o['tutar']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- İstatistikler -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart text-primary"></i> İstatistikler
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Toplam İşlem</td>
                        <td class="fw-bold text-end"><?= $ist['toplam_islem'] ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Toplam Gelen Ürün</td>
                        <td class="fw-bold text-end"><?= number_format($ist['toplam_adet']) ?> adet</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Ürün Çeşidi</td>
                        <td class="fw-bold text-end"><?= $ist['urun_cesidi'] ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Son Giriş</td>
                        <td class="fw-bold text-end"><?= $ist['son_giris'] ? tarih($ist['son_giris']) : '-' ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- En çok gelen ürünler -->
        <?php if (!empty($enCokUrunler)): ?>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-trophy text-warning"></i> En Çok Gelen Ürünler
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                <?php foreach ($enCokUrunler as $u): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <div>
                        <div class="small fw-semibold"><?= escH($u['ad']) ?></div>
                        <div class="text-muted" style="font-size:.75rem"><?= escH($u['kod']) ?></div>
                    </div>
                    <span class="badge bg-primary"><?= $u['toplam_adet'] ?> adet</span>
                </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sağ: Stok hareketleri -->
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history text-primary"></i> Stok Giriş Geçmişi</span>
                <span class="badge bg-secondary"><?= count($hareketler) ?> kayıt</span>
            </div>
            <div class="card-body p-0">
            <?php if (empty($hareketler)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                    Henüz stok hareketi yok
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead><tr>
                    <th>Tarih</th>
                    <th>Ürün</th>
                    <th class="text-center">Miktar</th>
                    <th>Belge No</th>
                    <th>Açıklama</th>
                    <th>Kullanıcı</th>
                </tr></thead>
                <tbody>
                <?php foreach ($hareketler as $h): ?>
                <tr>
                    <td class="text-nowrap"><?= tarihSaat($h['created_at']) ?></td>
                    <td>
                        <strong><?= escH($h['urun_adi']) ?></strong>
                        <br><small class="text-muted"><?= escH($h['kod']) ?></small>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-success fs-6"><?= $h['miktar'] ?></span>
                    </td>
                    <td><?= escH($h['belge_no'] ?: '-') ?></td>
                    <td><?= escH($h['aciklama'] ?: '-') ?></td>
                    <td><?= escH($h['kullanici'] ?: '-') ?></td>
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
                    <div class="alert alert-warning py-2">
                        Mevcut borç: <strong><?= para($t['toplam_borc']) ?></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ödeme Tutarı <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="odeme_tutar" class="form-control" step="0.01" min="0.01"
                                   value="<?= $t['toplam_borc'] ?>" required>
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
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tarih</label>
                        <input type="date" name="odeme_tarih" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Açıklama</label>
                        <input type="text" name="odeme_aciklama" class="form-control" placeholder="Fatura no, irsaliye no...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Ödemeyi Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
