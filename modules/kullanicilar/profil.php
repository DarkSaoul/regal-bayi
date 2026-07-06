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
            $pdo->prepare("UPDATE kullanicilar SET sifre=?, sifre_degistirilme_tarihi=NOW(), sifre_degistir_zorunlu=0 WHERE id=?")
                ->execute([password_hash($yeni, PASSWORD_DEFAULT), $uid]);
            logla('sifre_degistir', 'kullanicilar', $uid, 'Şifre değiştirildi');
            flash('basari', 'Şifreniz başarıyla değiştirildi.');
            header('Location: profil.php'); exit;
        }
    }

    if ($aksiyon === 'avatar') {
        try {
            $yeni = kullaniciAvatarYukle($_FILES['avatar_dosya'] ?? ['error' => UPLOAD_ERR_NO_FILE], $k['avatar']);
            if ($yeni) {
                $pdo->prepare("UPDATE kullanicilar SET avatar=? WHERE id=?")->execute([$yeni, $uid]);
                flash('basari', 'Profil fotoğrafı güncellendi.');
            }
        } catch (Exception $e) {
            flash('hata', $e->getMessage());
        }
        header('Location: profil.php'); exit;
    }

    if ($aksiyon === 'not_okundu') {
        $pdo->prepare("UPDATE kullanicilar SET sistem_notu_okundu=1 WHERE id=?")->execute([$uid]);
        header('Location: profil.php'); exit;
    }

    if ($aksiyon === 'bildirim_tercihi') {
        $tercih = ($_POST['bildirim_tercihi'] ?? '') === 'kapali' ? 'kapali' : 'varsayilan';
        $pdo->prepare("UPDATE kullanicilar SET bildirim_tercihi=? WHERE id=?")->execute([$tercih, $uid]);
        $_SESSION['bildirim_tercihi'] = $tercih;
        flash('basari', 'Bildirim tercihiniz kaydedildi.');
        header('Location: profil.php'); exit;
    }

    // ── İki adımlı doğrulama (TOTP) kurulumu ─────────────────
    if ($aksiyon === 'totp_baslat') {
        $secret = totpSecretUret();
        $_SESSION['totp_kurulum_secret'] = $secret;
        header('Location: profil.php#2fa'); exit;
    }

    if ($aksiyon === 'totp_iptal') {
        unset($_SESSION['totp_kurulum_secret']);
        header('Location: profil.php#2fa'); exit;
    }

    if ($aksiyon === 'totp_etkinlestir') {
        $secret = $_SESSION['totp_kurulum_secret'] ?? '';
        $kod = $_POST['totp_kod'] ?? '';
        if (!$secret) {
            $hatalar[] = 'Kurulum oturumu sona ermiş, tekrar başlatın.';
        } elseif (!totpDogrula($secret, $kod)) {
            $hatalar[] = 'Girilen kod hatalı. Uygulamanızdaki güncel kodu deneyin.';
        } else {
            $sifreliSecret = aesSifrele($secret);
            if (!$sifreliSecret) {
                $hatalar[] = 'Şifreleme anahtarı tanımsız olduğu için 2FA etkinleştirilemiyor.';
            } else {
                $pdo->prepare("UPDATE kullanicilar SET totp_gizli_anahtar=?, totp_aktif=1 WHERE id=?")->execute([$sifreliSecret, $uid]);
                unset($_SESSION['totp_kurulum_secret']);
                logla('totp_etkin', 'kullanicilar', $uid, 'İki adımlı doğrulama etkinleştirildi');
                flash('basari', 'İki adımlı doğrulama etkinleştirildi.');
                header('Location: profil.php#2fa'); exit;
            }
        }
    }

    if ($aksiyon === 'totp_kapat') {
        $sifreOK = password_verify($_POST['onay_sifre_totp'] ?? '', $k['sifre']);
        if (!$sifreOK) {
            $hatalar[] = 'İki adımlı doğrulamayı kapatmak için şifrenizi doğru girmelisiniz.';
        } else {
            $pdo->prepare("UPDATE kullanicilar SET totp_aktif=0, totp_gizli_anahtar=NULL WHERE id=?")->execute([$uid]);
            logla('totp_kapatildi', 'kullanicilar', $uid, 'İki adımlı doğrulama kullanıcı tarafından kapatıldı');
            flash('basari', 'İki adımlı doğrulama kapatıldı.');
            header('Location: profil.php#2fa'); exit;
        }
    }
}

$totpKurulumSecret = $_SESSION['totp_kurulum_secret'] ?? null;
$sonAktiviteler = $pdo->prepare("SELECT * FROM aktivite_loglari WHERE kullanici_id=? ORDER BY id DESC LIMIT 15");
$sonAktiviteler->execute([$uid]);
$sonAktiviteler = $sonAktiviteler->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h4><i class="bi bi-person-circle text-primary"></i> Profilim</h4>
</div>

<?php foreach ($hatalar as $h): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($h) ?></div>
<?php endforeach; ?>

<?php if ($k['sistem_notu'] && !$k['sistem_notu_okundu']): ?>
<div class="alert alert-info alert-dismissible">
    <i class="bi bi-megaphone"></i> <strong>Yöneticinizden not:</strong> <?= nl2br(escH($k['sistem_notu'])) ?>
    <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="aksiyon" value="not_okundu">
        <button type="submit" class="btn-close" aria-label="Kapat"></button>
    </form>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Avatar -->
    <div class="col-md-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-image text-primary"></i> Profil Fotoğrafı</div>
            <div class="card-body d-flex align-items-center gap-3">
                <?php if ($k['avatar']): ?>
                <img src="<?= BASE_URL ?>/uploads/avatar/<?= escH($k['avatar']) ?>" class="rounded-circle border" style="width:64px;height:64px;object-fit:cover">
                <?php else: ?>
                <i class="bi bi-person-circle text-muted" style="font-size:64px"></i>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="flex-grow-1">
                    <?= csrfField() ?><input type="hidden" name="aksiyon" value="avatar">
                    <input type="file" name="avatar_dosya" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png,.webp">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Yükle</button>
                </form>
            </div>
        </div>

        <!-- Bilgi güncelle -->
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

        <!-- Bildirim tercihi -->
        <div class="card shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bell text-primary"></i> Bildirim Tercihi</div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?><input type="hidden" name="aksiyon" value="bildirim_tercihi">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="bildirim_tercihi" value="kapali" id="bildKapali" <?= $k['bildirim_tercihi']==='kapali'?'checked':'' ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="bildKapali">Dashboard uyarı kartlarımı kişisel olarak kapat</label>
                    </div>
                    <div class="form-text">Sistem genelindeki bildirim ayarından bağımsız, yalnızca sizin görünümünüzü etkiler.</div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- İki Adımlı Doğrulama (TOTP) -->
<div class="row g-3 mt-1" id="2fa">
    <div class="col-md-10">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-lock text-primary"></i> İki Adımlı Doğrulama (2FA)</div>
            <div class="card-body">
                <?php if ($k['totp_aktif']): ?>
                <div class="alert alert-success mb-3"><i class="bi bi-shield-check"></i> İki adımlı doğrulama şu anda <strong>aktif</strong>. Girişte şifrenizin yanında authenticator uygulamanızdaki 6 haneli kod istenecek.</div>
                <form method="post" class="row g-2 align-items-end" style="max-width:400px" onsubmit="return confirm('İki adımlı doğrulamayı kapatmak istediğinize emin misiniz?')">
                    <?= csrfField() ?><input type="hidden" name="aksiyon" value="totp_kapat">
                    <div class="col-8">
                        <label class="form-label small">Şifrenizi Girin (onay için)</label>
                        <input type="password" name="onay_sifre_totp" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-4"><button type="submit" class="btn btn-sm btn-outline-danger w-100">2FA'yı Kapat</button></div>
                </form>
                <?php elseif ($totpKurulumSecret): ?>
                <p class="small text-muted">1) Google Authenticator, Microsoft Authenticator veya Authy gibi bir uygulamayla aşağıdaki QR kodu okutun (ya da anahtarı elle girin). 2) Uygulamada beliren 6 haneli kodu aşağıya girip etkinleştirin.</p>
                <div class="d-flex gap-4 flex-wrap align-items-start">
                    <div id="totpQr" style="width:180px;height:180px"></div>
                    <div>
                        <div class="mb-2"><span class="text-muted small">Manuel giriş anahtarı:</span><br><code><?= escH($totpKurulumSecret) ?></code></div>
                        <form method="post" class="d-flex gap-2 align-items-end">
                            <?= csrfField() ?><input type="hidden" name="aksiyon" value="totp_etkinlestir">
                            <div>
                                <label class="form-label small">6 Haneli Kod</label>
                                <input type="text" name="totp_kod" class="form-control" style="max-width:150px" maxlength="6" pattern="\d{6}" required autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-success">Etkinleştir</button>
                        </form>
                        <form method="post" class="mt-2"><?= csrfField() ?><input type="hidden" name="aksiyon" value="totp_iptal">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Vazgeç</button>
                        </form>
                    </div>
                </div>
                <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
                <script>
                if (typeof QRCode !== 'undefined') {
                    QRCode.toCanvas(document.createElement('canvas'), <?= json_encode(totpUri($totpKurulumSecret, $k['kullanici_adi'])) ?>, { width: 180 }, function (err, canvas) {
                        if (!err) document.getElementById('totpQr').appendChild(canvas);
                    });
                }
                </script>
                <?php else: ?>
                <p class="text-muted small">İki adımlı doğrulama kapalı. Etkinleştirirseniz, girişte şifrenizin yanında telefonunuzdaki authenticator uygulamasından alınan 6 haneli bir kod da istenir.</p>
                <form method="post"><?= csrfField() ?><input type="hidden" name="aksiyon" value="totp_baslat">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-shield-plus"></i> 2FA Kurulumunu Başlat</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Kendi Aktivite Geçmişim -->
<div class="row g-3 mt-1">
    <div class="col-md-10">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history text-primary"></i> Son Aktivitelerim</div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                <?php if ($sonAktiviteler): ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>İşlem</th><th>Modül</th><th>Zaman</th></tr></thead>
                    <tbody>
                    <?php foreach ($sonAktiviteler as $a): ?>
                    <tr><td><?= escH($a['aksiyon']) ?></td><td class="text-muted small"><?= escH($a['modul'] ?? '-') ?></td><td class="small"><?= tarihSaat($a['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-muted small p-3">Henüz kayıtlı aktivite yok.</div>
                <?php endif; ?>
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
