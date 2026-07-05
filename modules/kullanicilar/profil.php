<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Profilim';
$pdo = db();

$uid = (int)$_SESSION['kullanici_id'];
$k = $pdo->prepare("SELECT * FROM kullanicilar WHERE id=?");
$k->execute([$uid]); $k = $k->fetch();

$hatalar = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'bilgi') {
        $ad_soyad = trim($_POST['ad_soyad'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        if (!$ad_soyad) { $hatalar[] = 'Ad soyad boş olamaz.'; }
        else {
            $pdo->prepare("UPDATE kullanicilar SET ad_soyad=?, email=? WHERE id=?")
                ->execute([$ad_soyad, $email, $uid]);
            $_SESSION['ad_soyad'] = $ad_soyad;
            logla('profil_guncelle', 'kullanicilar', $uid, 'Profil bilgisi güncellendi');
            flash('basari', 'Bilgileriniz güncellendi.');
            header('Location: profil.php'); exit;
        }
    }

    if ($aksiyon === 'sifre') {
        $mevcut  = $_POST['mevcut_sifre'] ?? '';
        $yeni    = $_POST['yeni_sifre'] ?? '';
        $tekrar  = $_POST['yeni_sifre_tekrar'] ?? '';

        if (!password_verify($mevcut, $k['sifre'])) {
            $hatalar[] = 'Mevcut şifre yanlış.';
        } elseif ($yeni !== $tekrar) {
            $hatalar[] = 'Yeni şifreler eşleşmiyor.';
        } elseif ($hata = sifreDogrula($yeni)) {
            $hatalar[] = $hata;
        } else {
            $pdo->prepare("UPDATE kullanicilar SET sifre=?, sifre_degistirilme_tarihi=NOW() WHERE id=?")
                ->execute([password_hash($yeni, PASSWORD_DEFAULT), $uid]);
            logla('sifre_degistir', 'kullanicilar', $uid, 'Şifre değiştirildi');
            flash('basari', 'Şifreniz başarıyla değiştirildi.');
            header('Location: profil.php'); exit;
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-person-circle text-primary"></i> Profilim</h4>
</div>

<?php foreach ($hatalar as $h): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($h) ?></div>
<?php endforeach; ?>

<div class="row g-3">
    <!-- Bilgi güncelle -->
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-person text-primary"></i> Profil Bilgileri
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksiyon" value="bilgi">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Kullanıcı Adı</label>
                        <input type="text" class="form-control" value="<?= escH($k['kullanici_adi']) ?>" disabled>
                        <div class="form-text">Kullanıcı adı değiştirilemez.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ad Soyad *</label>
                        <input type="text" name="ad_soyad" class="form-control" required maxlength="100"
                               value="<?= escH($k['ad_soyad']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">E-posta</label>
                        <input type="email" name="email" class="form-control" maxlength="100"
                               value="<?= escH($k['email'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rol</label>
                        <input type="text" class="form-control" value="<?= escH($k['rol']) ?>" disabled>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Bilgileri Güncelle
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Şifre değiştir -->
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-lock text-warning"></i> Şifre Değiştir
            </div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksiyon" value="sifre">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mevcut Şifre *</label>
                        <input type="password" name="mevcut_sifre" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Yeni Şifre *</label>
                        <input type="password" name="yeni_sifre" class="form-control" required
                               autocomplete="new-password" id="yeniSifre">
                        <div class="form-text">En az 8 karakter, büyük/küçük harf ve rakam içermeli.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Yeni Şifre Tekrar *</label>
                        <input type="password" name="yeni_sifre_tekrar" class="form-control" required
                               autocomplete="new-password" id="yeniSifreTekrar"
                               oninput="sifreTekrarKontrol()">
                        <div id="sifreEslMesaj" class="form-text"></div>
                    </div>
                    <button type="submit" class="btn btn-warning fw-semibold">
                        <i class="bi bi-shield-lock"></i> Şifremi Değiştir
                    </button>
                </form>
            </div>
        </div>

        <!-- Hesap bilgisi -->
        <div class="card shadow-sm mt-3">
            <div class="card-body py-2">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Kayıt Tarihi</td>
                        <td><?= tarih($k['created_at']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Hesap Durumu</td>
                        <td><span class="badge bg-<?= $k['aktif']?'success':'danger' ?>"><?= $k['aktif']?'Aktif':'Pasif' ?></span></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function sifreTekrarKontrol() {
    const y = document.getElementById('yeniSifre').value;
    const t = document.getElementById('yeniSifreTekrar').value;
    const el = document.getElementById('sifreEslMesaj');
    if (!t) { el.textContent = ''; return; }
    if (y === t) { el.innerHTML = '<span class="text-success">✓ Şifreler eşleşiyor</span>'; }
    else         { el.innerHTML = '<span class="text-danger">✗ Şifreler eşleşmiyor</span>'; }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
