<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth(); yetki(['yonetici','kasiyer']);
$sayfa_basligi = 'Yeni Satış';
$pdo = db();
$rol = $_SESSION['rol'] ?? '';

$musteri_id = (int)($_GET['musteri_id'] ?? 0);
$degisim_id = (int)($_GET['degisim'] ?? 0);
$tekrar_id  = (int)($_GET['tekrar'] ?? 0);

$musteriler = $pdo->query("SELECT m.id, m.ad, COALESCE(m.soyad,'') AS soyad, COALESCE(m.firma_adi,'') AS firma_adi,
        COALESCE(m.telefon,'') AS telefon, COALESCE(m.adres,'') AS adres,
        m.toplam_borc, m.risk_limiti,
        (SELECT COUNT(*) FROM taksit_plani tp JOIN satislar s2 ON tp.satis_id=s2.id
         WHERE s2.musteri_id=m.id AND s2.durum!='iptal' AND tp.odendi=0 AND tp.vade_tarihi<CURDATE()) AS gecikmis
    FROM musteriler m WHERE m.aktif=1 ORDER BY m.ad")->fetchAll();
$urunler = $pdo->query("SELECT id, kod, barkod, ad, alis_fiyati, satis_fiyati, kdv_orani, stok_adedi, tesir_adedi FROM urunler WHERE aktif=1 ORDER BY ad")->fetchAll();
$sonMaliyet = sonAlisMaliyetleri();
$kasiyerMaxInd = (float)ayar('kasiyer_max_indirim', '0');

// Hızlı ürün butonları: son 90 günün en çok satan 12 ürünü
$hizliIds = $pdo->query("SELECT sk.urun_id FROM satis_kalemleri sk
    JOIN satislar s ON s.id=sk.satis_id AND s.durum!='iptal' AND s.tarih >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    JOIN urunler u ON u.id=sk.urun_id AND u.aktif=1
    GROUP BY sk.urun_id ORDER BY SUM(sk.miktar) DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);

// Park edilmiş sepetler (bu kullanıcının)
$parkStmt = $pdo->prepare("SELECT id, ad, veri, created_at FROM park_sepetler WHERE kullanici_id=? ORDER BY id DESC");
$parkStmt->execute([$_SESSION['kullanici_id']]);
$parklar = $parkStmt->fetchAll();

// Değişim modu: kaynak satışı doğrula
$degisimKaynak = null;
if ($degisim_id) {
    $dk = $pdo->prepare("SELECT id, fatura_no, musteri_id FROM satislar WHERE id=? AND durum!='iptal'");
    $dk->execute([$degisim_id]);
    $degisimKaynak = $dk->fetch();
    if (!$degisimKaynak) { $degisim_id = 0; }
    elseif (!$musteri_id && $degisimKaynak['musteri_id']) { $musteri_id = (int)$degisimKaynak['musteri_id']; }
}

// Satışı tekrarla: kalemleri ön doldur
$prefillKalemler = [];
if ($tekrar_id) {
    $ts = $pdo->prepare("SELECT musteri_id FROM satislar WHERE id=?");
    $ts->execute([$tekrar_id]);
    if ($tRow = $ts->fetch()) {
        if (!$musteri_id && $tRow['musteri_id']) $musteri_id = (int)$tRow['musteri_id'];
        $tk = $pdo->prepare("SELECT sk.urun_id, sk.miktar, sk.kdv_orani, u.satis_fiyati
                             FROM satis_kalemleri sk JOIN urunler u ON u.id=sk.urun_id AND u.aktif=1
                             WHERE sk.satis_id=?");
        $tk->execute([$tekrar_id]);
        foreach ($tk->fetchAll() as $k) {
            // Güncel satış fiyatı kullanılır (eski fiyattan tekrar satmamak için)
            $prefillKalemler[] = ['u' => (int)$k['urun_id'], 'm' => (int)$k['miktar'],
                                  'f' => (float)$k['satis_fiyati'], 'k' => (float)$k['kdv_orani'], 'i' => 0];
        }
    }
}

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $tarih = $d['tarih'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || strtotime($tarih) === false) $tarih = date('Y-m-d');
    $musteri   = (int)($d['musteri_id'] ?? 0) ?: null;
    $tip       = !empty($d['on_siparis']) ? 'on_siparis' : 'satis';
    $odeme_tipi = in_array($d['odeme_tipi'] ?? '', ['nakit','kredi_karti','havale','taksitli','bolunmus'])
                ? $d['odeme_tipi'] : 'nakit';
    $taksit_sayisi = $odeme_tipi === 'taksitli' ? max(1, (int)($d['taksit_sayisi'] ?? 1)) : 1;

    // Bölünmüş ödeme parçaları (nakit + kart + havale)
    $parcalar = [];
    if ($odeme_tipi === 'bolunmus') {
        foreach (['nakit' => 'b_nakit', 'kredi_karti' => 'b_kart', 'havale' => 'b_havale'] as $kanal => $alan) {
            $t = max(0, round((float)($d[$alan] ?? 0), 2));
            if ($t > 0) $parcalar[$kanal] = $t;
        }
        $odenen = round(array_sum($parcalar), 2);
    } else {
        $odenen = max(0, (float)($d['odenen_tutar'] ?? 0));
    }

    // Değişim bağlantısı doğrula
    $degisimPost = (int)($d['degisim_satis_id'] ?? 0) ?: null;
    if ($degisimPost) {
        $dg = $pdo->prepare("SELECT id FROM satislar WHERE id=? AND durum!='iptal'");
        $dg->execute([$degisimPost]);
        if (!$dg->fetchColumn()) $degisimPost = null;
    }

    $kalem_urunler    = $d['kalem_urun']    ?? [];
    $kalem_miktarlar  = $d['kalem_miktar']  ?? [];
    $kalem_fiyatlar   = $d['kalem_fiyat']   ?? [];
    $kalem_kdvler     = $d['kalem_kdv']     ?? [];
    $kalem_indirimler = $d['kalem_indirim'] ?? [];
    $kalem_tesirler   = $d['kalem_tesir']   ?? [];

    if (empty(array_filter($kalem_urunler))) {
        $hata = 'En az bir ürün eklemelisiniz.';
    } elseif ($odeme_tipi === 'bolunmus' && empty($parcalar)) {
        $hata = 'Bölünmüş ödemede en az bir ödeme tutarı girmelisiniz.';
    } else {
        // Fatura no üretimi ile insert arası yarışı önlemek için adlandırılmış kilit
        $pdo->query("SELECT GET_LOCK('regal_satis_kayit', 5)");
        $pdo->beginTransaction();
        try {
            $ara = 0; $kdv_t = 0; $indirim_t = 0; $brut = 0;
            $kalemler = [];
            $stokHatasi = [];
            $zararHatasi = [];
            $uyarilar = [];

            foreach ($kalem_urunler as $i => $uid) {
                if (!$uid) continue;
                $uid     = (int)$uid;
                $miktar  = max(1, (int)($kalem_miktarlar[$i] ?? 1));
                $fiyat   = max(0, round((float)($kalem_fiyatlar[$i] ?? 0), 2));
                $kdv     = min(100, max(0, round((float)($kalem_kdvler[$i] ?? 0), 2)));
                $indirim = max(0, round((float)($kalem_indirimler[$i] ?? 0), 2));
                $tesir_satis = ($tip === 'satis' && isset($kalem_tesirler[$i])) ? 1 : 0;

                // İndirim satır tutarını aşamaz (negatif satır önlenir)
                $indirim = min($indirim, round($fiyat * $miktar, 2));

                // Ürünü transaction içinde FOR UPDATE ile kilitle
                $stokStmt = $pdo->prepare("SELECT ad, stok_adedi, tesir_adedi, alis_fiyati FROM urunler WHERE id=? AND aktif=1 FOR UPDATE");
                $stokStmt->execute([$uid]);
                $urunRow = $stokStmt->fetch();
                if (!$urunRow) {
                    $stokHatasi[] = "Ürün bulunamadı (ID:$uid)";
                    continue;
                }
                // Stok kontrolü — ön siparişte stok düşülmediği için atlanır
                if ($tip === 'satis') {
                    if ($urunRow['stok_adedi'] < $miktar) {
                        $stokHatasi[] = '"' . $urunRow['ad'] . '" için yeterli stok yok'
                            . ' (İstenen: ' . $miktar . ', Mevcut: ' . $urunRow['stok_adedi'] . ')';
                    } elseif ($tesir_satis && $urunRow['tesir_adedi'] < $miktar) {
                        $stokHatasi[] = '"' . $urunRow['ad'] . '" için yeterli teşhir ürünü yok'
                            . ' (İstenen: ' . $miktar . ', Teşhirde: ' . $urunRow['tesir_adedi'] . ')';
                    }
                }

                // Zararına satış kontrolü: indirim sonrası birim net < alış fiyatı
                $alis = (float)$urunRow['alis_fiyati'];
                $birimNet = $miktar > 0 ? ($fiyat * $miktar - $indirim) / $miktar : 0;
                if ($alis > 0 && $birimNet < $alis - 0.005) {
                    $msg = '"' . $urunRow['ad'] . '" alış fiyatının altında satılıyor'
                         . ' (Net: ' . para($birimNet) . ', Alış: ' . para($alis) . ')';
                    if ($rol === 'kasiyer') $zararHatasi[] = $msg; else $uyarilar[] = $msg;
                }

                // Satış anı maliyet snapshot'ı: son gerçek alış maliyeti, yoksa kart alış fiyatı
                $maliyet = isset($sonMaliyet[$uid]) && $sonMaliyet[$uid] !== null
                         ? (float)$sonMaliyet[$uid] : $alis;

                $satir_ara = round($fiyat * $miktar - $indirim, 2);
                $satir_kdv = round($satir_ara * $kdv / 100, 2);
                $toplam    = round($satir_ara + $satir_kdv, 2);
                $ara += $satir_ara; $kdv_t += $satir_kdv; $indirim_t += $indirim; $brut += $fiyat * $miktar;
                $kalemler[] = [$uid, $miktar, $fiyat, $kdv, $satir_kdv, $indirim, $toplam, $tesir_satis, $maliyet];
            }

            if (!empty($stokHatasi))  throw new RuntimeException(implode(' • ', $stokHatasi));
            if (!empty($zararHatasi)) throw new RuntimeException('Zararına satış yetkiniz yok: ' . implode(' • ', $zararHatasi));

            // Kasiyer indirim yetki limiti (toplam indirim, brüt tutarın %X'ini aşamaz)
            if ($rol === 'kasiyer' && $kasiyerMaxInd > 0 && $brut > 0
                && ($indirim_t / $brut) * 100 > $kasiyerMaxInd + 0.001) {
                throw new RuntimeException('İndirim yetki limitiniz aşıldı: toplam indirim %'
                    . number_format(($indirim_t / $brut) * 100, 1, ',', '.')
                    . ' — izin verilen en fazla %' . number_format($kasiyerMaxInd, 1, ',', '.')
                    . '. Daha yüksek indirim için yönetici onayı gerekir.');
            }

            $genel = round($ara + $kdv_t, 2);
            // Para üstü kasaya/ciroya yazılmaz — ödenen tutar satış toplamını aşamaz
            if ($odeme_tipi === 'bolunmus' && $odenen > $genel + 0.005) {
                throw new RuntimeException('Bölünmüş ödeme toplamı (' . para($odenen) . ') satış toplamını (' . para($genel) . ') aşıyor.');
            }
            $odenen = min($odenen, $genel);
            $kalan = max(0, round($genel - $odenen, 2));
            $durum = $kalan > 0 ? 'bekliyor' : 'tamamlandi';

            // Müşteri risk limiti kontrolü (açık borç oluşuyorsa)
            if ($musteri && $kalan > 0) {
                $mk = $pdo->prepare("SELECT toplam_borc, risk_limiti, CONCAT(ad,' ',COALESCE(soyad,'')) AS adsoyad FROM musteriler WHERE id=? FOR UPDATE");
                $mk->execute([$musteri]);
                if ($mRow = $mk->fetch()) {
                    $limit = (float)$mRow['risk_limiti'];
                    $yeniBorc = (float)$mRow['toplam_borc'] + $kalan;
                    if ($limit > 0 && $yeniBorc > $limit + 0.005) {
                        $msg = trim($mRow['adsoyad']) . ' için risk limiti aşılıyor (Limit: ' . para($limit)
                             . ', Bu satışla borç: ' . para($yeniBorc) . ')';
                        if ($rol === 'kasiyer') throw new RuntimeException($msg . '. Yönetici onayı gerekir.');
                        $uyarilar[] = $msg;
                    }
                }
            }

            // Teslimat / montaj bilgileri
            $teslimatTarihi = null; $teslimatAdres = null; $teslimatDurum = 'yok';
            $montajTarihi = null; $montajNotu = null;
            if (!empty($d['teslimat_gerekli'])) {
                $teslimatTarihi = gecerliTarih($d['teslimat_tarihi'] ?? '', date('Y-m-d'));
                $teslimatAdres  = mb_substr(trim($d['teslimat_adresi'] ?? ''), 0, 1000);
                $teslimatDurum  = 'hazirlaniyor';
                if (!empty($d['montaj_tarihi'])) $montajTarihi = gecerliTarih($d['montaj_tarihi'], date('Y-m-d'));
                $montajNotu = mb_substr(trim($d['montaj_notu'] ?? ''), 0, 255) ?: null;
            }

            $fatura_no = yeniFaturaNo();
            $pdo->prepare("INSERT INTO satislar (fatura_no,tip,stok_dusuldu,musteri_id,kullanici_id,tarih,ara_toplam,kdv_toplam,indirim_toplam,genel_toplam,odeme_tipi,taksit_sayisi,odenen_tutar,kalan_tutar,durum,notlar,teslimat_tarihi,teslimat_adresi,teslimat_durum,montaj_tarihi,montaj_notu,degisim_satis_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$fatura_no, $tip, $tip === 'satis' ? 1 : 0, $musteri, $_SESSION['kullanici_id'], $tarih,
                           $ara, $kdv_t, $indirim_t, $genel, $odeme_tipi, $taksit_sayisi, $odenen, $kalan, $durum,
                           $d['notlar'] ?? '', $teslimatTarihi, $teslimatAdres, $teslimatDurum, $montajTarihi, $montajNotu, $degisimPost]);
            $satis_id = $pdo->lastInsertId();

            foreach ($kalemler as [$uid, $miktar, $fiyat, $kdv, $kdv_t2, $indirim, $toplam, $tesir_satis, $maliyet]) {
                $pdo->prepare("INSERT INTO satis_kalemleri (satis_id,urun_id,miktar,birim_fiyat,birim_maliyet,kdv_orani,kdv_tutar,indirim,toplam,tesir_satis) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$satis_id, $uid, $miktar, $fiyat, $maliyet, $kdv, $kdv_t2, $indirim, $toplam, $tesir_satis]);
                if ($tip === 'satis') {
                    stokGuncelle($uid, -$miktar, 'cikis', $fatura_no, 'Satış', null);
                    // Teşhir satışıysa tesir_adedi azalt
                    if ($tesir_satis) {
                        $pdo->prepare("UPDATE urunler SET tesir_adedi = GREATEST(0, tesir_adedi - ?) WHERE id=?")
                            ->execute([$miktar, $uid]);
                        // Seri no'lu ürün ise ilk tesirde kaydını satildi yap
                        $pdo->prepare("UPDATE seri_numaralari SET durum='satildi', satis_id=? WHERE urun_id=? AND durum='tesirde' LIMIT ?")
                            ->execute([$satis_id, $uid, $miktar]);
                    }
                }
            }

            if ($odenen > 0) {
                // Taksitli satışta ilk ödeme taksit_no=1 olarak kaydedilir
                $ilk_taksit_no = ($odeme_tipi === 'taksitli') ? 1 : null;
                if ($odeme_tipi === 'bolunmus') {
                    // Her kanal ayrı ödeme kaydı; nakit kasa hesabına, kart/havale banka hesabına işlenir
                    foreach ($parcalar as $kanal => $tutar) {
                        $pdo->prepare("INSERT INTO odemeler (satis_id,musteri_id,tarih,tutar,odeme_tipi,taksit_no,aciklama,kullanici_id) VALUES (?,?,?,?,?,?,?,?)")
                            ->execute([$satis_id, $musteri, $tarih, $tutar, $kanal, null, 'Satış (bölünmüş) - '.$fatura_no, $_SESSION['kullanici_id']]);
                        $hesap = $kanal === 'nakit' ? 'kasa' : 'banka';
                        $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,hesap,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$tarih, 'giris', $hesap, $tutar, 'Satış: '.$fatura_no.' (bölünmüş-'.$kanal.')', 'Satış', $_SESSION['kullanici_id']]);
                    }
                } else {
                    $odeme_kanali = $odeme_tipi === 'taksitli' ? 'nakit' : $odeme_tipi;
                    $pdo->prepare("INSERT INTO odemeler (satis_id,musteri_id,tarih,tutar,odeme_tipi,taksit_no,aciklama,kullanici_id) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$satis_id, $musteri, $tarih, $odenen, $odeme_kanali, $ilk_taksit_no, 'Satış - '.$fatura_no, $_SESSION['kullanici_id']]);
                    // Nakit → kasa hesabı, kart/havale → banka hesabı (kasa = fiziksel çekmece değil artık tek hesap değil)
                    $hesap = $odeme_kanali === 'nakit' ? 'kasa' : ($odeme_kanali === 'kredi_karti' || $odeme_kanali === 'havale' ? 'banka' : null);
                    if ($hesap) {
                        $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,hesap,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$tarih, 'giris', $hesap, $odenen, 'Satış: '.$fatura_no, 'Satış', $_SESSION['kullanici_id']]);
                    }
                }
            }

            // Taksit planı oluştur — peşinat kaç taksiti karşılıyorsa öyle işaretle
            if ($odeme_tipi === 'taksitli' && $taksit_sayisi > 1 && $genel > 0) {
                $taksit_tutari = round($genel / $taksit_sayisi, 2);
                $fark = round($genel - ($taksit_tutari * $taksit_sayisi), 2); // kuruş farkı son taksitte
                $kalanOdeme = $odenen;
                for ($t = 1; $t <= $taksit_sayisi; $t++) {
                    $vade = date('Y-m-d', strtotime("+{$t} month", strtotime($tarih)));
                    $bu_tutar = ($t === $taksit_sayisi) ? round($taksit_tutari + $fark, 2) : $taksit_tutari;
                    $odendi = 0; $odeme_tarihi = null;
                    if ($kalanOdeme >= $bu_tutar - 0.005) {
                        $odendi = 1; $odeme_tarihi = $tarih;
                        $kalanOdeme = round($kalanOdeme - $bu_tutar, 2);
                    }
                    $pdo->prepare("INSERT INTO taksit_plani (satis_id, taksit_no, tutar, vade_tarihi, odendi, odeme_tarihi) VALUES (?,?,?,?,?,?)")
                        ->execute([$satis_id, $t, $bu_tutar, $vade, $odendi, $odeme_tarihi]);
                }
            }

            // Park edilmiş sepetten geldiyse park kaydını temizle
            $parkId = (int)($d['park_id'] ?? 0);
            if ($parkId) {
                $pdo->prepare("DELETE FROM park_sepetler WHERE id=? AND kullanici_id=?")
                    ->execute([$parkId, $_SESSION['kullanici_id']]);
            }

            // Müşteri borcunu açık satışlardan yeniden hesapla
            musteriBorcuYenile($musteri);

            $pdo->commit();
            $pdo->query("SELECT RELEASE_LOCK('regal_satis_kayit')");
            logla('satis_olustur', 'satislar', $satis_id, "Fatura: $fatura_no | " . para($genel)
                . ($tip === 'on_siparis' ? ' | ÖN SİPARİŞ' : '') . ($degisimPost ? " | Değişim: #$degisimPost" : ''));
            $mesaj = ($tip === 'on_siparis' ? 'Ön sipariş kaydedildi (stok düşülmedi). ' : 'Satış kaydedildi. ') . "Fatura: $fatura_no";
            if (!empty($uyarilar)) $mesaj .= ' | DİKKAT: ' . implode(' • ', $uyarilar);
            flash(!empty($uyarilar) ? 'uyari' : 'basari', $mesaj);
            header('Location: detay.php?id=' . $satis_id); exit;
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $pdo->query("SELECT RELEASE_LOCK('regal_satis_kayit')");
            $hata = $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $pdo->query("SELECT RELEASE_LOCK('regal_satis_kayit')");
            $hata = 'Hata: ' . $e->getMessage();
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.musteri-dropdown { position:absolute; z-index:1000; background:#fff; border:1px solid #dee2e6; border-radius:8px; max-height:220px; overflow-y:auto; width:100%; box-shadow:0 4px 16px rgba(0,0,0,.1); }
.musteri-dropdown .item { padding:9px 14px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:.9rem; }
.musteri-dropdown .item:hover, .musteri-dropdown .item.active { background:#e8f0fe; }
.musteri-dropdown .item .firma { color:#6c757d; font-size:.8rem; }
#barkodInput::placeholder { color:#aaa; }
.kalem-stok-uyari { font-size:.75rem; }
.toplam-kutu { background:linear-gradient(135deg,#0d6efd,#0a58ca); border-radius:12px; }
.indirim-tip-btn { min-width:44px; }
.hizli-urun-btn { min-width:110px; max-width:160px; white-space:normal; font-size:.78rem; line-height:1.15; }
.hizli-urun-btn .fiyat { font-size:.72rem; opacity:.75; }
.kisayol-bar kbd { background:#343a40; border-radius:4px; padding:1px 6px; font-size:.72rem; }
</style>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4><i class="bi bi-receipt text-primary"></i> Yeni Satış
        <?php if ($degisim_id): ?>
        <span class="badge bg-info text-dark"><i class="bi bi-arrow-left-right"></i> Değişim: <?= escH($degisimKaynak['fatura_no']) ?></span>
        <?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-warning btn-sm position-relative" onclick="parkListesiAc()" title="Park edilmiş sepetler (F8 ile park et)">
            <i class="bi bi-pause-circle"></i> Park Edilenler
            <span class="badge bg-warning text-dark ms-1" id="parkSayac"><?= count($parklar) ?></span>
        </button>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">← Satışlar</a>
    </div>
</div>

<?php
// Son 30 dk içinde sayım sayfası açıldıysa bilgilendir (sayım sırasında satış farkı çakışma korumasıyla atlanır)
$sayimAcilis = (int)ayar('sayim_son_acilis', '0');
if ($sayimAcilis && (time() - $sayimAcilis) < 1800): ?>
<div class="alert alert-info alert-dismissible py-2">
    <i class="bi bi-clipboard-check"></i> Şu anda <strong>stok sayımı yapılıyor olabilir</strong> (sayım ekranı son 30 dk içinde açıldı).
    Satış yapabilirsiniz; sayım tarafında bu ürünler çakışma koruması ile atlanır.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($hata): ?>
<div class="alert alert-danger alert-dismissible"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Taslak geri yükleme bannerı (localStorage) -->
<div class="alert alert-warning py-2 d-none" id="taslakBanner">
    <i class="bi bi-clock-history"></i> Kaydedilmemiş bir <strong>satış taslağı</strong> bulundu (<span id="taslakZaman"></span>).
    <button type="button" class="btn btn-sm btn-warning ms-2" onclick="taslakGeriYukle()">Geri Yükle</button>
    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="taslakSil()">Sil</button>
</div>

<form method="post" id="satisForm">
<?= csrfField() ?>
<input type="hidden" name="degisim_satis_id" value="<?= $degisim_id ?>">
<input type="hidden" name="park_id" id="parkIdInput" value="">
<div class="row g-3">

    <!-- ===== SOL PANEL ===== -->
    <div class="col-xl-3 col-lg-4">

        <!-- Müşteri -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person text-primary"></i> Müşteri</span>
                <a href="<?= BASE_URL ?>/modules/musteriler/ekle.php?geri=<?= urlencode('/regal/modules/satislar/yeni.php?d=1') ?>"
                   class="btn btn-sm btn-outline-primary py-0" title="Listede yoksa hızlıca ekleyin — kaydedince bu ekrana dönersiniz">
                    <i class="bi bi-person-plus"></i> Yeni</a>
            </div>
            <div class="card-body pb-2">
                <input type="hidden" name="musteri_id" id="musteriId" value="<?= $musteri_id ?>">
                <div class="position-relative">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="musteriArama" class="form-control border-start-0"
                               placeholder="Ad, telefon ile ara... (F4)"
                               autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary" id="musteriTemizle" title="Temizle" style="display:none">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <div id="musteriDropdown" class="musteri-dropdown" style="display:none"></div>
                </div>
                <div id="musteriSecili" class="mt-2 p-2 bg-light rounded d-none">
                    <div class="fw-semibold" id="musteriAd"></div>
                    <div class="small text-muted" id="musteriTel"></div>
                    <div class="mt-1" id="musteriRozetler"></div>
                    <div class="mt-2 small" id="musteriGecmis"></div>
                </div>
            </div>
        </div>

        <!-- Satış Bilgileri -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-info-circle text-primary"></i> Satış Bilgileri
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1">Tarih</label>
                        <input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1">Ödeme Tipi</label>
                        <select name="odeme_tipi" id="odemeTipi" class="form-select form-select-sm" onchange="odemeTipiDegisti()">
                            <option value="nakit">💵 Nakit</option>
                            <option value="kredi_karti">💳 Kredi Kartı</option>
                            <option value="havale">🏦 Havale / EFT</option>
                            <option value="bolunmus">➗ Bölünmüş (Nakit+Kart)</option>
                            <option value="taksitli">📅 Taksitli</option>
                        </select>
                    </div>
                    <!-- Taksit seçenekleri (sadece taksitli seçilince görünür) -->
                    <div class="col-12" id="taksitAlani" style="display:none">
                        <div class="p-2 bg-light rounded border">
                            <label class="form-label small fw-semibold mb-2">
                                <i class="bi bi-calendar-week text-primary"></i> Taksit Sayısı
                            </label>
                            <div class="d-flex flex-wrap gap-2" id="taksitButonlar">
                                <?php foreach ([2,3,4,6,9,12,18,24,36] as $t): ?>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="taksit_sayisi"
                                           id="t<?= $t ?>" value="<?= $t ?>"
                                           onchange="taksitHesapla()"
                                           <?= $t === 12 ? 'checked' : '' ?>>
                                    <label class="form-check-label btn btn-sm btn-outline-primary px-2 py-1" for="t<?= $t ?>">
                                        <?= $t ?>x
                                    </label>
                                </div>
                                <?php endforeach; ?>
                                <!-- Özel taksit -->
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="taksit_sayisi"
                                           id="tOzel" value="ozel" onchange="taksitHesapla()">
                                    <label class="form-check-label btn btn-sm btn-outline-secondary px-2 py-1" for="tOzel">
                                        Özel
                                    </label>
                                </div>
                            </div>
                            <div id="ozelTaksitAlan" class="mt-2" style="display:none">
                                <input type="number" id="ozelTaksitSayisi" class="form-control form-control-sm"
                                       min="2" max="60" placeholder="Taksit sayısı girin"
                                       style="width:160px" oninput="taksitHesapla()">
                            </div>
                            <div id="taksitBilgi" class="mt-2 small text-muted"></div>
                        </div>
                    </div>

                    <!-- Ön sipariş -->
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="on_siparis" value="1" id="onSiparisSw" onchange="onSiparisDegisti()">
                            <label class="form-check-label small fw-semibold" for="onSiparisSw">
                                <i class="bi bi-bag-plus text-info"></i> Ön Sipariş <span class="text-muted">(stokta yok — kapora alınır)</span>
                            </label>
                        </div>
                        <div class="small text-muted d-none" id="onSiparisBilgi">
                            <i class="bi bi-info-circle"></i> Stok düşülmez, stok kontrolü yapılmaz. Ürün gelince satış detayından
                            <strong>Teslim Et</strong> ile stoktan düşülür. Alınan ödeme kapora olarak işlenir.
                        </div>
                    </div>

                    <!-- Teslimat -->
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="teslimat_gerekli" value="1" id="teslimatSw" onchange="teslimatDegisti()">
                            <label class="form-check-label small fw-semibold" for="teslimatSw">
                                <i class="bi bi-truck text-success"></i> Adrese Teslimat / Montaj
                            </label>
                        </div>
                        <div class="p-2 bg-light rounded border mt-1 d-none" id="teslimatAlani">
                            <label class="form-label small mb-1">Teslimat Tarihi</label>
                            <input type="date" name="teslimat_tarihi" id="teslimatTarihi" class="form-control form-control-sm mb-2" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                            <label class="form-label small mb-1">Teslimat Adresi</label>
                            <textarea name="teslimat_adresi" id="teslimatAdresi" class="form-control form-control-sm mb-2" rows="2" placeholder="Müşteri seçilince adresi otomatik gelir"></textarea>
                            <label class="form-label small mb-1">Montaj Randevusu <span class="text-muted">(opsiyonel)</span></label>
                            <input type="date" name="montaj_tarihi" class="form-control form-control-sm mb-2">
                            <input type="text" name="montaj_notu" class="form-control form-control-sm" maxlength="255" placeholder="Montaj notu (kat, saat aralığı vb.)">
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">Notlar</label>
                        <textarea name="notlar" id="satisNotlar" class="form-control form-control-sm" rows="2" placeholder="İsteğe bağlı..."></textarea>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ===== SAĞ PANEL: Ürünler ===== -->
    <div class="col-xl-9 col-lg-8">

        <!-- Hızlı ürünler (son 90 günün en çok satanları) -->
        <?php if (!empty($hizliIds)): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center" style="cursor:pointer"
                 data-bs-toggle="collapse" data-bs-target="#hizliUrunler">
                <span class="fw-semibold small"><i class="bi bi-lightning-charge text-warning"></i> Hızlı Ürünler <span class="text-muted">(çok satanlar — tıkla, sepete eklensin)</span></span>
                <i class="bi bi-chevron-down text-muted"></i>
            </div>
            <div class="collapse show" id="hizliUrunler">
                <div class="card-body py-2 d-flex flex-wrap gap-2" id="hizliUrunKutu"></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-white py-2">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md">
                        <span class="fw-semibold"><i class="bi bi-cart text-primary"></i> Satış Kalemleri</span>
                    </div>
                    <div class="col-12 col-md-auto">
                        <!-- Barkod girişi + kamera butonu -->
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white">
                                <i class="bi bi-upc-scan text-primary"></i>
                            </span>
                            <input type="text" id="barkodInput" class="form-control"
                                   placeholder="Barkod / Ürün kodu (Esc)"
                                   autocomplete="off" inputmode="text">
                            <!-- Kamera tarama butonu (mobil + masaüstü) -->
                            <button type="button" class="btn btn-outline-success"
                                    onclick="kameraIleTara('satis')"
                                    title="Kamera ile barkod tara">
                                <i class="bi bi-camera"></i>
                            </button>
                            <button type="button" class="btn btn-primary" onclick="barkodEkle()">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-6 col-md-auto">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="kalemEkle()">
                            <i class="bi bi-list-ul"></i> Listeden Seç
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle" id="kalemTable">
                    <thead class="table-light">
                        <tr>
                            <th>Ürün</th>
                            <th style="width:80px" class="text-center">Miktar</th>
                            <th style="width:130px">Fiyat (₺)</th>
                            <th style="width:65px" class="text-center">KDV%</th>
                            <th style="width:130px">İndirim</th>
                            <th style="width:115px" class="text-end">Satır Toplamı</th>
                            <th style="width:36px"></th>
                        </tr>
                    </thead>
                    <tbody id="kalemBody">
                        <tr id="bosKalemMesaj">
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-cart-plus fs-2 d-block mb-2 opacity-25"></i>
                                Barkod okutun veya "Listeden Seç" butonuna tıklayın
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Tutar Özeti + Kaydet -->
        <div class="toplam-kutu text-white p-3 mt-3">
            <input type="hidden" id="genel-input" name="genel_toplam_hidden">
            <div class="row align-items-center g-3">
                <!-- Tutarlar -->
                <div class="col-md-4">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="opacity-75">Ara Toplam</span>
                        <span id="ara-toplam">0,00 ₺</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="opacity-75">KDV</span>
                        <span id="kdv-toplam">0,00 ₺</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1" id="satirIndirimSatir" style="display:none!important">
                        <span class="opacity-75">Satır İndirimi</span>
                        <span class="text-warning" id="satir-indirim">0,00 ₺</span>
                    </div>
                    <!-- Genel (sepet) indirimi -->
                    <div class="d-flex justify-content-between align-items-center mb-1 gap-2">
                        <span class="opacity-75 text-nowrap">Genel İndirim</span>
                        <div class="input-group input-group-sm" style="max-width:150px">
                            <input type="number" id="genelIndirim" class="form-control form-control-sm" step="0.01" min="0" value="" placeholder="0">
                            <button type="button" class="btn btn-warning indirim-tip-btn" id="genelIndirimTip" data-tip="tl" onclick="genelIndirimTipDegistir()">₺</button>
                        </div>
                    </div>
                    <div class="small text-warning d-none" id="indirimLimitUyari"></div>
                    <hr class="border-white opacity-25 my-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold fs-5">TOPLAM</span>
                        <span class="fw-bold fs-3" id="genel-toplam">0,00 ₺</span>
                    </div>
                </div>
                <!-- Ödenen -->
                <div class="col-md-4">
                    <div id="normalOdemeAlani">
                        <label class="small fw-semibold opacity-75 mb-1">Ödenen Tutar (₺) <span class="opacity-50">(F2)</span></label>
                        <div class="input-group mb-1">
                            <input type="number" id="odenen-input" name="odenen_tutar"
                                   class="form-control form-control-lg fw-bold text-center"
                                   step="0.01" min="0" value="0" placeholder="0,00">
                            <button type="button" class="btn btn-warning fw-bold" onclick="tamOdemeAl()" title="Tamamını Öde">TAM</button>
                        </div>
                        <div class="d-flex gap-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-light py-0" onclick="hizliNakit(50)">50₺</button>
                            <button type="button" class="btn btn-sm btn-light py-0" onclick="hizliNakit(100)">100₺</button>
                            <button type="button" class="btn btn-sm btn-light py-0" onclick="hizliNakit(200)">200₺</button>
                            <button type="button" class="btn btn-sm btn-light py-0" onclick="hizliNakit(500)">500₺</button>
                            <button type="button" class="btn btn-sm btn-light py-0" onclick="hizliNakit(1000)">1.000₺</button>
                        </div>
                    </div>
                    <!-- Bölünmüş ödeme alanları -->
                    <div id="bolunmusAlani" class="d-none">
                        <label class="small fw-semibold opacity-75 mb-1">Bölünmüş Ödeme</label>
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text" style="min-width:80px">💵 Nakit</span>
                            <input type="number" name="b_nakit" id="bNakit" class="form-control b-parca" step="0.01" min="0" placeholder="0,00">
                            <button type="button" class="btn btn-warning py-0" onclick="kalaniYaz('bNakit')" title="Kalanı bu alana yaz">Kalan</button>
                        </div>
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text" style="min-width:80px">💳 Kart</span>
                            <input type="number" name="b_kart" id="bKart" class="form-control b-parca" step="0.01" min="0" placeholder="0,00">
                            <button type="button" class="btn btn-warning py-0" onclick="kalaniYaz('bKart')" title="Kalanı bu alana yaz">Kalan</button>
                        </div>
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text" style="min-width:80px">🏦 Havale</span>
                            <input type="number" name="b_havale" id="bHavale" class="form-control b-parca" step="0.01" min="0" placeholder="0,00">
                            <button type="button" class="btn btn-warning py-0" onclick="kalaniYaz('bHavale')" title="Kalanı bu alana yaz">Kalan</button>
                        </div>
                        <div class="small opacity-75">Parçaların toplamı ödenen tutardır; kasaya yalnızca nakit parça işlenir.</div>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="opacity-75">Kalan Borç</span>
                        <span class="fw-bold fs-5" id="kalan-tutar">0,00 ₺</span>
                    </div>
                </div>
                <!-- Kaydet -->
                <div class="col-md-4 d-flex flex-column align-items-end justify-content-center gap-2">
                    <button type="submit" class="btn btn-success btn-lg fw-bold shadow px-5 py-3" style="font-size:1.1rem">
                        <i class="bi bi-check-circle-fill me-2"></i>Satışı Kaydet
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="sepetiParkEt()">
                        <i class="bi bi-pause-circle"></i> Sepeti Park Et (F8)
                    </button>
                </div>
            </div>
        </div>

        <!-- Klavye kısayolları -->
        <div class="text-muted small mt-2 kisayol-bar d-none d-md-block">
            <kbd>F2</kbd> Ödenen tutar &nbsp; <kbd>F4</kbd> Müşteri ara &nbsp; <kbd>F8</kbd> Park et &nbsp;
            <kbd>F9</kbd> Kaydet &nbsp; <kbd>Esc</kbd> Barkod alanına dön
        </div>
    </div>

</div>
</form>

<!-- Park edilen sepetler modalı -->
<div class="modal fade" id="parkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-pause-circle text-warning"></i> Park Edilen Sepetler</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush" id="parkListe">
                    <div class="text-center text-muted py-4">Yükleniyor...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window._baseUrl = '<?= BASE_URL ?>';
const CSRF = '<?= csrfToken() ?>';
const ROL = '<?= escH($rol) ?>';
const KASIYER_MAX_IND = <?= json_encode($kasiyerMaxInd) ?>;

// ── Ürün verisi ──────────────────────────────────────────────
const urunler = <?= json_encode(array_map(fn($u) => [
    'id'     => $u['id'],
    'kod'    => $u['kod'],
    'barkod' => $u['barkod'] ?? '',
    'ad'     => $u['ad'],
    'label'  => '[' . $u['kod'] . '] ' . $u['ad'],
    'fiyat'  => (float)$u['satis_fiyati'],
    'alis'   => (float)$u['alis_fiyati'],
    'kdv'    => (float)$u['kdv_orani'],
    'stok'   => (int)$u['stok_adedi'],
    'tesir'  => (int)$u['tesir_adedi'],
], $urunler), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window._urunler = urunler; // main.js barkodEkle() için erişim

// ── Müşteri verisi ───────────────────────────────────────────
const musteriler = <?= json_encode(array_map(fn($m) => [
    'id'       => $m['id'],
    'ad'       => trim($m['ad'] . ' ' . $m['soyad']),
    'firma'    => $m['firma_adi'],
    'tel'      => $m['telefon'],
    'adres'    => $m['adres'],
    'borc'     => (float)$m['toplam_borc'],
    'limit'    => (float)$m['risk_limiti'],
    'gecikmis' => (int)$m['gecikmis'],
], $musteriler), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const hizliIds = <?= json_encode(array_map('intval', $hizliIds)) ?>;
window._prefill = <?= json_encode($prefillKalemler, JSON_UNESCAPED_UNICODE) ?>;

let kalemSayaci = 0;
const TASLAK_KEY = 'regal_satis_taslak';
let taslakDevre = false; // geri yükleme sırasında otomatik kaydı kapat

// ── Yardımcı ─────────────────────────────────────────────────
function fmt(n) {
    return new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2}).format(n) + ' ₺';
}
function bosKalemGizle() {
    const el = document.getElementById('bosKalemMesaj');
    if (el) el.style.display = document.querySelectorAll('.kalem-row').length ? 'none' : '';
}

// ── Hesapla ──────────────────────────────────────────────────
function genelIndirimTl(araNet) {
    const btn = document.getElementById('genelIndirimTip');
    const val = parseFloat(document.getElementById('genelIndirim').value) || 0;
    if (val <= 0) return 0;
    let g = btn.dataset.tip === 'yuzde' ? araNet * val / 100 : val;
    return Math.min(g, araNet);
}

function satisHesapla() {
    let araNet = 0, indT = 0;
    const satirlar = [];
    document.querySelectorAll('.kalem-row').forEach(row => {
        const fiyat   = parseFloat(row.querySelector('.k-fiyat').value)   || 0;
        const miktar  = parseInt(row.querySelector('.k-miktar').value)    || 0;
        const kdv     = parseFloat(row.querySelector('.k-kdv').value)     || 0;
        let indirim   = parseFloat(row.querySelector('.k-indirim').value) || 0;
        if (row.querySelector('.k-indirim-tip').dataset.tip === 'yuzde') {
            indirim = (indirim / 100) * fiyat * miktar;
        }
        indirim = Math.min(indirim, fiyat * miktar);
        const net = fiyat * miktar - indirim;
        satirlar.push({row, net, kdv, fiyat, miktar, indirim});
        araNet += net;
        indT   += indirim;
        zararKontrol(row, fiyat, miktar, indirim);
    });
    // Genel indirimi satır netlerine oranlı dağıt, KDV'yi indirilmiş matrahtan hesapla
    const G = genelIndirimTl(araNet);
    let ara = 0, kdvT = 0;
    satirlar.forEach(s => {
        const pay = araNet > 0 ? G * s.net / araNet : 0;
        const satirAra = s.net - pay;
        const satirKdv = satirAra * s.kdv / 100;
        s.row.querySelector('.k-toplam').textContent = fmt(satirAra + satirKdv);
        ara += satirAra; kdvT += satirKdv;
    });
    const genel = ara + kdvT;
    document.getElementById('ara-toplam').textContent  = fmt(ara);
    document.getElementById('kdv-toplam').textContent  = fmt(kdvT);
    document.getElementById('genel-toplam').textContent = fmt(genel);
    document.getElementById('genel-input').value = genel.toFixed(2);
    if (indT > 0) {
        document.getElementById('satir-indirim').textContent = fmt(indT);
        document.getElementById('satirIndirimSatir').style.removeProperty('display');
    } else {
        document.getElementById('satirIndirimSatir').style.setProperty('display','none','important');
    }
    indirimLimitKontrol(indT + G, satirlar.reduce((t,s) => t + s.fiyat * s.miktar, 0));
    odenenHesapla();
    taslakKaydet();
}

// Kasiyer indirim yetki limiti uyarısı (sunucu tarafında da doğrulanır)
function indirimLimitKontrol(toplamInd, brut) {
    const el = document.getElementById('indirimLimitUyari');
    if (ROL === 'kasiyer' && KASIYER_MAX_IND > 0 && brut > 0 && (toplamInd / brut) * 100 > KASIYER_MAX_IND) {
        el.textContent = '⚠ İndirim yetki limitiniz %' + KASIYER_MAX_IND + ' — bu satış kaydedilemez, yönetici onayı gerekir.';
        el.classList.remove('d-none');
    } else {
        el.classList.add('d-none');
    }
}

// Zararına satış rozeti (satır bazında)
function zararKontrol(row, fiyat, miktar, indirimTl) {
    const sel = row.querySelector('.k-urun');
    const opt = sel.options[sel.selectedIndex];
    const alis = parseFloat(opt?.dataset?.alis || 0);
    let z = row.querySelector('.k-zarar');
    const birimNet = miktar > 0 ? (fiyat * miktar - indirimTl) / miktar : 0;
    if (alis > 0 && birimNet < alis - 0.005) {
        if (!z) {
            z = document.createElement('div');
            z.className = 'k-zarar small text-danger fw-semibold';
            row.querySelector('.k-stok-info').after(z);
        }
        z.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Zararına satış! Alış: ' + fmt(alis)
            + (ROL === 'kasiyer' ? ' — yetkiniz yok' : '');
    } else if (z) {
        z.remove();
    }
}

function odenenHesapla() {
    const genel = parseFloat(document.getElementById('genel-input').value) || 0;
    let odenen;
    if (document.getElementById('odemeTipi').value === 'bolunmus') {
        odenen = 0;
        document.querySelectorAll('.b-parca').forEach(i => odenen += parseFloat(i.value) || 0);
    } else {
        odenen = parseFloat(document.getElementById('odenen-input').value) || 0;
    }
    const kalan = Math.max(0, genel - odenen);
    document.getElementById('kalan-tutar').textContent = fmt(kalan);
}

function tamOdemeAl() {
    const genel = parseFloat(document.getElementById('genel-input').value) || 0;
    document.getElementById('odenen-input').value = genel.toFixed(2);
    odenenHesapla();
}

function hizliNakit(tutar) {
    const mevcut = parseFloat(document.getElementById('odenen-input').value) || 0;
    document.getElementById('odenen-input').value = (mevcut + tutar).toFixed(2);
    odenenHesapla();
}

// Bölünmüş ödeme: kalanı seçilen alana yaz
function kalaniYaz(id) {
    const genel = parseFloat(document.getElementById('genel-input').value) || 0;
    let diger = 0;
    document.querySelectorAll('.b-parca').forEach(i => { if (i.id !== id) diger += parseFloat(i.value) || 0; });
    document.getElementById(id).value = Math.max(0, genel - diger).toFixed(2);
    odenenHesapla();
}

// ── Kalem Satırı ─────────────────────────────────────────────
function kalemEkle(urun = null) {
    bosKalemGizle();
    const idx = kalemSayaci++;
    const row = document.createElement('tr');
    row.className = 'kalem-row';

    const options = urunler.map(u =>
        `<option value="${u.id}" data-fiyat="${u.fiyat}" data-alis="${u.alis}" data-kdv="${u.kdv}" data-stok="${u.stok}" data-tesir="${u.tesir}" data-kod="${u.kod}" data-barkod="${u.barkod}"
            ${urun && urun.id == u.id ? ' selected' : ''}>${u.label} (Stok: ${u.stok})</option>`
    ).join('');

    const fiyat   = urun ? urun.fiyat : '';
    const kdv     = urun ? urun.kdv   : 20;
    const stok    = urun ? urun.stok  : null;
    const tesirAd = urun ? urun.tesir : 0;
    const stokRenk = stok !== null ? (stok <= 0 ? 'danger' : stok <= 3 ? 'warning' : 'success') : '';
    let stokMsg = stok !== null ? `<span class="badge bg-${stokRenk} kalem-stok-uyari me-1">${stok} stok</span>` : '';
    if (tesirAd > 0) stokMsg += `<span class="badge bg-warning text-dark kalem-stok-uyari"><i class="bi bi-shop-window"></i> ${tesirAd} teşhir</span>`;

    row.innerHTML = `
        <td>
            <select name="kalem_urun[]" class="form-select form-select-sm k-urun" required>
                <option value="">Ürün seçin...</option>${options}
            </select>
            <div class="k-stok-info mt-1">${stokMsg}</div>
            <div class="k-tesir-div mt-1" style="display:${tesirAd > 0 ? '' : 'none'}">
                <div class="form-check form-check-sm">
                    <input class="form-check-input k-tesir" type="checkbox"
                           name="kalem_tesir[${idx}]" value="1" id="tesir_${idx}">
                    <label class="form-check-label small text-warning fw-semibold" for="tesir_${idx}">
                        <i class="bi bi-shop-window"></i> Teşhir ürününden sat
                    </label>
                </div>
            </div>
        </td>
        <td>
            <input type="number" name="kalem_miktar[]" class="form-control form-control-sm k-miktar text-center fw-bold"
                   min="1" value="1" style="width:70px">
        </td>
        <td>
            <input type="number" name="kalem_fiyat[]" class="form-control form-control-sm k-fiyat"
                   step="0.01" min="0" value="${fiyat}" placeholder="0,00">
        </td>
        <td>
            <input type="number" name="kalem_kdv[]" class="form-control form-control-sm k-kdv text-center"
                   min="0" max="100" value="${kdv}" style="width:55px">
        </td>
        <td>
            <div class="input-group input-group-sm">
                <input type="number" name="kalem_indirim[]" class="form-control k-indirim"
                       step="0.01" min="0" value="0" placeholder="0">
                <button type="button" class="btn btn-outline-secondary indirim-tip-btn k-indirim-tip"
                        data-tip="tl" title="₺ / % değiştir" onclick="indirimTipDegistir(this)">₺</button>
            </div>
        </td>
        <td class="text-end">
            <span class="k-toplam fw-bold text-primary">0,00 ₺</span>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger px-1"
                    onclick="kalemSil(this)" title="Satırı sil">
                <i class="bi bi-trash"></i>
            </button>
        </td>`;

    // Ürün seçilince fiyat/kdv doldur
    row.querySelector('.k-urun').addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        row.querySelector('.k-fiyat').value = opt.dataset.fiyat || '';
        row.querySelector('.k-kdv').value   = opt.dataset.kdv   || 20;
        const stok  = parseInt(opt.dataset.stok  ?? -1);
        const tesir = parseInt(opt.dataset.tesir ?? 0);
        const renk  = stok <= 0 ? 'danger' : stok <= 3 ? 'warning' : 'success';
        let info = '';
        if (opt.value) {
            info = `<span class="badge bg-${renk} kalem-stok-uyari me-1">Stok: ${stok}</span>`;
            if (tesir > 0) {
                info += `<span class="badge bg-warning text-dark kalem-stok-uyari">
                    <i class="bi bi-shop-window"></i> ${tesir} teşhir
                </span>`;
            }
        }
        row.querySelector('.k-stok-info').innerHTML = info;
        // Teşhir checkbox göster/gizle
        const tesirDiv = row.querySelector('.k-tesir-div');
        const onSip = document.getElementById('onSiparisSw').checked;
        tesirDiv.style.display = (opt.value && tesir > 0 && !onSip) ? '' : 'none';
        if (!opt.value || tesir === 0) row.querySelector('.k-tesir').checked = false;
        satisHesapla();
    });

    document.getElementById('kalemBody').appendChild(row);
    bosKalemGizle();
    satisHesapla();
    return row;
}

function kalemSil(btn) {
    btn.closest('tr').remove();
    bosKalemGizle();
    satisHesapla();
}

// Veri nesnesinden satır oluştur (taslak / park / tekrarla geri yüklemesi)
function kalemEkleVeri(k) {
    const u = urunler.find(x => x.id == k.u);
    if (!u) return;
    const row = kalemEkle(u);
    row.querySelector('.k-miktar').value = k.m || 1;
    row.querySelector('.k-fiyat').value  = (k.f ?? u.fiyat);
    row.querySelector('.k-kdv').value    = (k.k ?? u.kdv);
    row.querySelector('.k-indirim').value = k.i || 0;
    if ((k.it || 'tl') === 'yuzde') {
        const btn = row.querySelector('.k-indirim-tip');
        btn.dataset.tip = 'yuzde'; btn.textContent = '%';
        btn.classList.add('btn-warning'); btn.classList.remove('btn-outline-secondary');
    }
    if (k.t) { const c = row.querySelector('.k-tesir'); if (c) c.checked = true; }
    satisHesapla();
}

// ── İndirim ₺ / % toggle ─────────────────────────────────────
function indirimTipDegistir(btn) {
    const row   = btn.closest('tr');
    const input = row.querySelector('.k-indirim');
    const fiyat = parseFloat(row.querySelector('.k-fiyat').value) || 0;
    const miktar = parseInt(row.querySelector('.k-miktar').value) || 1;
    const eskiDeger = parseFloat(input.value) || 0;

    if (btn.dataset.tip === 'tl') {
        // ₺ → % : girilen tutarı yüzdeye çevir
        const yuzde = fiyat * miktar > 0 ? (eskiDeger / (fiyat * miktar)) * 100 : 0;
        input.value = yuzde.toFixed(1);
        input.placeholder = '%';
        btn.dataset.tip = 'yuzde';
        btn.textContent = '%';
        btn.classList.add('btn-warning');
        btn.classList.remove('btn-outline-secondary');
    } else {
        // % → ₺ : yüzdeyi tutara çevir
        const tl = (eskiDeger / 100) * fiyat * miktar;
        input.value = tl.toFixed(2);
        input.placeholder = '0';
        btn.dataset.tip = 'tl';
        btn.textContent = '₺';
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-outline-secondary');
    }
    satisHesapla();
}

function genelIndirimTipDegistir() {
    const btn = document.getElementById('genelIndirimTip');
    if (btn.dataset.tip === 'tl') { btn.dataset.tip = 'yuzde'; btn.textContent = '%'; }
    else { btn.dataset.tip = 'tl'; btn.textContent = '₺'; }
    satisHesapla();
}

// Form gönderiminde: % indirimleri ₺'ye çevir + genel indirimi satırlara dağıt
document.getElementById('satisForm').addEventListener('submit', function () {
    // 1) Satır % → ₺
    document.querySelectorAll('.kalem-row').forEach(row => {
        const btn = row.querySelector('.k-indirim-tip');
        if (btn.dataset.tip === 'yuzde') {
            const fiyat  = parseFloat(row.querySelector('.k-fiyat').value) || 0;
            const miktar = parseInt(row.querySelector('.k-miktar').value)  || 1;
            const yuzde  = parseFloat(row.querySelector('.k-indirim').value) || 0;
            row.querySelector('.k-indirim').value = ((yuzde / 100) * fiyat * miktar).toFixed(2);
            btn.dataset.tip = 'tl';
        }
    });
    // 2) Genel indirimi satır netlerine oranlı dağıt (satır indirimine eklenir)
    let araNet = 0;
    const netler = [];
    document.querySelectorAll('.kalem-row').forEach(row => {
        const fiyat  = parseFloat(row.querySelector('.k-fiyat').value) || 0;
        const miktar = parseInt(row.querySelector('.k-miktar').value)  || 1;
        const ind    = parseFloat(row.querySelector('.k-indirim').value) || 0;
        const net    = Math.max(0, fiyat * miktar - ind);
        netler.push({row, net});
        araNet += net;
    });
    const G = genelIndirimTl(araNet);
    if (G > 0 && araNet > 0) {
        let dagitilan = 0;
        netler.forEach((s, i) => {
            let pay = (i === netler.length - 1) ? G - dagitilan : Math.round(G * s.net / araNet * 100) / 100;
            pay = Math.min(pay, s.net);
            dagitilan += pay;
            const indInp = s.row.querySelector('.k-indirim');
            indInp.value = ((parseFloat(indInp.value) || 0) + pay).toFixed(2);
        });
        document.getElementById('genelIndirim').value = '';
    }
    // 3) Taslağı temizle
    taslakDevre = true;
    localStorage.removeItem(TASLAK_KEY);
});

// ── Barkod / Kod ile hızlı ekleme ────────────────────────────
function barkodEkle() {
    const val = document.getElementById('barkodInput').value.trim();
    if (!val) return;
    const u = urunler.find(u => u.barkod === val || u.kod === val || u.kod.toLowerCase() === val.toLowerCase());
    if (u) {
        urunSepeteEkle(u);
        document.getElementById('barkodInput').value = '';
        document.getElementById('barkodInput').focus();
    } else {
        document.getElementById('barkodInput').classList.add('is-invalid');
        setTimeout(() => document.getElementById('barkodInput').classList.remove('is-invalid'), 1200);
    }
}

// Aynı ürün satırda varsa miktar artır, yoksa yeni satır (barkod + hızlı buton ortak)
function urunSepeteEkle(u) {
    let bulundu = false;
    document.querySelectorAll('.kalem-row').forEach(row => {
        const sel = row.querySelector('.k-urun');
        if (sel.value == u.id) {
            const mk = row.querySelector('.k-miktar');
            mk.value = parseInt(mk.value) + 1;
            mk.classList.add('bg-warning');
            setTimeout(() => mk.classList.remove('bg-warning'), 600);
            satisHesapla();
            bulundu = true;
        }
    });
    if (!bulundu) kalemEkle(u);
}

document.getElementById('barkodInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); barkodEkle(); }
});

// ── Hızlı ürün butonları ─────────────────────────────────────
(function () {
    const kutu = document.getElementById('hizliUrunKutu');
    if (!kutu) return;
    hizliIds.forEach(id => {
        const u = urunler.find(x => x.id == id);
        if (!u) return;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-primary btn-sm hizli-urun-btn';
        b.innerHTML = `${u.ad}<br><span class="fiyat">${fmt(u.fiyat)} • Stok:${u.stok}</span>`;
        b.onclick = () => urunSepeteEkle(u);
        kutu.appendChild(b);
    });
})();

// ── Müşteri Arama ────────────────────────────────────────────
const musteriInput  = document.getElementById('musteriArama');
const musteriDrop   = document.getElementById('musteriDropdown');
const musteriIdInp  = document.getElementById('musteriId');
const musteriTemizleBtn = document.getElementById('musteriTemizle');

musteriInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    musteriTemizleBtn.style.display = q ? '' : 'none';
    if (q.length < 1) { musteriDrop.style.display = 'none'; musteriIdInp.value = ''; return; }
    const sonuclar = musteriler.filter(m =>
        m.ad.toLowerCase().includes(q) || m.tel.includes(q) || m.firma.toLowerCase().includes(q)
    ).slice(0, 8);
    if (!sonuclar.length) { musteriDrop.style.display = 'none'; return; }
    musteriDrop.innerHTML = sonuclar.map(m =>
        `<div class="item" onclick="musteriSecId(${m.id})">
            <strong>${m.ad}</strong>${m.firma ? ' <span class="firma">(' + m.firma + ')</span>' : ''}
            ${m.borc > 0 ? '<span class="badge bg-danger ms-1">Borç: ' + fmt(m.borc) + '</span>' : ''}
            <div class="firma">${m.tel || ''}</div>
        </div>`
    ).join('');
    musteriDrop.style.display = '';
});

function musteriSecId(id) {
    const m = musteriler.find(x => x.id == id);
    if (m) musteriSec(m);
}

function musteriSec(m) {
    musteriIdInp.value = m.id;
    musteriInput.value = m.ad + (m.firma ? ' (' + m.firma + ')' : '');
    musteriDrop.style.display = 'none';
    musteriTemizleBtn.style.display = '';
    document.getElementById('musteriSecili').classList.remove('d-none');
    document.getElementById('musteriAd').textContent = m.ad + (m.firma ? ' — ' + m.firma : '');
    document.getElementById('musteriTel').textContent = m.tel || '';
    // Borç / limit / gecikmiş taksit rozetleri
    let roz = '';
    if (m.borc > 0)     roz += `<span class="badge bg-danger me-1">Açık Borç: ${fmt(m.borc)}</span>`;
    if (m.gecikmis > 0) roz += `<span class="badge bg-warning text-dark me-1">⚠ ${m.gecikmis} gecikmiş taksit</span>`;
    if (m.limit > 0)    roz += `<span class="badge bg-secondary me-1">Risk Limiti: ${fmt(m.limit)}</span>`;
    document.getElementById('musteriRozetler').innerHTML = roz;
    // Teslimat adresi boşsa müşteri adresini öner
    const ta = document.getElementById('teslimatAdresi');
    if (ta && !ta.value && m.adres) ta.value = m.adres;
    // Son alımlar (AJAX)
    const g = document.getElementById('musteriGecmis');
    g.innerHTML = '<span class="text-muted">Son alımlar yükleniyor...</span>';
    fetch('park.php?action=musteri_gecmis&id=' + m.id)
        .then(r => r.json())
        .then(j => {
            if (!j.ok || !j.satislar.length) { g.innerHTML = '<span class="text-muted">Önceki alım yok</span>'; return; }
            g.innerHTML = '<div class="fw-semibold text-muted mb-1">Son Alımlar</div>' + j.satislar.map(s =>
                `<div class="d-flex justify-content-between border-bottom py-1">
                    <a href="detay.php?id=${s.id}" target="_blank" class="text-decoration-none">${s.fatura}</a>
                    <span>${s.tarih}</span><span class="fw-semibold">${s.tutar}</span>
                </div>`).join('');
        })
        .catch(() => { g.innerHTML = ''; });
    taslakKaydet();
}

musteriTemizleBtn.addEventListener('click', () => {
    musteriInput.value = '';
    musteriIdInp.value = '';
    musteriTemizleBtn.style.display = 'none';
    musteriDrop.style.display = 'none';
    document.getElementById('musteriSecili').classList.add('d-none');
    taslakKaydet();
});

document.addEventListener('click', e => {
    if (!e.target.closest('#musteriArama') && !e.target.closest('#musteriDropdown'))
        musteriDrop.style.display = 'none';
});

<?php if ($musteri_id): ?>
musteriSecId(<?= $musteri_id ?>);
<?php endif; ?>

// ── Event: input değişince hesapla ──────────────────────────
document.addEventListener('input', e => {
    if (e.target.closest('.kalem-row')) satisHesapla();
    if (e.target.id === 'odenen-input' || e.target.classList.contains('b-parca')) odenenHesapla();
    if (e.target.id === 'genelIndirim') satisHesapla();
    if (e.target.id === 'satisNotlar') taslakKaydet();
});

bosKalemGizle();

// ── Ödeme tipi / Taksit / Bölünmüş ──────────────────────────
function odemeTipiDegisti() {
    const tip = document.getElementById('odemeTipi').value;
    document.getElementById('taksitAlani').style.display = tip === 'taksitli' ? '' : 'none';
    document.getElementById('bolunmusAlani').classList.toggle('d-none', tip !== 'bolunmus');
    document.getElementById('normalOdemeAlani').classList.toggle('d-none', tip === 'bolunmus');
    if (tip === 'taksitli') taksitHesapla();
    odenenHesapla();
    taslakKaydet();
}

// ── Ön sipariş ───────────────────────────────────────────────
function onSiparisDegisti() {
    const acik = document.getElementById('onSiparisSw').checked;
    document.getElementById('onSiparisBilgi').classList.toggle('d-none', !acik);
    // Ön siparişte teşhirden satış olmaz
    document.querySelectorAll('.k-tesir-div').forEach(d => {
        d.style.display = acik ? 'none' : d.style.display;
        if (acik) { const c = d.querySelector('.k-tesir'); if (c) c.checked = false; }
    });
    if (!acik) satisHesapla(); // teşhir görünürlüğü ürün değişiminde tazelenir
}

function teslimatDegisti() {
    document.getElementById('teslimatAlani').classList.toggle('d-none', !document.getElementById('teslimatSw').checked);
}

function taksitHesapla() {
    const secili = document.querySelector('input[name="taksit_sayisi"]:checked');
    if (!secili) return;
    const ozelAlan = document.getElementById('ozelTaksitAlan');
    let sayi;
    if (secili.value === 'ozel') {
        ozelAlan.style.display = '';
        sayi = parseInt(document.getElementById('ozelTaksitSayisi').value) || 0;
    } else {
        ozelAlan.style.display = 'none';
        sayi = parseInt(secili.value);
    }
    const genel = parseFloat(document.getElementById('genel-input').value) || 0;
    const bilgi = document.getElementById('taksitBilgi');
    if (sayi >= 2 && genel > 0) {
        const aylik = genel / sayi;
        bilgi.innerHTML = `<i class="bi bi-info-circle text-primary"></i>
            <strong>${sayi} taksit</strong> × <strong class="text-primary">${fmt(aylik)}</strong>/ay
            = Toplam ${fmt(genel)}`;
    } else if (sayi >= 2) {
        bilgi.innerHTML = `<i class="bi bi-info-circle text-muted"></i> Ürün ekleyince aylık taksit görünecek`;
    } else {
        bilgi.innerHTML = '';
    }
    // Buton stillerini güncelle
    document.querySelectorAll('input[name="taksit_sayisi"]').forEach(i => {
        const lbl = i.nextElementSibling;
        if (!lbl) return;
        if (i.checked) {
            lbl.classList.remove('btn-outline-primary','btn-outline-secondary');
            lbl.classList.add(i.value === 'ozel' ? 'btn-secondary' : 'btn-primary');
        } else {
            lbl.classList.remove('btn-primary','btn-secondary');
            lbl.classList.add(i.value === 'ozel' ? 'btn-outline-secondary' : 'btn-outline-primary');
        }
    });
}

// Özel taksit input'una tıklayınca "Özel" radio seçilsin
document.getElementById('ozelTaksitSayisi').addEventListener('focus', () => {
    document.getElementById('tOzel').checked = true;
    taksitHesapla();
});
document.getElementById('ozelTaksitSayisi').addEventListener('input', taksitHesapla);

// satisHesapla çalışınca taksit bilgisini de güncelle
const _origSatisHesapla = satisHesapla;
satisHesapla = function() {
    _origSatisHesapla();
    if (document.getElementById('odemeTipi').value === 'taksitli') taksitHesapla();
};

// Form gönderiminde özel taksit sayısını name="taksit_sayisi"'ya yaz
document.getElementById('satisForm').addEventListener('submit', function(e) {
    const tip = document.getElementById('odemeTipi').value;
    if (tip === 'taksitli') {
        const secili = document.querySelector('input[name="taksit_sayisi"]:checked');
        if (secili && secili.value === 'ozel') {
            const ozelSayi = parseInt(document.getElementById('ozelTaksitSayisi').value) || 0;
            if (ozelSayi < 2) { e.preventDefault(); alert('Özel taksit sayısı en az 2 olmalıdır.'); return; }
            secili.value = ozelSayi;
        }
    }
}, true);

// ── Sepet serileştirme (taslak + park ortak) ────────────────
function sepetVerisi() {
    const kalemler = [];
    document.querySelectorAll('.kalem-row').forEach(row => {
        const uid = row.querySelector('.k-urun').value;
        if (!uid) return;
        kalemler.push({
            u: parseInt(uid),
            m: row.querySelector('.k-miktar').value,
            f: row.querySelector('.k-fiyat').value,
            k: row.querySelector('.k-kdv').value,
            i: row.querySelector('.k-indirim').value,
            it: row.querySelector('.k-indirim-tip').dataset.tip,
            t: row.querySelector('.k-tesir')?.checked ? 1 : 0,
        });
    });
    return {
        kalemler,
        musteri_id: musteriIdInp.value,
        odeme_tipi: document.getElementById('odemeTipi').value,
        odenen: document.getElementById('odenen-input').value,
        genel_ind: document.getElementById('genelIndirim').value,
        genel_ind_tip: document.getElementById('genelIndirimTip').dataset.tip,
        notlar: document.getElementById('satisNotlar').value,
        zaman: new Date().toLocaleString('tr-TR'),
    };
}

function sepetYukle(v) {
    taslakDevre = true;
    document.querySelectorAll('.kalem-row').forEach(r => r.remove());
    (v.kalemler || []).forEach(k => kalemEkleVeri(k));
    if (v.musteri_id) musteriSecId(parseInt(v.musteri_id));
    if (v.odeme_tipi) { document.getElementById('odemeTipi').value = v.odeme_tipi; odemeTipiDegisti(); }
    if (v.odenen) document.getElementById('odenen-input').value = v.odenen;
    if (v.genel_ind) document.getElementById('genelIndirim').value = v.genel_ind;
    if (v.genel_ind_tip === 'yuzde') { const b = document.getElementById('genelIndirimTip'); b.dataset.tip = 'yuzde'; b.textContent = '%'; }
    if (v.notlar) document.getElementById('satisNotlar').value = v.notlar;
    bosKalemGizle();
    taslakDevre = false;
    satisHesapla();
}

// ── Taslak (localStorage) ────────────────────────────────────
let taslakTimer = null;
function taslakKaydet() {
    if (taslakDevre) return;
    clearTimeout(taslakTimer);
    taslakTimer = setTimeout(() => {
        const v = sepetVerisi();
        if (v.kalemler.length) localStorage.setItem(TASLAK_KEY, JSON.stringify(v));
        else localStorage.removeItem(TASLAK_KEY);
    }, 400);
}

function taslakGeriYukle() {
    try {
        const v = JSON.parse(localStorage.getItem(TASLAK_KEY) || 'null');
        if (v) sepetYukle(v);
    } catch (e) {}
    document.getElementById('taslakBanner').classList.add('d-none');
}

function taslakSil() {
    localStorage.removeItem(TASLAK_KEY);
    document.getElementById('taslakBanner').classList.add('d-none');
}

// Sayfa açılışı: prefill (tekrarla) > taslak bannerı
document.addEventListener('DOMContentLoaded', () => {
    if (window._prefill && window._prefill.length) {
        taslakDevre = true;
        window._prefill.forEach(k => kalemEkleVeri(k));
        taslakDevre = false;
        satisHesapla();
        return;
    }
    try {
        const v = JSON.parse(localStorage.getItem(TASLAK_KEY) || 'null');
        if (v && v.kalemler && v.kalemler.length) {
            document.getElementById('taslakZaman').textContent = v.zaman || '';
            document.getElementById('taslakBanner').classList.remove('d-none');
        }
    } catch (e) {}
});

// ── Park (askıya alma) ───────────────────────────────────────
function sepetiParkEt() {
    const v = sepetVerisi();
    if (!v.kalemler.length) { alert('Park edilecek sepet boş.'); return; }
    const ad = prompt('Park adı (müşteri adı vb.):', musteriInput.value || ('Sepet ' + new Date().toLocaleTimeString('tr-TR', {hour: '2-digit', minute: '2-digit'})));
    if (ad === null) return;
    const fd = new FormData();
    fd.append('action', 'kaydet');
    fd.append('ad', ad);
    fd.append('veri', JSON.stringify(v));
    fetch('park.php', {method: 'POST', headers: {'X-CSRF-Token': CSRF}, body: fd})
        .then(r => r.json())
        .then(j => {
            if (!j.ok) { alert(j.hata || 'Park edilemedi.'); return; }
            // Sepeti temizle
            taslakDevre = true;
            document.querySelectorAll('.kalem-row').forEach(r => r.remove());
            localStorage.removeItem(TASLAK_KEY);
            taslakDevre = false;
            bosKalemGizle(); satisHesapla();
            parkSayacGuncelle();
        })
        .catch(() => alert('Bağlantı hatası.'));
}

function parkSayacGuncelle() {
    fetch('park.php?action=liste')
        .then(r => r.json())
        .then(j => { if (j.ok) document.getElementById('parkSayac').textContent = j.liste.length; })
        .catch(() => {});
}

function parkListesiAc() {
    const modal = new bootstrap.Modal(document.getElementById('parkModal'));
    modal.show();
    const kutu = document.getElementById('parkListe');
    kutu.innerHTML = '<div class="text-center text-muted py-4">Yükleniyor...</div>';
    fetch('park.php?action=liste')
        .then(r => r.json())
        .then(j => {
            if (!j.ok || !j.liste.length) {
                kutu.innerHTML = '<div class="text-center text-muted py-4">Park edilmiş sepet yok</div>';
                document.getElementById('parkSayac').textContent = '0';
                return;
            }
            document.getElementById('parkSayac').textContent = j.liste.length;
            kutu.innerHTML = j.liste.map(p =>
                `<div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">${p.ad.replace(/</g,'&lt;')}</div>
                        <div class="small text-muted">${p.adet} kalem • ${p.zaman}</div>
                    </div>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-success" onclick="parkDevamEt(${p.id})"><i class="bi bi-play-fill"></i> Devam Et</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="parkSil(${p.id})"><i class="bi bi-trash"></i></button>
                    </div>
                </div>`).join('');
        })
        .catch(() => { kutu.innerHTML = '<div class="text-center text-danger py-4">Liste alınamadı</div>'; });
}

function parkDevamEt(id) {
    const fd = new FormData();
    fd.append('action', 'al');
    fd.append('id', id);
    fetch('park.php', {method: 'POST', headers: {'X-CSRF-Token': CSRF}, body: fd})
        .then(r => r.json())
        .then(j => {
            if (!j.ok) { alert(j.hata || 'Sepet alınamadı.'); return; }
            sepetYukle(j.veri);
            document.getElementById('parkIdInput').value = j.park_id;
            bootstrap.Modal.getInstance(document.getElementById('parkModal'))?.hide();
        })
        .catch(() => alert('Bağlantı hatası.'));
}

function parkSil(id) {
    if (!confirm('Bu park edilmiş sepet silinsin mi?')) return;
    const fd = new FormData();
    fd.append('action', 'sil');
    fd.append('id', id);
    fetch('park.php', {method: 'POST', headers: {'X-CSRF-Token': CSRF}, body: fd})
        .then(r => r.json())
        .then(() => parkListesiAc())
        .catch(() => {});
}

// ── Klavye kısayolları ───────────────────────────────────────
document.addEventListener('keydown', function (e) {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('odenen-input').focus(); document.getElementById('odenen-input').select(); }
    else if (e.key === 'F4') { e.preventDefault(); musteriInput.focus(); }
    else if (e.key === 'F8') { e.preventDefault(); sepetiParkEt(); }
    else if (e.key === 'F9') { e.preventDefault(); document.getElementById('satisForm').requestSubmit(); }
    else if (e.key === 'Escape') {
        const b = document.getElementById('barkodInput');
        b.value = ''; b.focus();
    }
});
</script>

<!-- Barkod tarayıcı modülü -->
<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
