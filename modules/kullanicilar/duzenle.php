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

$hata = ''; $geciciSifre = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $aksiyon = $d['aksiyon'] ?? 'guncelle';

    // ── Admin: geçici şifre ata ───────────────────────────────
    if ($aksiyon === 'sifre_sifirla') {
        $geciciSifre = 'Regal' . random_int(1000, 9999) . '!' . chr(random_int(97, 122));
        $pdo->prepare("UPDATE kullanicilar SET sifre=?, sifre_degistirilme_tarihi=NOW(), sifre_degistir_zorunlu=1 WHERE id=?")
            ->execute([password_hash($geciciSifre, PASSWORD_DEFAULT), $id]);
        logla('sifre_sifirlandi', 'kullanicilar', $id, 'Admin tarafından geçici şifre atandı');
        $kullanici['sifre_degistir_zorunlu'] = 1; // formda güncel görünsün
    }

    // ── TOTP'yi admin tarafından devre dışı bırak (telefon kaybı vb.) ──
    if ($aksiyon === 'totp_devre_disi') {
        $pdo->prepare("UPDATE kullanicilar SET totp_aktif=0, totp_gizli_anahtar=NULL WHERE id=?")->execute([$id]);
        logla('totp_devre_disi_admin', 'kullanicilar', $id, 'İki adımlı doğrulama admin tarafından kapatıldı');
        flash('basari', 'İki adımlı doğrulama devre dışı bırakıldı.');
        header('Location: duzenle.php?id=' . $id); exit;
    }

    if ($aksiyon === 'guncelle') {
        $ad_soyad     = trim($d['ad_soyad'] ?? '');
        $email        = trim($d['email'] ?? '');
        $rol          = in_array($d['rol'] ?? '', ['yonetici','kasiyer','depo']) ? $d['rol'] : 'kasiyer';
        $yeni_sifre   = $d['yeni_sifre'] ?? '';
        $sifre_tekrar = $d['sifre_tekrar'] ?? '';
        $sifreMuaf    = isset($d['sifre_muaf']) ? 1 : 0;
        $sifreZorunlu = isset($d['sifre_degistir_zorunlu']) ? 1 : 0;
        $izinIade     = isset($d['izin_iade_yapabilir']) ? 1 : 0;
        $gecerlilik   = trim($d['hesap_gecerlilik_tarihi'] ?? '') ?: null;
        $sistemNotu   = trim($d['sistem_notu'] ?? '');
        $notDegisti   = $sistemNotu !== (string)($kullanici['sistem_notu'] ?? '');

        // Kendi rolü değiştirilemez (gizli input manipülasyonuna karşı sunucu tarafı koruma)
        if ($id == $_SESSION['kullanici_id']) $rol = $kullanici['rol'];

        if (!$ad_soyad) {
            $hata = 'Ad Soyad alanı zorunludur.';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $hata = 'Geçerli bir e-posta adresi girin.';
        } elseif (!$email && ayar('kullanici_email_zorunlu','0') === '1') {
            $hata = 'E-posta alanı zorunludur.';
        } elseif ($kullanici['rol'] === 'yonetici' && $rol !== 'yonetici') {
            // Son aktif yöneticinin rolü düşürülemez — sistem kilitlenir
            $sayi = $pdo->query("SELECT COUNT(*) FROM kullanicilar WHERE rol='yonetici' AND aktif=1")->fetchColumn();
            if ((int)$sayi <= 1) $hata = 'Sistemdeki son yöneticinin rolü değiştirilemez.';
        } elseif ($gecerlilik && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gecerlilik)) {
            $hata = 'Geçersiz hesap geçerlilik tarihi.';
        }

        if (!$hata && $yeni_sifre) {
            $sifreHata = sifreDogrula($yeni_sifre);
            if ($sifreHata)                    { $hata = $sifreHata; }
            elseif ($yeni_sifre !== $sifre_tekrar) { $hata = 'Şifreler eşleşmiyor.'; }
        }

        if (!$hata) {
            if ($yeni_sifre) {
                $pdo->prepare("UPDATE kullanicilar SET ad_soyad=?, email=?, rol=?, sifre=?, sifre_degistirilme_tarihi=NOW() WHERE id=?")
                    ->execute([$ad_soyad, $email, $rol, password_hash($yeni_sifre, PASSWORD_DEFAULT), $id]);
            } else {
                $pdo->prepare("UPDATE kullanicilar SET ad_soyad=?, email=?, rol=? WHERE id=?")
                    ->execute([$ad_soyad, $email, $rol, $id]);
            }
            $pdo->prepare(
                "UPDATE kullanicilar SET sifre_muaf=?, sifre_degistir_zorunlu=?, izin_iade_yapabilir=?,
                    hesap_gecerlilik_tarihi=?, sistem_notu=?, sistem_notu_okundu=? WHERE id=?"
            )->execute([
                $sifreMuaf, $sifreZorunlu, $izinIade, $gecerlilik, $sistemNotu ?: null,
                $notDegisti && $sistemNotu ? 0 : 1, $id,
            ]);
            if ($ad_soyad !== $kullanici['ad_soyad'] || $email !== ($kullanici['email'] ?? '') || $rol !== $kullanici['rol']) {
                logla('kullanici_duzenle', 'kullanicilar', $id, "Güncellendi: {$kullanici['ad_soyad']}/{$kullanici['rol']} → $ad_soyad/$rol");
            }
            flash('basari', 'Kullanıcı güncellendi.');
            header('Location: index.php'); exit;
        }
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

<?php if ($geciciSifre): ?>
<div class="alert alert-success">
    <i class="bi bi-key"></i> Geçici şifre oluşturuldu, kullanıcıya güvenli bir şekilde iletin (bir daha gösterilmeyecek):
    <div class="fw-bold fs-5 mt-1"><code><?= escH($geciciSifre) ?></code></div>
    <div class="small text-muted">Kullanıcı bir sonraki girişte şifresini değiştirmeye zorlanacak.</div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-3" style="max-width:520px">
    <div class="card-body py-2">
        <div class="d-flex gap-2 flex-wrap">
            <form method="post" onsubmit="return confirm('Kullanıcıya rastgele bir geçici şifre atanacak. Devam edilsin mi?')">
                <?= csrfField() ?><input type="hidden" name="aksiyon" value="sifre_sifirla">
                <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-key"></i> Geçici Şifre Ata</button>
            </form>
            <?php if ($kullanici['totp_aktif']): ?>
            <form method="post" onsubmit="return confirm('Bu kullanıcının iki adımlı doğrulaması kapatılacak. Devam edilsin mi?')">
                <?= csrfField() ?><input type="hidden" name="aksiyon" value="totp_devre_disi">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-shield-slash"></i> 2FA'yı Kapat</button>
            </form>
            <?php endif; ?>
            <a href="detay.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-lines-fill"></i> Detay/Özet</a>
        </div>
    </div>
</div>

<div class="card shadow-sm" style="max-width:520px">
    <div class="card-body">
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="aksiyon" value="guncelle">
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

        <hr>
        <p class="fw-semibold mb-2">Güvenlik ve İzinler</p>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="sifre_degistir_zorunlu" id="sdz" <?= $kullanici['sifre_degistir_zorunlu'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="sdz">Bir sonraki girişte şifre değiştirmeye zorla</label>
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="sifre_muaf" id="sm" <?= $kullanici['sifre_muaf'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="sm">Şifre geçerlilik süresinden muaf tut <small class="text-muted">(Ayarlar → Sistem Geneli'ndeki genel kuraldan bağımsız)</small></label>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="izin_iade_yapabilir" id="iiy" <?= $kullanici['izin_iade_yapabilir'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="iiy">İade/değişim işlemi yapabilsin <small class="text-muted">(yalnızca kasiyer rolü için geçerli — yönetici her zaman yapabilir)</small></label>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Hesap Geçerlilik Tarihi <small class="text-muted fw-normal">(opsiyonel — geçici/stajyer hesaplar için)</small></label>
            <input type="date" name="hesap_gecerlilik_tarihi" class="form-control" style="max-width:200px" value="<?= escH($kullanici['hesap_gecerlilik_tarihi'] ?? '') ?>">
            <div class="form-text">Bu tarihten sonra hesap otomatik pasifleştirilir. Boş bırakılırsa süresiz.</div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-semibold">Sistem Notu <small class="text-muted fw-normal">(kullanıcıya bir sonraki girişinde tek seferlik gösterilir)</small></label>
            <textarea name="sistem_notu" class="form-control" rows="2" maxlength="500"><?= escH($kullanici['sistem_notu'] ?? '') ?></textarea>
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
