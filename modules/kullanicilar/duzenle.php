<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$kullanici = $pdo->prepare("SELECT * FROM kullanicilar WHERE id=?");
$kullanici->execute([$id]); $kullanici = $kullanici->fetch();
if (!$kullanici) { flash('hata', 'Kullanıcı bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = 'Kullanıcı Düzenle: ' . $kullanici['ad_soyad'];

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $ad_soyad     = trim($d['ad_soyad'] ?? '');
    $email        = trim($d['email'] ?? '');
    $rol          = in_array($d['rol'] ?? '', ['yonetici','kasiyer','depo']) ? $d['rol'] : 'kasiyer';
    $yeni_sifre   = $d['yeni_sifre'] ?? '';
    $sifre_tekrar = $d['sifre_tekrar'] ?? '';

    if (!$ad_soyad) {
        $hata = 'Ad Soyad alanı zorunludur.';
    } elseif ($yeni_sifre) {
        $sifreHata = sifreDogrula($yeni_sifre);
        if ($sifreHata)                    { $hata = $sifreHata; }
        elseif ($yeni_sifre !== $sifre_tekrar) { $hata = 'Şifreler eşleşmiyor.'; }
    }

    if (!$hata) {
        if ($yeni_sifre) {
            $pdo->prepare("UPDATE kullanicilar SET ad_soyad=?, email=?, rol=?, sifre=? WHERE id=?")
                ->execute([$ad_soyad, $email, $rol, password_hash($yeni_sifre, PASSWORD_BCRYPT, ['cost'=>12]), $id]);
        } else {
            $pdo->prepare("UPDATE kullanicilar SET ad_soyad=?, email=?, rol=? WHERE id=?")
                ->execute([$ad_soyad, $email, $rol, $id]);
        }
        flash('basari', 'Kullanıcı güncellendi.');
        header('Location: index.php'); exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-person-gear text-primary"></i> Kullanıcı Düzenle</h4>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:520px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label fw-semibold">Kullanıcı Adı</label>
            <input type="text" class="form-control" value="<?= escH($kullanici['kullanici_adi']) ?>" disabled>
            <div class="form-text">Kullanıcı adı değiştirilemez.</div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Ad Soyad <span class="text-danger">*</span></label>
            <input type="text" name="ad_soyad" class="form-control" required
                   value="<?= escH($_POST['ad_soyad'] ?? $kullanici['ad_soyad']) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">E-posta</label>
            <input type="email" name="email" class="form-control"
                   value="<?= escH($_POST['email'] ?? $kullanici['email'] ?? '') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Rol</label>
            <select name="rol" class="form-select" <?= $id == $_SESSION['kullanici_id'] ? 'disabled' : '' ?>>
                <option value="kasiyer"  <?= ($kullanici['rol']==='kasiyer'  && !isset($_POST['rol'])) || ($_POST['rol']??'')==='kasiyer'  ? 'selected':'' ?>>Kasiyer</option>
                <option value="depo"     <?= ($kullanici['rol']==='depo'     && !isset($_POST['rol'])) || ($_POST['rol']??'')==='depo'     ? 'selected':'' ?>>Depo Görevlisi</option>
                <option value="yonetici" <?= ($kullanici['rol']==='yonetici' && !isset($_POST['rol'])) || ($_POST['rol']??'')==='yonetici' ? 'selected':'' ?>>Yönetici</option>
            </select>
            <?php if ($id == $_SESSION['kullanici_id']): ?>
            <input type="hidden" name="rol" value="<?= escH($kullanici['rol']) ?>">
            <div class="form-text text-warning">Kendi rolünüzü değiştiremezsiniz.</div>
            <?php endif; ?>
        </div>

        <hr>
        <p class="fw-semibold mb-2">Şifre Değiştir <small class="text-muted fw-normal">(boş bırakılırsa şifre değişmez)</small></p>

        <div class="mb-3">
            <label class="form-label fw-semibold">Yeni Şifre <small class="text-muted">(min. 8 kar., büyük+küçük+rakam)</small></label>
            <div class="input-group">
                <input type="password" name="yeni_sifre" id="yeniSifre" class="form-control" placeholder="En az 8 karakter" minlength="8">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleSifre('yeniSifre', this)">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-semibold">Şifre Tekrar</label>
            <div class="input-group">
                <input type="password" name="sifre_tekrar" id="sifreTekrar" class="form-control" placeholder="Şifreyi tekrar girin">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleSifre('sifreTekrar', this)">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Güncelle</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>

<script>
function toggleSifre(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
