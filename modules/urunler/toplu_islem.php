<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}
csrfVerify();
$pdo = db();

$ids = array_values(array_filter(array_map('intval', (array)($_POST['urun'] ?? []))));
$islem = $_POST['islem'] ?? '';
$geri = 'index.php' . (!empty($_POST['geri']) ? '?' . preg_replace('/[^a-zA-Z0-9=&%_.\-]/', '', $_POST['geri']) : '');

if (!$ids || !in_array($islem, ['pasif','aktif','kategori','kdv'], true)) {
    flash('hata', 'Ürün seçilmedi veya geçersiz işlem.');
    header("Location: $geri"); exit;
}

$yerTutucu = implode(',', array_fill(0, count($ids), '?'));
$adet = count($ids);

switch ($islem) {
    case 'pasif':
        $pdo->prepare("UPDATE urunler SET aktif=0 WHERE id IN ($yerTutucu)")->execute($ids);
        logla('urun_toplu_pasif', 'urunler', 0, "$adet ürün arşive taşındı: " . implode(',', $ids));
        flash('basari', "$adet ürün arşive taşındı.");
        break;
    case 'aktif':
        $pdo->prepare("UPDATE urunler SET aktif=1 WHERE id IN ($yerTutucu)")->execute($ids);
        logla('urun_toplu_aktif', 'urunler', 0, "$adet ürün arşivden çıkarıldı: " . implode(',', $ids));
        flash('basari', "$adet ürün arşivden çıkarıldı.");
        break;
    case 'kategori':
        $kategori_id = (int)($_POST['kategori_id'] ?? 0);
        $var = $pdo->prepare("SELECT COUNT(*) FROM kategoriler WHERE id=?");
        $var->execute([$kategori_id]);
        if (!$var->fetchColumn()) { flash('hata', 'Geçersiz kategori.'); header("Location: $geri"); exit; }
        $pdo->prepare("UPDATE urunler SET kategori_id=? WHERE id IN ($yerTutucu)")->execute(array_merge([$kategori_id], $ids));
        logla('urun_toplu_kategori', 'urunler', $kategori_id, "$adet ürünün kategorisi değişti: " . implode(',', $ids));
        flash('basari', "$adet ürünün kategorisi güncellendi.");
        break;
    case 'kdv':
        $kdv = (float)($_POST['kdv_orani'] ?? -1);
        if (!in_array($kdv, [0.0, 1.0, 10.0, 20.0], true)) { flash('hata', 'Geçersiz KDV oranı.'); header("Location: $geri"); exit; }
        $pdo->prepare("UPDATE urunler SET kdv_orani=? WHERE id IN ($yerTutucu)")->execute(array_merge([$kdv], $ids));
        logla('urun_toplu_kdv', 'urunler', 0, "$adet ürünün KDV oranı %$kdv yapıldı: " . implode(',', $ids));
        flash('basari', "$adet ürünün KDV oranı %$kdv olarak güncellendi.");
        break;
}
header("Location: $geri"); exit;
