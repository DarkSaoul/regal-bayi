<?php
// Toplu gider girişi — CSV import (önizleme session'da tutulur, dosya iki kez seçilmez)
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
$sayfa_basligi = 'Toplu Gider Girişi';
$pdo = db();

if (isset($_GET['sablon'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gider_sablon.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tarih','Kategori','Tutar','Aciklama'], ';');
    fputcsv($out, [date('Y-m-d'), 'Kira', '15000,00', 'Örnek gider açıklaması'], ';');
    fclose($out); exit;
}

$kategoriler = $pdo->query("SELECT ad FROM kasa_kategoriler WHERE aktif=1 AND sistem=0 AND tip IN ('cikis','ikisi')")->fetchAll(PDO::FETCH_COLUMN);
$onizleme = $_SESSION['gider_toplu_onizleme'] ?? [];
$hatalar = []; $hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $asama = $_POST['asama'] ?? 'onizle';

    if ($asama === 'onizle') {
        unset($_SESSION['gider_toplu_onizleme']);
        $onizleme = [];
        if (empty($_FILES['dosya']['tmp_name']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
            $hata = 'Lütfen bir CSV dosyası seçin.';
        } else {
            $fh = fopen($_FILES['dosya']['tmp_name'], 'r');
            $ilkBayt = fread($fh, 3);
            if ($ilkBayt !== "\xEF\xBB\xBF") rewind($fh);
            fgetcsv($fh, 0, ';'); // başlık satırı
            $satirNo = 1;
            while (($satir = fgetcsv($fh, 0, ';')) !== false) {
                $satirNo++;
                if (count(array_filter($satir, fn($v) => trim((string)$v) !== '')) === 0) continue;
                [$tarihStr, $kategori, $tutarStr, $aciklama] = array_pad($satir, 4, '');
                $tarihStr = trim($tarihStr); $kategori = trim($kategori);
                $tutar = (float)str_replace(['.', ','], ['', '.'], trim($tutarStr));
                $satirHata = [];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarihStr) || strtotime($tarihStr) === false) $satirHata[] = 'geçersiz tarih';
                if (!in_array($kategori, $kategoriler, true)) $satirHata[] = 'tanımsız kategori';
                if ($tutar <= 0) $satirHata[] = 'geçersiz tutar';
                if ($satirHata) {
                    $hatalar[] = "Satır $satirNo: " . implode(', ', $satirHata);
                } else {
                    $onizleme[] = ['tarih' => $tarihStr, 'kategori' => $kategori, 'tutar' => $tutar, 'aciklama' => trim($aciklama)];
                }
            }
            fclose($fh);
            if (empty($hatalar) && !empty($onizleme)) $_SESSION['gider_toplu_onizleme'] = $onizleme;
        }
    } elseif ($asama === 'uygula') {
        if (empty($onizleme)) {
            $hata = 'Önizleme süresi doldu, dosyayı tekrar yükleyin.';
        } else {
            $pdo->beginTransaction();
            try {
                foreach ($onizleme as $r) {
                    $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,hesap,tutar,aciklama,kategori,onay_durumu,onaylayan_id,kullanici_id) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$r['tarih'], 'cikis', 'kasa', $r['tutar'], $r['aciklama'] ?: 'Toplu gider girişi', $r['kategori'], 'onaylandi', $_SESSION['kullanici_id'], $_SESSION['kullanici_id']]);
                }
                $pdo->commit();
                unset($_SESSION['gider_toplu_onizleme']);
                logla('gider_toplu', 'finans', 0, count($onizleme) . ' kayıt, toplam ' . para(array_sum(array_column($onizleme,'tutar'))));
                flash('basari', count($onizleme) . ' gider kaydı oluşturuldu.');
                header('Location: index.php'); exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $hata = 'Kayıt sırasında hata: ' . $e->getMessage();
            }
        }
    } elseif ($asama === 'iptal') {
        unset($_SESSION['gider_toplu_onizleme']);
        $onizleme = [];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-upload text-primary"></i> Toplu Gider Girişi (CSV)</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Kasa & Finans</a>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?></div>
<?php endif; ?>

<?php if (empty($onizleme)): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <p class="mb-2">CSV formatı: <code>Tarih;Kategori;Tutar;Aciklama</code> — tarih <code>YYYY-AA-GG</code>, kategori mevcut kasa kategorilerinden biri olmalı.</p>
        <a href="?sablon=1" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i> Örnek Şablon İndir</a>
        <div class="small text-muted mt-2">Geçerli kategoriler: <?= escH(implode(', ', $kategoriler)) ?></div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="asama" value="onizle">
            <label class="form-label fw-semibold">CSV Dosyası</label>
            <input type="file" name="dosya" class="form-control mb-2" accept=".csv" required>
            <button type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Önizle</button>
        </form>
    </div>
</div>

<?php if (!empty($hatalar)): ?>
<div class="alert alert-danger mt-3">
    <strong>Aşağıdaki hatalar giderilmeden hiçbir kayıt oluşturulmayacak:</strong>
    <ul class="mb-0 mt-1"><?php foreach ($hatalar as $h): ?><li><?= escH($h) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card shadow-sm border-success">
    <div class="card-header bg-white fw-semibold text-success">
        <i class="bi bi-check-circle"></i> Önizleme — <?= count($onizleme) ?> kayıt, toplam <?= para(array_sum(array_column($onizleme,'tutar'))) ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tarih</th><th>Kategori</th><th class="text-end">Tutar</th><th>Açıklama</th></tr></thead>
            <tbody>
            <?php foreach ($onizleme as $r): ?>
            <tr><td><?= tarih($r['tarih']) ?></td><td><?= escH($r['kategori']) ?></td><td class="text-end"><?= para($r['tutar']) ?></td><td class="small"><?= escH($r['aciklama']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <form method="post" onsubmit="return confirm('<?= count($onizleme) ?> gider kaydı oluşturulacak. Onaylıyor musunuz?')">
            <?= csrfField() ?>
            <input type="hidden" name="asama" value="uygula">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Onayla ve Kaydet</button>
        </form>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="asama" value="iptal">
            <button type="submit" class="btn btn-outline-secondary">Vazgeç</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
