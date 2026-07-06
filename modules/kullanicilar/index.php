<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Kullanıcılar';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'toplu_pasif') {
        $idler = array_filter(array_map('intval', $_POST['secili'] ?? []));
        $idler = array_diff($idler, [(int)$_SESSION['kullanici_id']]); // kendi hesabını hariç tut

        // Son aktif yönetici(ler) toplu işlemle pasife alınamaz — sistem kilitlenmesin
        $aktifYoneticiSayisi = (int)$pdo->query("SELECT COUNT(*) FROM kullanicilar WHERE rol='yonetici' AND aktif=1")->fetchColumn();
        if ($idler) {
            $yerTutucu = implode(',', array_fill(0, count($idler), '?'));
            $sayimStmt = $pdo->prepare("SELECT COUNT(*) FROM kullanicilar WHERE id IN ($yerTutucu) AND rol='yonetici' AND aktif=1");
            $sayimStmt->execute(array_values($idler));
            $secilenYoneticiSayisi = (int)$sayimStmt->fetchColumn();
            if ($secilenYoneticiSayisi >= $aktifYoneticiSayisi) {
                flash('hata', 'Toplu işlem sistemdeki tüm aktif yöneticileri pasifleştirecekti — bu işlem engellendi. En az bir yönetici aktif kalmalı.');
                header('Location: index.php'); exit;
            }
            $pdo->prepare("UPDATE kullanicilar SET aktif=0 WHERE id IN ($yerTutucu)")->execute(array_values($idler));
            logla('kullanici_toplu_pasif', 'kullanicilar', 0, count($idler) . ' kullanıcı pasifleştirildi');
            flash('basari', count($idler) . ' kullanıcı pasifleştirildi.');
        }
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'zorla_cikis') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $id != $_SESSION['kullanici_id']) {
            $pdo->prepare("UPDATE kullanicilar SET zorla_cikis_tarihi=NOW() WHERE id=?")->execute([$id]);
            logla('oturum_zorla_sonlandirildi', 'kullanicilar', $id, 'Oturum yönetici tarafından sonlandırıldı');
            flash('basari', 'Kullanıcının oturumu sonlandırıldı.');
        }
        header('Location: index.php'); exit;
    }

    if ($aksiyon === 'kilit_ac') {
        $kullaniciAdi = trim($_POST['kullanici_adi'] ?? '');
        if ($kullaniciAdi) {
            bruteForceSifirla($kullaniciAdi);
            logla('giris_kilidi_acildi', 'kullanicilar', 0, "$kullaniciAdi için giriş kilidi manuel açıldı");
            flash('basari', "\"$kullaniciAdi\" için giriş kilidi açıldı.");
        }
        header('Location: index.php'); exit;
    }
}

// ── Arama / filtre / sıralama ─────────────────────────────────
$arama = trim($_GET['ara'] ?? '');
$filtreRol = in_array($_GET['rol'] ?? '', ['yonetici','kasiyer','depo'], true) ? $_GET['rol'] : '';
$filtreDurum = in_array($_GET['durum'] ?? '', ['aktif','pasif'], true) ? $_GET['durum'] : '';
$siralama = in_array($_GET['sirala'] ?? '', ['ad','son_giris','rol'], true) ? $_GET['sirala'] : 'ad';

$where = "WHERE 1=1"; $params = [];
if ($arama) { $where .= " AND (ad_soyad LIKE ? OR kullanici_adi LIKE ?)"; $params[] = "%$arama%"; $params[] = "%$arama%"; }
if ($filtreRol) { $where .= " AND rol=?"; $params[] = $filtreRol; }
if ($filtreDurum) { $where .= " AND aktif=?"; $params[] = $filtreDurum === 'aktif' ? 1 : 0; }
$orderBy = ['ad' => 'ad_soyad', 'son_giris' => 'son_giris DESC', 'rol' => 'rol'][$siralama];

$stmt = $pdo->prepare("SELECT * FROM kullanicilar $where ORDER BY $orderBy");
$stmt->execute($params);
$kullanicilar = $stmt->fetchAll();

$gecerlilikGun = (int)ayar('sifre_gecerlilik_gun', '0');
$pasifGun = (int)ayar('pasif_hesap_uyari_gun', '90');

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4><i class="bi bi-person-gear text-primary"></i> Kullanıcılar
        <span class="small text-muted fw-normal" data-bs-toggle="tooltip" title="Yönetici: tüm modüllere tam erişim. Kasiyer: satış/tahsilat/müşteri, finans görünürlüğü ayarlara bağlı. Depo: stok/sayım/teslimat, parasal veri yok.">
            <i class="bi bi-info-circle"></i> Rol açıklamaları
        </span>
    </h4>
    <a href="ekle.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Kullanıcı</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Ara</label>
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="Ad veya kullanıcı adı..." value="<?= escH($arama) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Rol</label>
                <select name="rol" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach (['yonetici'=>'Yönetici','kasiyer'=>'Kasiyer','depo'=>'Depo'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $filtreRol===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Durum</label>
                <select name="durum" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <option value="aktif" <?= $filtreDurum==='aktif'?'selected':'' ?>>Aktif</option>
                    <option value="pasif" <?= $filtreDurum==='pasif'?'selected':'' ?>>Pasif</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Sırala</label>
                <select name="sirala" class="form-select form-select-sm">
                    <option value="ad" <?= $siralama==='ad'?'selected':'' ?>>Ad Soyad</option>
                    <option value="son_giris" <?= $siralama==='son_giris'?'selected':'' ?>>Son Giriş</option>
                    <option value="rol" <?= $siralama==='rol'?'selected':'' ?>>Rol</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search"></i> Filtrele</button>
            </div>
        </form>
    </div>
</div>

<form method="post" onsubmit="return confirm('Seçili kullanıcılar pasifleştirilecek. Emin misiniz?')">
<?= csrfField() ?>
<input type="hidden" name="aksiyon" value="toplu_pasif">
<div class="card shadow-sm">
    <div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead>
        <tr>
            <th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('.satir-secim').forEach(c=>c.checked=this.checked)"></th>
            <th>Kullanıcı Adı</th><th>Ad Soyad</th><th>E-posta</th><th>Rol</th><th>Durum</th>
            <th>Son Giriş</th><th>Şifre Durumu</th><th>2FA</th><th>İşlem</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($kullanicilar as $k): ?>
        <tr>
            <td>
                <?php if ($k['id'] != $_SESSION['kullanici_id']): ?>
                <input type="checkbox" name="secili[]" value="<?= $k['id'] ?>" class="satir-secim">
                <?php endif; ?>
            </td>
            <td>
                <strong><?= escH($k['kullanici_adi']) ?></strong>
                <?php if ($k['aktif_oturum_token']): ?><i class="bi bi-circle-fill text-success ms-1" style="font-size:8px" title="Aktif oturum token'ı var"></i><?php endif; ?>
            </td>
            <td><?= escH($k['ad_soyad']) ?></td>
            <td><?= escH($k['email']??'-') ?></td>
            <td><span class="badge bg-<?= $k['rol']==='yonetici'?'danger':($k['rol']==='kasiyer'?'primary':'success') ?>"><?= ucfirst($k['rol']) ?></span></td>
            <td>
                <span class="badge bg-<?= $k['aktif']?'success':'secondary' ?>"><?= $k['aktif']?'Aktif':'Pasif' ?></span>
                <?php if ($k['hesap_gecerlilik_tarihi']): ?>
                <div class="small text-muted"><?= $k['hesap_gecerlilik_tarihi'] < date('Y-m-d') ? '⚠️ süresi doldu' : 'geçerlilik: ' . tarih($k['hesap_gecerlilik_tarihi']) ?></div>
                <?php endif; ?>
            </td>
            <td class="small">
                <?php if ($k['son_giris']): ?>
                    <?= tarihSaat($k['son_giris']) ?>
                    <?php if ($pasifGun > 0 && (time() - strtotime($k['son_giris'])) > $pasifGun * 86400): ?>
                    <div class="text-warning">⚠️ <?= $pasifGun ?>+ gündür pasif</div>
                    <?php endif; ?>
                <?php else: ?>
                <span class="text-muted">Hiç giriş yapmadı</span>
                <?php endif; ?>
            </td>
            <td class="small">
                <?php if ($k['sifre_muaf']): ?>
                <span class="text-muted">Muaf</span>
                <?php elseif ($k['sifre_degistirilme_tarihi']): ?>
                    <?php $gun = (int)((time() - strtotime($k['sifre_degistirilme_tarihi'])) / 86400); ?>
                    <?= $gun ?> gün önce
                    <?php if ($gecerlilikGun > 0 && $gun > $gecerlilikGun): ?><div class="text-danger">⚠️ süresi doldu</div><?php endif; ?>
                <?php else: ?>
                <span class="text-muted">-</span>
                <?php endif; ?>
                <?php if ($k['sifre_degistir_zorunlu']): ?><div class="text-warning">Değişikliğe zorlanıyor</div><?php endif; ?>
            </td>
            <td><?= $k['totp_aktif'] ? '<i class="bi bi-shield-check text-success" title="2FA aktif"></i>' : '<span class="text-muted small">-</span>' ?></td>
            <td>
                <div class="d-flex gap-1 flex-wrap">
                <?php if ($k['id'] != $_SESSION['kullanici_id']): ?>
                <a href="duzenle.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="Düzenle"><i class="bi bi-pencil"></i></a>
                <a href="detay.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Detay"><i class="bi bi-eye"></i></a>
                <button type="button" class="btn btn-sm btn-outline-warning py-0 px-1" title="Durumu Değiştir"
                        onclick="if(confirm('Kullanıcı durumunu değiştirmek istiyor musunuz?')){document.getElementById('toggle-<?= $k['id'] ?>').submit()}">
                    <i class="bi bi-<?= $k['aktif']?'pause':'play' ?>"></i>
                </button>
                <?php if ($k['aktif_oturum_token']): ?>
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" title="Oturumu Sonlandır"
                        onclick="if(confirm('Bu kullanıcının oturumu sonlandırılsın mı?')){document.getElementById('cikis-<?= $k['id'] ?>').submit()}">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-info py-0 px-1" title="Giriş Kilidini Aç"
                        onclick="if(confirm('Bu kullanıcı için giriş denemesi kilidi açılsın mı?')){document.getElementById('kilit-<?= $k['id'] ?>').submit()}">
                    <i class="bi bi-unlock"></i>
                </button>
                <?php else: ?>
                <span class="text-muted small">Aktif Kullanıcı</span>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($kullanicilar)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Filtreye uyan kullanıcı bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<div class="mt-2">
    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-pause-circle"></i> Seçilenleri Pasifleştir</button>
</div>
</form>

<?php foreach ($kullanicilar as $k): if ($k['id'] == $_SESSION['kullanici_id']) continue; ?>
<form method="post" id="toggle-<?= $k['id'] ?>" action="toggle.php" class="d-none">
    <?= csrfField() ?><input type="hidden" name="id" value="<?= $k['id'] ?>">
</form>
<form method="post" id="cikis-<?= $k['id'] ?>" class="d-none">
    <?= csrfField() ?><input type="hidden" name="aksiyon" value="zorla_cikis"><input type="hidden" name="id" value="<?= $k['id'] ?>">
</form>
<form method="post" id="kilit-<?= $k['id'] ?>" class="d-none">
    <?= csrfField() ?><input type="hidden" name="aksiyon" value="kilit_ac"><input type="hidden" name="kullanici_adi" value="<?= escH($k['kullanici_adi']) ?>">
</form>
<?php endforeach; ?>

<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
