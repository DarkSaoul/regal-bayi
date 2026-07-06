<?php
// Kullanıcı detay/özet sayfası — salt okunur
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$k = $pdo->prepare("SELECT * FROM kullanicilar WHERE id=?");
$k->execute([$id]); $k = $k->fetch();
if (!$k) { flash('hata', 'Kullanıcı bulunamadı.'); header('Location: index.php'); exit; }
$sayfa_basligi = 'Kullanıcı Detayı: ' . $k['ad_soyad'];

// Güvenlik olayları (son 30 gün)
$basarisizGirisler = $pdo->prepare(
    "SELECT ip_adresi, created_at FROM aktivite_loglari
     WHERE aksiyon='giris_basarisiz' AND detay=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY id DESC LIMIT 15"
);
$basarisizGirisler->execute([$k['kullanici_adi']]);
$basarisizGirisler = $basarisizGirisler->fetchAll();

$sonGirisler = $pdo->prepare(
    "SELECT created_at FROM aktivite_loglari WHERE aksiyon='giris' AND kullanici_id=? ORDER BY id DESC LIMIT 10"
);
$sonGirisler->execute([$id]);
$sonGirisler = $sonGirisler->fetchAll(PDO::FETCH_COLUMN);

// Kasiyer performans özeti (satislar.kullanici_id üzerinden)
$performans = null;
if ($k['rol'] === 'kasiyer' || $k['rol'] === 'yonetici') {
    $performans = $pdo->prepare(
        "SELECT COUNT(*) AS satis_adedi, COALESCE(SUM(genel_toplam),0) AS toplam_ciro,
                COALESCE(AVG(genel_toplam),0) AS ortalama_sepet
         FROM satislar WHERE kullanici_id=? AND durum != 'iptal' AND tarih > DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    );
    $performans->execute([$id]);
    $performans = $performans->fetch();
}

// Son aktiviteler (genel)
$sonAktiviteler = $pdo->prepare("SELECT * FROM aktivite_loglari WHERE kullanici_id=? ORDER BY id DESC LIMIT 20");
$sonAktiviteler->execute([$id]);
$sonAktiviteler = $sonAktiviteler->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-person-lines-fill text-primary"></i> <?= escH($k['ad_soyad']) ?></h4>
    <div class="d-flex gap-2">
        <a href="duzenle.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Düzenle</a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">← Listeye Dön</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Kullanıcı Adı</div>
            <div class="fw-bold"><?= escH($k['kullanici_adi']) ?></div>
            <span class="badge bg-<?= $k['rol']==='yonetici'?'danger':($k['rol']==='kasiyer'?'primary':'success') ?> mt-1"><?= ucfirst($k['rol']) ?></span>
            <span class="badge bg-<?= $k['aktif']?'success':'secondary' ?>"><?= $k['aktif']?'Aktif':'Pasif' ?></span>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Son Giriş</div>
            <div class="fw-bold"><?= $k['son_giris'] ? tarihSaat($k['son_giris']) : 'Hiç giriş yapmadı' ?></div>
            <div class="small"><?= $k['aktif_oturum_token'] ? '<span class="text-success">Aktif oturum token\'ı var</span>' : '<span class="text-muted">Aktif token yok</span>' ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Şifre Durumu</div>
            <div class="fw-bold"><?= $k['sifre_degistirilme_tarihi'] ? tarih($k['sifre_degistirilme_tarihi']) : 'Bilinmiyor' ?></div>
            <div class="small text-muted"><?= $k['sifre_muaf'] ? 'Geçerlilik kuralından muaf' : ($k['sifre_degistir_zorunlu'] ? 'Değişikliğe zorlanıyor' : 'Normal') ?></div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Güvenlik</div>
            <div class="fw-bold"><?= $k['totp_aktif'] ? '<i class="bi bi-shield-check text-success"></i> 2FA Aktif' : '<span class="text-muted">2FA Kapalı</span>' ?></div>
            <div class="small text-muted">İade yetkisi: <?= $k['izin_iade_yapabilir'] ? 'Var' : 'Yok' ?></div>
        </div></div>
    </div>
</div>

<?php if ($performans && (int)$performans['satis_adedi'] > 0): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up text-primary"></i> Performans Özeti (son 30 gün)</div>
    <div class="card-body d-flex gap-4 flex-wrap">
        <div><div class="text-muted small">Satış Adedi</div><div class="fw-bold fs-5"><?= (int)$performans['satis_adedi'] ?></div></div>
        <div><div class="text-muted small">Toplam Ciro</div><div class="fw-bold fs-5 text-success"><?= para($performans['toplam_ciro']) ?></div></div>
        <div><div class="text-muted small">Ortalama Sepet</div><div class="fw-bold fs-5"><?= para($performans['ortalama_sepet']) ?></div></div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-exclamation text-primary"></i> Başarısız Giriş Denemeleri (son 30 gün)</div>
            <div class="card-body p-0">
                <?php if ($basarisizGirisler): ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>IP</th><th>Zaman</th></tr></thead>
                    <tbody>
                    <?php foreach ($basarisizGirisler as $b): ?>
                    <tr><td><?= escH($b['ip_adresi']) ?></td><td class="small"><?= tarihSaat($b['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-muted small p-3">Son 30 günde başarısız giriş denemesi yok.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history text-primary"></i> Son Girişler</div>
            <div class="card-body p-0">
                <?php if ($sonGirisler): ?>
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($sonGirisler as $g): ?>
                    <tr><td class="small"><?= tarihSaat($g) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-muted small p-3">Kayıtlı giriş yok.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-primary"></i> Son Aktiviteler</div>
            <div class="card-body p-0" style="max-height:500px;overflow-y:auto">
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
                <div class="text-muted small p-3">Kayıtlı aktivite yok.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
