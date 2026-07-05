<?php
// Satış ekranı AJAX uçları: sepet park etme / geri çağırma + müşteri geçmişi
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
header('Content-Type: application/json; charset=UTF-8');
$pdo = db();
$uid = (int)$_SESSION['kullanici_id'];
$action = $_REQUEST['action'] ?? '';

function jsonCik(array $veri, int $kod = 200): void {
    http_response_code($kod);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

// POST işlemlerinde CSRF (redirect yerine JSON hata)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        jsonCik(['ok' => false, 'hata' => 'Güvenlik doğrulaması başarısız. Sayfayı yenileyin.'], 403);
    }
}

try {
    switch ($action) {

        case 'liste': {
            $stmt = $pdo->prepare("SELECT id, ad, veri, created_at FROM park_sepetler WHERE kullanici_id=? ORDER BY id DESC");
            $stmt->execute([$uid]);
            $liste = [];
            foreach ($stmt->fetchAll() as $r) {
                $v = json_decode($r['veri'], true);
                $liste[] = [
                    'id'    => (int)$r['id'],
                    'ad'    => $r['ad'],
                    'adet'  => is_array($v['kalemler'] ?? null) ? count($v['kalemler']) : 0,
                    'zaman' => tarihSaat($r['created_at']),
                ];
            }
            jsonCik(['ok' => true, 'liste' => $liste]);
        }

        case 'kaydet': {
            $ad   = mb_substr(trim($_POST['ad'] ?? ''), 0, 100) ?: ('Sepet ' . date('H:i'));
            $veri = $_POST['veri'] ?? '';
            $v = json_decode($veri, true);
            if (!is_array($v) || empty($v['kalemler'])) {
                jsonCik(['ok' => false, 'hata' => 'Park edilecek sepet boş.'], 400);
            }
            $adet = $pdo->prepare("SELECT COUNT(*) FROM park_sepetler WHERE kullanici_id=?");
            $adet->execute([$uid]);
            if ((int)$adet->fetchColumn() >= 20) {
                jsonCik(['ok' => false, 'hata' => 'En fazla 20 park edilmiş sepet tutulabilir. Eskileri silin.'], 400);
            }
            $pdo->prepare("INSERT INTO park_sepetler (kullanici_id, ad, veri) VALUES (?,?,?)")
                ->execute([$uid, $ad, $veri]);
            logla('sepet_park', 'satislar', (int)$pdo->lastInsertId(), $ad);
            jsonCik(['ok' => true]);
        }

        case 'al': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT veri FROM park_sepetler WHERE id=? AND kullanici_id=?");
            $stmt->execute([$id, $uid]);
            $veri = $stmt->fetchColumn();
            if ($veri === false) jsonCik(['ok' => false, 'hata' => 'Park kaydı bulunamadı.'], 404);
            // Kayıt satış ekranına yüklenirken silinmez; satış kaydedilince park_id ile silinir.
            jsonCik(['ok' => true, 'veri' => json_decode($veri, true), 'park_id' => $id]);
        }

        case 'sil': {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM park_sepetler WHERE id=? AND kullanici_id=?")->execute([$id, $uid]);
            jsonCik(['ok' => true]);
        }

        case 'musteri_gecmis': {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, fatura_no, tarih, genel_toplam, kalan_tutar, durum, tip
                                   FROM satislar WHERE musteri_id=? ORDER BY id DESC LIMIT 5");
            $stmt->execute([$id]);
            $satislar = array_map(fn($s) => [
                'id'     => (int)$s['id'],
                'fatura' => $s['fatura_no'],
                'tarih'  => tarih($s['tarih']),
                'tutar'  => para($s['genel_toplam']),
                'kalan'  => (float)$s['kalan_tutar'],
                'durum'  => $s['durum'],
            ], $stmt->fetchAll());
            jsonCik(['ok' => true, 'satislar' => $satislar]);
        }

        default:
            jsonCik(['ok' => false, 'hata' => 'Geçersiz işlem.'], 400);
    }
} catch (Exception $e) {
    jsonCik(['ok' => false, 'hata' => 'Sunucu hatası: ' . $e->getMessage()], 500);
}
