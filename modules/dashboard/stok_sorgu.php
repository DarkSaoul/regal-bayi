<?php
// Dashboard hızlı stok sorgulama — AJAX uç noktası
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
header('Content-Type: application/json; charset=UTF-8');
$pdo = db();

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'sonuclar' => []]);
    exit;
}

$stmt = $pdo->prepare("SELECT u.id, u.kod, u.barkod, u.ad, u.marka, u.resim, u.satis_fiyati,
        u.stok_adedi, u.min_stok, u.tesir_adedi, k.ad AS kategori_adi
    FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id
    WHERE u.aktif=1 AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ? OR u.marka LIKE ?)
    ORDER BY (u.stok_adedi > 0) DESC, u.ad LIMIT 20");
$param = likeParam($q);
$stmt->execute([$param, $param, $param, $param]);

$sonuclar = array_map(function ($u) {
    $durum = $u['stok_adedi'] <= 0 ? 'tukendi' : ($u['stok_adedi'] <= $u['min_stok'] ? 'kritik' : 'yeterli');
    return [
        'id'        => (int)$u['id'],
        'kod'       => $u['kod'],
        'barkod'    => $u['barkod'],
        'ad'        => $u['ad'],
        'marka'     => $u['marka'],
        'kategori'  => $u['kategori_adi'],
        'resim'     => $u['resim'] ? BASE_URL . '/uploads/urunler/' . $u['resim'] : null,
        'fiyat'     => para($u['satis_fiyati']),
        'stok'      => (int)$u['stok_adedi'],
        'min_stok'  => (int)$u['min_stok'],
        'tesir'     => (int)$u['tesir_adedi'],
        'durum'     => $durum,
    ];
}, $stmt->fetchAll());

echo json_encode(['ok' => true, 'sonuclar' => $sonuclar], JSON_UNESCAPED_UNICODE);
