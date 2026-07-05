<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrfVerify();
$pdo = db();
$islem = $_POST['islem'] ?? '';

try {
    if ($islem === 'arsivle' || $islem === 'aktif') {
        $id = (int)($_POST['id'] ?? 0);
        $m = $pdo->prepare("SELECT ad, soyad, firma_adi, toplam_borc FROM musteriler WHERE id=?");
        $m->execute([$id]);
        if (!($mk = $m->fetch())) throw new Exception('Müşteri bulunamadı.');
        $adi = trim(($mk['firma_adi'] ?: '') . ' ' . $mk['ad'] . ' ' . ($mk['soyad'] ?? ''));
        if ($islem === 'arsivle' && $mk['toplam_borc'] > 0)
            throw new Exception("\"$adi\" arşivlenemez: " . para($mk['toplam_borc']) . ' açık borcu var. Önce tahsil edin.');
        $pdo->prepare("UPDATE musteriler SET aktif=? WHERE id=?")->execute([$islem === 'aktif' ? 1 : 0, $id]);
        logla($islem === 'aktif' ? 'musteri_aktif' : 'musteri_arsiv', 'musteriler', $id, $adi);
        flash('basari', "\"$adi\" " . ($islem === 'aktif' ? 'arşivden çıkarıldı.' : 'arşive taşındı.'));

    } elseif ($islem === 'birlestir') {
        $kaynak = (int)($_POST['kaynak_id'] ?? 0);
        $hedef  = (int)($_POST['hedef_id'] ?? 0);
        if (!$kaynak || !$hedef || $kaynak === $hedef) throw new Exception('Geçerli bir kaynak ve hedef müşteri seçin.');
        $s = $pdo->prepare("SELECT id, ad, soyad, firma_adi FROM musteriler WHERE id IN (?,?)");
        $s->execute([$kaynak, $hedef]);
        $kayitlar = [];
        foreach ($s->fetchAll() as $r) $kayitlar[$r['id']] = trim(($r['firma_adi'] ?: '') . ' ' . $r['ad'] . ' ' . ($r['soyad'] ?? ''));
        if (count($kayitlar) !== 2) throw new Exception('Müşteri bulunamadı.');

        $pdo->beginTransaction();
        $satis = $pdo->prepare("UPDATE satislar SET musteri_id=? WHERE musteri_id=?");
        $satis->execute([$hedef, $kaynak]);
        $satisSayi = $satis->rowCount();
        $pdo->prepare("UPDATE odemeler SET musteri_id=? WHERE musteri_id=?")->execute([$hedef, $kaynak]);
        $pdo->prepare("UPDATE musteri_notlari SET musteri_id=? WHERE musteri_id=?")->execute([$hedef, $kaynak]);
        $pdo->prepare("DELETE FROM musteriler WHERE id=?")->execute([$kaynak]);
        musteriBorcuYenile($hedef);
        $pdo->commit();
        logla('musteri_birlestir', 'musteriler', $hedef, "{$kayitlar[$kaynak]} → {$kayitlar[$hedef]} ($satisSayi satış taşındı)");
        flash('basari', "\"{$kayitlar[$kaynak]}\" kaydı \"{$kayitlar[$hedef]}\" ile birleştirildi ($satisSayi satış taşındı).");
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('hata', $e->getMessage());
}
header('Location: index.php'); exit;
