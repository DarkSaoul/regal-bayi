<?php
// Git tabanlı güncelleme bildirimi ve uygulaması — yalnızca kendi sunucunuzda çalışır
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Sistem Güncellemesi';
$pdo = db();

$durum = null; $hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'kontrol_et') {
        $durum = gitDurumKontrol();
        if (!empty($durum['hata'])) { flash('hata', $durum['hata']); header('Location: guncelleme.php'); exit; }
        $_SESSION['git_durum'] = $durum;
        header('Location: guncelleme.php'); exit;
    }

    if ($aksiyon === 'ayar_kaydet') {
        ayarKaydet('git_guncelleme_kontrolu_aktif', isset($_POST['aktif']) ? '1' : '0');
        flash('basari', 'Ayar kaydedildi.');
        header('Location: guncelleme.php'); exit;
    }

    if ($aksiyon === 'guncelle_uygula') {
        $sifreOK = false;
        if (!empty($_POST['onay_sifre'])) {
            $kul = $pdo->prepare("SELECT sifre FROM kullanicilar WHERE id=?");
            $kul->execute([$_SESSION['kullanici_id']]);
            $sifreOK = password_verify($_POST['onay_sifre'], (string)$kul->fetchColumn());
        }
        if (!$sifreOK) {
            flash('hata', 'Güncellemeyi uygulamak için şifrenizi doğru girmelisiniz.');
            header('Location: guncelleme.php'); exit;
        }
        $sonuc = gitGuncellemeUygula((int)$_SESSION['kullanici_id']);
        unset($_SESSION['git_durum']);
        flash($sonuc['basarili'] ? 'basari' : 'hata', $sonuc['mesaj']);
        header('Location: guncelleme.php'); exit;
    }

    if ($aksiyon === 'onceki_surume_don') {
        $gecmisId = (int)($_POST['gecmis_id'] ?? 0);
        $sifreOK = false;
        if (!empty($_POST['onay_sifre_don'])) {
            $kul = $pdo->prepare("SELECT sifre FROM kullanicilar WHERE id=?");
            $kul->execute([$_SESSION['kullanici_id']]);
            $sifreOK = password_verify($_POST['onay_sifre_don'], (string)$kul->fetchColumn());
        }
        if (!$sifreOK) {
            flash('hata', 'Önceki sürüme dönmek için şifrenizi doğru girmelisiniz.');
            header('Location: guncelleme.php'); exit;
        }
        $kayit = $pdo->prepare("SELECT * FROM git_guncelleme_gecmisi WHERE id=?");
        $kayit->execute([$gecmisId]); $kayit = $kayit->fetch();
        if (!$kayit) {
            flash('hata', 'Kayıt bulunamadı.');
        } else {
            $kodSonuc = gitOncekiSurumeDon($kayit['eski_commit']);
            $dbSonuc = ['basarili' => true, 'mesaj' => ''];
            if ($kayit['yedek_dosya_adi']) {
                $yol = __DIR__ . '/../../backups/' . basename($kayit['yedek_dosya_adi']);
                if (is_file($yol)) {
                    $duz = yedekDuzMetneCevir($yol);
                    $hataDizi = [];
                    $dbBasarili = $duz && mysqlRestoreCalistir($duz, $hataDizi);
                    if ($duz) @unlink($duz);
                    $dbSonuc = ['basarili' => $dbBasarili, 'mesaj' => $dbBasarili ? '' : implode(' ', $hataDizi)];
                }
            }
            $pdo->prepare("UPDATE git_guncelleme_gecmisi SET geri_alindi=1 WHERE id=?")->execute([$gecmisId]);
            logla('git_gecmis_surume_donuldu', 'sistem', $gecmisId, $kayit['yeni_commit'] . ' → ' . $kayit['eski_commit']);
            if ($kodSonuc['basarili'] && $dbSonuc['basarili']) {
                flash('basari', 'Kod ve veritabanı önceki sürüme (' . substr($kayit['eski_commit'], 0, 8) . ') döndürüldü.');
            } else {
                flash('hata', 'Geri alma kısmen başarısız: ' . $kodSonuc['mesaj'] . ' ' . $dbSonuc['mesaj']);
            }
        }
        header('Location: guncelleme.php'); exit;
    }
}

$durum = $_SESSION['git_durum'] ?? null;
$bekleyenMigrationlar = $durum && !($durum['guncel_mi'] ?? true) ? gitBekleyenMigrationlar() : [];
$gecmis = $pdo->query("SELECT g.*, k.ad_soyad FROM git_guncelleme_gecmisi g LEFT JOIN kullanicilar k ON g.kullanici_id=k.id ORDER BY g.id DESC LIMIT 20")->fetchAll();
$mevcutCommit = gitMevcutCommit();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-cloud-arrow-down-fill text-primary"></i> Sistem Güncellemesi</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Ayarlar</a>
</div>

<?php if (!gitGuncellemeDesteklerMi()): ?>
<div class="alert alert-secondary"><i class="bi bi-info-circle"></i> Bu özellik yalnızca <strong>git kurulu Linux sunucularında</strong> kullanılabilir. Windows üzerinde çalışan kurulumlarda güncellemeler manuel olarak (GitHub'dan yeni sürümü indirip dosyaları değiştirerek) uygulanmalıdır.</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; exit; ?>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="text-muted small">Mevcut Sürüm (commit)</div>
            <div class="fw-bold"><code><?= escH(substr($mevcutCommit ?? '-', 0, 12)) ?></code></div>
        </div>
        <form method="post"><?= csrfField() ?><input type="hidden" name="aksiyon" value="kontrol_et">
            <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> Güncelleme Kontrol Et</button>
        </form>
    </div>
</div>

<?php if ($durum): ?>
    <?php if ($durum['guncel_mi']): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle"></i> Sistem güncel — GitHub'daki en son sürümü çalıştırıyorsunuz.</div>
    <?php else: ?>
    <div class="card shadow-sm mb-3 border-warning">
        <div class="card-header bg-white fw-semibold text-warning"><i class="bi bi-exclamation-triangle"></i> <?= $durum['geride_kalan'] ?> commit geride</div>
        <div class="card-body">
            <?php if ($durum['yerel_degisiklik_var']): ?>
            <div class="alert alert-danger small"><i class="bi bi-exclamation-octagon"></i> Sunucuda commit edilmemiş yerel değişiklikler tespit edildi — güvenlik için güncelleme uygulanamaz. Önce bu değişiklikleri inceleyin.</div>
            <?php endif; ?>

            <div class="fw-semibold small mb-1">Bekleyen Değişiklikler</div>
            <div style="max-height:250px;overflow-y:auto" class="mb-3">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Commit</th><th>Yazar</th><th>Tarih</th><th>Mesaj</th></tr></thead>
                <tbody>
                <?php foreach ($durum['commitler'] as $c): ?>
                <tr><td><code><?= escH($c['hash']) ?></code></td><td class="small"><?= escH($c['yazar']) ?></td><td class="small"><?= escH($c['tarih']) ?></td><td class="small"><?= escH($c['mesaj']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($bekleyenMigrationlar): ?>
            <div class="alert alert-info small">
                <i class="bi bi-database-add"></i> Bu güncellemeyle birlikte <strong><?= count($bekleyenMigrationlar) ?> veritabanı migration'ı</strong> otomatik uygulanacak:
                <ul class="mb-0 mt-1"><?php foreach ($bekleyenMigrationlar as $m): ?><li><code><?= escH($m) ?></code></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>

            <?php if (!$durum['yerel_degisiklik_var']): ?>
            <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Sistem güncellenecek: otomatik yedek alınacak, bakım modu geçici açılacak, kod ve veritabanı güncellenecek. Devam edilsin mi?')">
                <?= csrfField() ?><input type="hidden" name="aksiyon" value="guncelle_uygula">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Şifrenizi Girin (onay için)</label>
                    <input type="password" name="onay_sifre" class="form-control" required autocomplete="current-password">
                </div>
                <div class="col-md-3"><button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-cloud-download"></i> Şimdi Güncelle</button></div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-gear text-primary"></i> Otomatik Kontrol Ayarı</div>
    <div class="card-body">
        <form method="post"><?= csrfField() ?><input type="hidden" name="aksiyon" value="ayar_kaydet">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="aktif" id="gitAktif" <?= ayar('git_guncelleme_kontrolu_aktif','1')==='1'?'checked':'' ?> onchange="this.form.submit()">
                <label class="form-check-label" for="gitAktif">Yönetici girişinde günde bir kez otomatik güncelleme kontrolü yap ve dashboard'da bildir</label>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history text-primary"></i> Güncelleme Geçmişi</div>
    <div class="card-body p-0">
        <?php if (empty($gecmis)): ?>
        <div class="text-muted small p-3">Henüz güncelleme yapılmamış.</div>
        <?php else: ?>
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tarih</th><th>Eski → Yeni</th><th>Migration</th><th>Durum</th><th>Kim</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($gecmis as $g): ?>
            <tr>
                <td class="small"><?= tarihSaat($g['created_at']) ?></td>
                <td><code class="small"><?= substr($g['eski_commit'],0,8) ?> → <?= substr($g['yeni_commit'],0,8) ?></code></td>
                <td><?= (int)$g['migration_sayisi'] ?></td>
                <td>
                    <?php if ($g['geri_alindi']): ?><span class="badge bg-secondary">Geri Alındı</span>
                    <?php elseif ($g['basarili']): ?><span class="badge bg-success">Başarılı</span>
                    <?php else: ?><span class="badge bg-danger" title="<?= escH($g['hata_metni'] ?? '') ?>">Başarısız</span>
                    <?php endif; ?>
                </td>
                <td class="small"><?= escH($g['ad_soyad'] ?? '-') ?></td>
                <td>
                    <?php if ($g['basarili'] && !$g['geri_alindi'] && $g['eski_commit'] !== $g['yeni_commit']): ?>
                    <form method="post" class="d-flex gap-1" onsubmit="return confirm('Kod VE veritabanı bu güncellemeden önceki haline (commit <?= substr($g['eski_commit'],0,8) ?>) döndürülecek. Bu işlem geri alınamaz. Emin misiniz?')">
                        <?= csrfField() ?><input type="hidden" name="aksiyon" value="onceki_surume_don"><input type="hidden" name="gecmis_id" value="<?= $g['id'] ?>">
                        <input type="password" name="onay_sifre_don" class="form-control form-control-sm" placeholder="Şifre" style="max-width:120px" required>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Bu Sürüme Dön</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
