<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Yeni Kullanıcı';
$pdo = db();

$davetLinki = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $email = trim($d['email'] ?? '');
    $rol   = in_array($d['rol'] ?? '', ['yonetici','kasiyer','depo']) ? $d['rol'] : 'kasiyer';
    $davetIle = ($d['sifre_yontemi'] ?? '') === 'davet';

    if (!trim($d['kullanici_adi'] ?? '') || !trim($d['ad_soyad'] ?? '')) {
        $hata = 'Kullanıcı adı ve ad soyad zorunludur.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $hata = 'Geçerli bir e-posta adresi girin.';
    } elseif (!$email && ayar('kullanici_email_zorunlu','0') === '1') {
        $hata = 'E-posta alanı zorunludur.';
    } elseif (!$davetIle && ($sifreHata = sifreDogrula($d['sifre'] ?? ''))) {
        $hata = $sifreHata;
    } else {
        // Davetliyse: kullanılamayacak rastgele bir hash atanır (password_verify hiçbir zaman true dönmez),
        // gerçek şifre kullanıcı davet linkinden kendi belirleyene kadar yoktur.
        $sifreHash = $davetIle ? password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT) : password_hash($d['sifre'], PASSWORD_DEFAULT);
        $davetToken = $davetIle ? bin2hex(random_bytes(32)) : null;
        $davetSon = $davetIle ? date('Y-m-d H:i:s', time() + 72 * 3600) : null;
        try {
            $pdo->prepare("INSERT INTO kullanicilar (kullanici_adi,sifre,ad_soyad,email,rol,davet_token,davet_son_tarih) VALUES (?,?,?,?,?,?,?)")
                ->execute([trim($d['kullanici_adi']), $sifreHash, trim($d['ad_soyad']), $email, $rol, $davetToken, $davetSon]);
            if ($davetIle) {
                $davetLinki = BASE_URL . '/modules/auth/davet.php?token=' . $davetToken;
            } else {
                flash('basari','Kullanıcı eklendi.');
                header('Location: index.php'); exit;
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            $hata = 'Bu kullanıcı adı zaten kullanımda.';
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><h4><i class="bi bi-person-plus text-primary"></i> Yeni Kullanıcı</h4></div>
<?php if (!empty($hata)): ?><div class="alert alert-danger"><?= escH($hata) ?></div><?php endif; ?>

<?php if ($davetLinki): ?>
<div class="alert alert-success" style="max-width:500px">
    <i class="bi bi-check-circle"></i> Kullanıcı oluşturuldu. Aşağıdaki tek kullanımlık daveti linkini kopyalayıp kullanıcıya iletin (72 saat geçerlidir):
    <div class="input-group mt-2">
        <input type="text" id="davetLinkInput" class="form-control form-control-sm" readonly value="<?= escH($davetLinki) ?>">
        <button class="btn btn-sm btn-outline-success" onclick="navigator.clipboard.writeText(document.getElementById('davetLinkInput').value); this.textContent='Kopyalandı!'">Kopyala</button>
    </div>
</div>
<a href="index.php" class="btn btn-outline-secondary btn-sm">← Listeye Dön</a>
<?php else: ?>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
    <form method="post" id="ekleForm">
        <?= csrfField() ?>
        <div class="mb-3"><label class="form-label fw-semibold">Kullanıcı Adı *</label><input type="text" name="kullanici_adi" class="form-control" required value="<?= escH($_POST['kullanici_adi']??'') ?>" maxlength="50"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Ad Soyad *</label><input type="text" name="ad_soyad" class="form-control" required value="<?= escH($_POST['ad_soyad']??'') ?>" maxlength="100"></div>
        <div class="mb-3"><label class="form-label fw-semibold">E-posta<?= ayar('kullanici_email_zorunlu','0')==='1' ? ' *' : '' ?></label><input type="email" name="email" class="form-control" <?= ayar('kullanici_email_zorunlu','0')==='1' ? 'required' : '' ?> value="<?= escH($_POST['email']??'') ?>" maxlength="100"></div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Şifre Belirleme Yöntemi</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="sifre_yontemi" value="admin" id="syAdmin" checked onchange="sifreYontemiDegisti()">
                <label class="form-check-label" for="syAdmin">Şimdi ben belirleyeyim</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="sifre_yontemi" value="davet" id="syDavet" onchange="sifreYontemiDegisti()">
                <label class="form-check-label" for="syDavet">Davet linki oluştur — kullanıcı kendi belirlesin (72 saat geçerli)</label>
            </div>
        </div>
        <div class="mb-3" id="sifreAlani">
            <label class="form-label fw-semibold">Şifre * <small class="text-muted">(min. 8 kar., büyük+küçük harf+rakam)</small></label>
            <input type="password" name="sifre" id="sifreInput" class="form-control" minlength="8">
        </div>

        <div class="mb-3"><label class="form-label fw-semibold">Rol</label>
            <select name="rol" class="form-select">
                <option value="kasiyer">Kasiyer</option>
                <option value="depo">Depo Görevlisi</option>
                <option value="yonetici">Yönetici</option>
            </select>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
            <a href="index.php" class="btn btn-outline-secondary">İptal</a>
        </div>
    </form>
    </div>
</div>
<script>
function sifreYontemiDegisti() {
    const davet = document.getElementById('syDavet').checked;
    document.getElementById('sifreAlani').style.display = davet ? 'none' : '';
    document.getElementById('sifreInput').required = !davet;
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
