<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Kullanıcılar';
$pdo = db();
$kullanicilar = $pdo->query("SELECT * FROM kullanicilar ORDER BY id")->fetchAll();
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-person-gear text-primary"></i> Kullanıcılar</h4>
    <a href="ekle.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Yeni Kullanıcı</a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>Kullanıcı Adı</th><th>Ad Soyad</th><th>E-posta</th><th>Rol</th><th>Durum</th><th>İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($kullanicilar as $k): ?>
        <tr>
            <td><strong><?= escH($k['kullanici_adi']) ?></strong></td>
            <td><?= escH($k['ad_soyad']) ?></td>
            <td><?= escH($k['email']??'-') ?></td>
            <td><span class="badge bg-<?= $k['rol']==='yonetici'?'danger':($k['rol']==='kasiyer'?'primary':'success') ?>"><?= ucfirst($k['rol']) ?></span></td>
            <td><span class="badge bg-<?= $k['aktif']?'success':'secondary' ?>"><?= $k['aktif']?'Aktif':'Pasif' ?></span></td>
            <td>
                <?php if ($k['id'] != $_SESSION['kullanici_id']): ?>
                <a href="duzenle.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                <form method="post" action="toggle.php" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $k['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-<?= $k['aktif']?'warning':'success' ?>"
                            onclick="return confirm('Kullanıcı durumunu değiştirmek istiyor musunuz?')">
                        <i class="bi bi-<?= $k['aktif']?'pause':'play' ?>"></i>
                    </button>
                </form>
                <?php else: ?>
                <span class="text-muted small">Aktif Kullanıcı</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
