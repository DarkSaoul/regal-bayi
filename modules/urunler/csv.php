<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$pdo = db();

// index.php ile aynı filtreler
$arama  = trim($_GET['ara'] ?? '');
$kat    = (int)($_GET['kat'] ?? 0);
$marka  = trim($_GET['marka'] ?? '');
$durum  = $_GET['durum'] ?? '1';
if (!in_array($durum, ['1','0','tumu'], true)) $durum = '1';
$stokF  = $_GET['stok'] ?? '';
if (!in_array($stokF, ['','yok','kritik','var'], true)) $stokF = '';
$ozel   = $_GET['ozel'] ?? '';
if (!in_array($ozel, ['','barkodsuz','olu'], true)) $ozel = '';

$where = "WHERE 1=1";
$params = [];
if ($durum !== 'tumu') { $where .= " AND u.aktif=" . (int)$durum; }
if ($arama) { $where .= " AND (u.ad LIKE ? OR u.kod LIKE ? OR u.barkod LIKE ? OR u.model LIKE ?)"; $params = array_merge($params, array_fill(0, 4, likeParam($arama))); }
if ($kat)   { $where .= " AND (u.kategori_id=? OR k.ust_id=?)"; $params = array_merge($params, [$kat, $kat]); }
if ($marka) { $where .= " AND u.marka=?"; $params[] = $marka; }
if ($stokF === 'yok')    $where .= " AND u.stok_adedi <= 0";
if ($stokF === 'kritik') $where .= " AND u.stok_adedi > 0 AND u.stok_adedi <= u.min_stok";
if ($stokF === 'var')    $where .= " AND u.stok_adedi > u.min_stok";
if ($ozel === 'barkodsuz') $where .= " AND (u.barkod IS NULL OR u.barkod='')";
if ($ozel === 'olu') $where .= " AND NOT EXISTS (SELECT 1 FROM satis_kalemleri sk JOIN satislar sx ON sk.satis_id=sx.id
                                 WHERE sk.urun_id=u.id AND sx.durum!='iptal' AND sx.tarih >= DATE_SUB(CURDATE(), INTERVAL 90 DAY))";

$stmt = $pdo->prepare("SELECT u.*, k.ad AS kategori_adi FROM urunler u LEFT JOIN kategoriler k ON u.kategori_id=k.id $where ORDER BY u.ad");
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="urunler_' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['Kod','Barkod','Ürün Adı','Kategori','Marka','Model','Renk','Birim','Alış Fiyatı','Satış Fiyatı','Marj %','KDV %','Stok','Min. Stok','Stok Değeri (Alış)','Seri No Takip','Durum'], ';');
foreach ($rows as $r) {
    $marj = ($r['alis_fiyati'] > 0 && $r['satis_fiyati'] > 0)
        ? number_format(($r['satis_fiyati'] - $r['alis_fiyati']) / $r['alis_fiyati'] * 100, 1, ',', '.') : '';
    fputcsv($out, [
        csvHucre($r['kod']), csvHucre($r['barkod']), csvHucre($r['ad']), csvHucre($r['kategori_adi'] ?? ''),
        csvHucre($r['marka']), csvHucre($r['model']), csvHucre($r['renk']), csvHucre($r['birim']),
        number_format($r['alis_fiyati'], 2, ',', '.'),
        number_format($r['satis_fiyati'], 2, ',', '.'),
        $marj,
        number_format($r['kdv_orani'], 0),
        $r['stok_adedi'], $r['min_stok'],
        number_format($r['stok_adedi'] * $r['alis_fiyati'], 2, ',', '.'),
        $r['seri_no_takip'] ? 'Evet' : 'Hayır',
        $r['aktif'] ? 'Aktif' : 'Arşiv',
    ], ';');
}
fclose($out);
exit;
