<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Yeni Satış';
$pdo = db();

$musteri_id = (int)($_GET['musteri_id'] ?? 0);
$musteriler = $pdo->query("SELECT id, ad, COALESCE(soyad,'') AS soyad, COALESCE(firma_adi,'') AS firma_adi, COALESCE(telefon,'') AS telefon FROM musteriler ORDER BY ad")->fetchAll();
$urunler    = $pdo->query("SELECT id, kod, barkod, ad, satis_fiyati, kdv_orani, stok_adedi FROM urunler WHERE aktif=1 ORDER BY ad")->fetchAll();

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $d = $_POST;
    $tarih     = $d['tarih'] ?: date('Y-m-d');
    $fatura_no = yeniFaturaNo();
    $musteri   = (int)($d['musteri_id'] ?? 0) ?: null;
    $odeme_tipi   = $d['odeme_tipi'] ?? 'nakit';
    $taksit_sayisi = $odeme_tipi === 'taksitli' ? max(1, (int)($d['taksit_sayisi'] ?? 1)) : 1;
    $odenen    = (float)($d['odenen_tutar'] ?? 0);

    $kalem_urunler    = $d['kalem_urun']    ?? [];
    $kalem_miktarlar  = $d['kalem_miktar']  ?? [];
    $kalem_fiyatlar   = $d['kalem_fiyat']   ?? [];
    $kalem_kdvler     = $d['kalem_kdv']     ?? [];
    $kalem_indirimler = $d['kalem_indirim'] ?? [];

    if (empty(array_filter($kalem_urunler))) {
        $hata = 'En az bir ürün eklemelisiniz.';
    } else {
        $ara = 0; $kdv_t = 0; $indirim_t = 0;
        $kalemler = [];
        $stokHatasi = [];

        foreach ($kalem_urunler as $i => $uid) {
            if (!$uid) continue;
            $miktar  = max(1, (int)($kalem_miktarlar[$i] ?? 1));
            $fiyat   = round((float)($kalem_fiyatlar[$i] ?? 0), 2);
            $kdv     = round((float)($kalem_kdvler[$i] ?? 0), 2);
            $indirim = round((float)($kalem_indirimler[$i] ?? 0), 2);

            // Stok kontrolü — FOR UPDATE ile kilitle (race condition önleme)
            $stokStmt = $pdo->prepare("SELECT ad, stok_adedi FROM urunler WHERE id=? FOR UPDATE");
            $stokStmt->execute([$uid]);
            $urunRow = $stokStmt->fetch();
            if (!$urunRow || $urunRow['stok_adedi'] < $miktar) {
                $stokHatasi[] = '"' . ($urunRow['ad'] ?? "ID:$uid") . '" için yeterli stok yok'
                    . ' (İstenen: ' . $miktar . ', Mevcut: ' . ($urunRow['stok_adedi'] ?? 0) . ')';
            }

            $satir_ara = round($fiyat * $miktar - $indirim, 2);
            $satir_kdv = round($satir_ara * $kdv / 100, 2);
            $toplam    = round($satir_ara + $satir_kdv, 2);
            $ara += $satir_ara; $kdv_t += $satir_kdv; $indirim_t += $indirim;
            $kalemler[] = [$uid, $miktar, $fiyat, $kdv, $satir_kdv, $indirim, $toplam];
        }

        if (!empty($stokHatasi)) {
            $hata = implode('<br>', $stokHatasi);
        } else {

        $genel = round($ara + $kdv_t, 2);
        $kalan = max(0, round($genel - $odenen, 2));
        $durum = $kalan > 0 ? 'bekliyor' : 'tamamlandi';

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO satislar (fatura_no,musteri_id,kullanici_id,tarih,ara_toplam,kdv_toplam,indirim_toplam,genel_toplam,odeme_tipi,taksit_sayisi,odenen_tutar,kalan_tutar,durum,notlar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$fatura_no, $musteri, $_SESSION['kullanici_id'], $tarih, $ara, $kdv_t, $indirim_t, $genel, $odeme_tipi, $taksit_sayisi, $odenen, $kalan, $durum, $d['notlar'] ?? '']);
            $satis_id = $pdo->lastInsertId();

            foreach ($kalemler as [$uid, $miktar, $fiyat, $kdv, $kdv_t2, $indirim, $toplam]) {
                $pdo->prepare("INSERT INTO satis_kalemleri (satis_id,urun_id,miktar,birim_fiyat,kdv_orani,kdv_tutar,indirim,toplam) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$satis_id, $uid, $miktar, $fiyat, $kdv, $kdv_t2, $indirim, $toplam]);
                stokGuncelle($uid, -$miktar, 'cikis', $fatura_no, 'Satış', null);
            }

            if ($odenen > 0) {
                // Taksitli satışta ilk ödeme taksit_no=1 olarak kaydedilir
                $ilk_taksit_no = ($odeme_tipi === 'taksitli') ? 1 : null;
                $pdo->prepare("INSERT INTO odemeler (satis_id,musteri_id,tarih,tutar,odeme_tipi,taksit_no,aciklama,kullanici_id) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$satis_id, $musteri, $tarih, $odenen, $odeme_tipi === 'taksitli' ? 'nakit' : $odeme_tipi, $ilk_taksit_no, 'Satış - '.$fatura_no, $_SESSION['kullanici_id']]);
                $pdo->prepare("INSERT INTO kasa_hareketleri (tarih,tip,tutar,aciklama,kategori,kullanici_id) VALUES (?,?,?,?,?,?)")
                    ->execute([$tarih, 'giris', $odenen, 'Satış: '.$fatura_no, 'Satış', $_SESSION['kullanici_id']]);
            }
            if ($musteri && $kalan > 0) {
                $pdo->prepare("UPDATE musteriler SET toplam_borc = toplam_borc + ? WHERE id=?")->execute([$kalan, $musteri]);
            }

            // Taksit planı oluştur
            if ($odeme_tipi === 'taksitli' && $taksit_sayisi > 1 && $genel > 0) {
                $taksit_tutari = round($genel / $taksit_sayisi, 2);
                $fark = round($genel - ($taksit_tutari * $taksit_sayisi), 2); // kuruş farkı son taksitte
                for ($t = 1; $t <= $taksit_sayisi; $t++) {
                    $vade = date('Y-m-d', strtotime("+{$t} month", strtotime($tarih)));
                    $bu_tutar = ($t === $taksit_sayisi) ? round($taksit_tutari + $fark, 2) : $taksit_tutari;
                    $odendi = ($t === 1 && $odenen >= $taksit_tutari) ? 1 : 0;
                    $pdo->prepare("INSERT INTO taksit_plani (satis_id, taksit_no, tutar, vade_tarihi, odendi) VALUES (?,?,?,?,?)")
                        ->execute([$satis_id, $t, $bu_tutar, $vade, $odendi]);
                }
            }

            $pdo->commit();
            logla('satis_olustur', 'satislar', $satis_id, "Fatura: $fatura_no | " . para($genel));
            flash('basari', "Satış kaydedildi. Fatura: $fatura_no");
            header('Location: detay.php?id=' . $satis_id); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $hata = 'Hata: ' . $e->getMessage();
        }
        } // stokHatasi else sonu
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
</style>

<div class="page-header d-flex justify-content-between align-items-center">
    <h4><i class="bi bi-receipt text-primary"></i> Yeni Satış</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Satışlar</a>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger alert-dismissible"><i class="bi bi-exclamation-triangle"></i> <?= escH($hata) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="post" id="satisForm">
<?= csrfField() ?>
<div class="row g-3">

    <!-- ===== SOL PANEL ===== -->
    <div class="col-xl-3 col-lg-4">

        <!-- Müşteri -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-person text-primary"></i> Müşteri
            </div>
            <div class="card-body pb-2">
                <input type="hidden" name="musteri_id" id="musteriId" value="<?= $musteri_id ?>">
                <div class="position-relative">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="musteriArama" class="form-control border-start-0"
                               placeholder="Ad, telefon ile ara..."
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
                </div>
                <a href="<?= BASE_URL ?>/modules/musteriler/ekle.php" target="_blank"
                   class="small text-primary mt-1 d-block"><i class="bi bi-plus-circle"></i> Yeni müşteri ekle</a>
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
                            <option value="taksitli">📅 Taksitli</option>
                            <option value="karisik">🔀 Karışık</option>
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
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">Notlar</label>
                        <textarea name="notlar" class="form-control form-control-sm" rows="2" placeholder="İsteğe bağlı..."></textarea>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ===== SAĞ PANEL: Ürünler ===== -->
    <div class="col-xl-9 col-lg-8">
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
                                   placeholder="Barkod / Ürün kodu"
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
                    <hr class="border-white opacity-25 my-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold fs-5">TOPLAM</span>
                        <span class="fw-bold fs-3" id="genel-toplam">0,00 ₺</span>
                    </div>
                </div>
                <!-- Ödenen -->
                <div class="col-md-4">
                    <label class="small fw-semibold opacity-75 mb-1">Ödenen Tutar (₺)</label>
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
                    <div class="d-flex justify-content-between mt-2">
                        <span class="opacity-75">Kalan Borç</span>
                        <span class="fw-bold fs-5" id="kalan-tutar">0,00 ₺</span>
                    </div>
                </div>
                <!-- Kaydet -->
                <div class="col-md-4 d-flex align-items-center justify-content-end">
                    <button type="submit" class="btn btn-success btn-lg fw-bold shadow px-5 py-3" style="font-size:1.1rem">
                        <i class="bi bi-check-circle-fill me-2"></i>Satışı Kaydet
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
</form>

<script>
window._baseUrl = '<?= BASE_URL ?>';
// ── Ürün verisi ──────────────────────────────────────────────
const urunler = <?= json_encode(array_map(fn($u) => [
    'id'     => $u['id'],
    'kod'    => $u['kod'],
    'barkod' => $u['barkod'] ?? '',
    'ad'     => $u['ad'],
    'label'  => '[' . $u['kod'] . '] ' . $u['ad'],
    'fiyat'  => (float)$u['satis_fiyati'],
    'kdv'    => (float)$u['kdv_orani'],
    'stok'   => (int)$u['stok_adedi'],
], $urunler), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window._urunler = urunler; // main.js barkodEkle() için erişim

// ── Müşteri verisi ───────────────────────────────────────────
const musteriler = <?= json_encode(array_map(fn($m) => [
    'id'     => $m['id'],
    'ad'     => trim($m['ad'] . ' ' . $m['soyad']),
    'firma'  => $m['firma_adi'],
    'tel'    => $m['telefon'],
], $musteriler), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let kalemSayaci = 0;

// ── Yardımcı ─────────────────────────────────────────────────
function fmt(n) {
    return new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2}).format(n) + ' ₺';
}
function bosKalemGizle() {
    const el = document.getElementById('bosKalemMesaj');
    if (el) el.style.display = document.querySelectorAll('.kalem-row').length ? 'none' : '';
}

// ── Hesapla ──────────────────────────────────────────────────
function satisHesapla() {
    let ara = 0, kdvT = 0, indT = 0;
    document.querySelectorAll('.kalem-row').forEach(row => {
        const fiyat   = parseFloat(row.querySelector('.k-fiyat').value)   || 0;
        const miktar  = parseInt(row.querySelector('.k-miktar').value)    || 0;
        const kdv     = parseFloat(row.querySelector('.k-kdv').value)     || 0;
        const indirim = parseFloat(row.querySelector('.k-indirim').value) || 0;
        const satirAra = fiyat * miktar - indirim;
        const satirKdv = satirAra * kdv / 100;
        row.querySelector('.k-toplam').textContent = fmt(satirAra + satirKdv);
        ara  += satirAra;
        kdvT += satirKdv;
        indT += indirim;
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
    odenenHesapla();
}

function odenenHesapla() {
    const genel  = parseFloat(document.getElementById('genel-input').value) || 0;
    const odenen = parseFloat(document.getElementById('odenen-input').value) || 0;
    const kalan  = Math.max(0, genel - odenen);
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

// ── Kalem Satırı ─────────────────────────────────────────────
function kalemEkle(urun = null) {
    bosKalemGizle();
    const idx = kalemSayaci++;
    const row = document.createElement('tr');
    row.className = 'kalem-row';

    const options = urunler.map(u =>
        `<option value="${u.id}" data-fiyat="${u.fiyat}" data-kdv="${u.kdv}" data-stok="${u.stok}" data-kod="${u.kod}" data-barkod="${u.barkod}"
            ${urun && urun.id == u.id ? ' selected' : ''}>${u.label} (Stok: ${u.stok})</option>`
    ).join('');

    const fiyat   = urun ? urun.fiyat   : '';
    const kdv     = urun ? urun.kdv     : 20;
    const stok    = urun ? urun.stok    : null;
    const stokRenk = stok !== null ? (stok <= 0 ? 'danger' : stok <= 3 ? 'warning' : 'success') : '';
    const stokMsg  = stok !== null ? `<span class="badge bg-${stokRenk} kalem-stok-uyari ms-1">${stok} stok</span>` : '';

    row.innerHTML = `
        <td>
            <select name="kalem_urun[]" class="form-select form-select-sm k-urun" required>
                <option value="">Ürün seçin...</option>${options}
            </select>
            <div class="k-stok-info mt-1">${stokMsg}</div>
        </td>
        <td>
            <input type="number" name="kalem_miktar[]" class="form-control form-control-sm k-miktar text-center fw-bold"
                   min="1" value="${urun ? 1 : 1}" style="width:70px">
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
        const stok = parseInt(opt.dataset.stok ?? -1);
        const renk = stok <= 0 ? 'danger' : stok <= 3 ? 'warning' : 'success';
        row.querySelector('.k-stok-info').innerHTML = opt.value
            ? `<span class="badge bg-${renk} kalem-stok-uyari">Stok: ${stok}</span>` : '';
        satisHesapla();
    });

    document.getElementById('kalemBody').appendChild(row);
    bosKalemGizle();
    satisHesapla();
}

function kalemSil(btn) {
    btn.closest('tr').remove();
    bosKalemGizle();
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

// İndirim % ise gerçek ₺ değerini hesapla (form gönderiminde)
document.getElementById('satisForm').addEventListener('submit', function () {
    document.querySelectorAll('.kalem-row').forEach(row => {
        const btn = row.querySelector('.k-indirim-tip');
        if (btn.dataset.tip === 'yuzde') {
            const fiyat  = parseFloat(row.querySelector('.k-fiyat').value) || 0;
            const miktar = parseInt(row.querySelector('.k-miktar').value)  || 1;
            const yuzde  = parseFloat(row.querySelector('.k-indirim').value) || 0;
            row.querySelector('.k-indirim').value = ((yuzde / 100) * fiyat * miktar).toFixed(2);
        }
    });
});

// ── Barkod / Kod ile hızlı ekleme ────────────────────────────
function barkodEkle() {
    const val = document.getElementById('barkodInput').value.trim();
    if (!val) return;
    const u = urunler.find(u => u.barkod === val || u.kod === val || u.kod.toLowerCase() === val.toLowerCase());
    if (u) {
        // Aynı ürün zaten satırda varsa miktarı artır
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
        document.getElementById('barkodInput').value = '';
        document.getElementById('barkodInput').focus();
    } else {
        document.getElementById('barkodInput').classList.add('is-invalid');
        setTimeout(() => document.getElementById('barkodInput').classList.remove('is-invalid'), 1200);
    }
}

document.getElementById('barkodInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); barkodEkle(); }
});

// ── Müşteri Arama ────────────────────────────────────────────
const musteriInput  = document.getElementById('musteriArama');
const musteriDrop   = document.getElementById('musteriDropdown');
const musteriIdInp  = document.getElementById('musteriId');
const musteriTemizleBtn = document.getElementById('musteriTemizle');

<?php if ($musteri_id): ?>
const seciliM = musteriler.find(m => m.id == <?= $musteri_id ?>);
if (seciliM) musteriSec(seciliM);
<?php endif; ?>

musteriInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    musteriTemizleBtn.style.display = q ? '' : 'none';
    if (q.length < 1) { musteriDrop.style.display = 'none'; musteriIdInp.value = ''; return; }
    const sonuclar = musteriler.filter(m =>
        m.ad.toLowerCase().includes(q) || m.tel.includes(q) || m.firma.toLowerCase().includes(q)
    ).slice(0, 8);
    if (!sonuclar.length) { musteriDrop.style.display = 'none'; return; }
    musteriDrop.innerHTML = sonuclar.map(m =>
        `<div class="item" onclick="musteriSec(${JSON.stringify(m).replace(/"/g,'&quot;')})">
            <strong>${m.ad}</strong>${m.firma ? ' <span class="firma">(' + m.firma + ')</span>' : ''}
            <div class="firma">${m.tel || ''}</div>
        </div>`
    ).join('');
    musteriDrop.style.display = '';
});

function musteriSec(m) {
    musteriIdInp.value = m.id;
    musteriInput.value = m.ad + (m.firma ? ' (' + m.firma + ')' : '');
    musteriDrop.style.display = 'none';
    musteriTemizleBtn.style.display = '';
    document.getElementById('musteriSecili').classList.remove('d-none');
    document.getElementById('musteriAd').textContent = m.ad + (m.firma ? ' — ' + m.firma : '');
    document.getElementById('musteriTel').textContent = m.tel || '';
}

musteriTemizleBtn.addEventListener('click', () => {
    musteriInput.value = '';
    musteriIdInp.value = '';
    musteriTemizleBtn.style.display = 'none';
    musteriDrop.style.display = 'none';
    document.getElementById('musteriSecili').classList.add('d-none');
});

document.addEventListener('click', e => {
    if (!e.target.closest('#musteriArama') && !e.target.closest('#musteriDropdown'))
        musteriDrop.style.display = 'none';
});

// ── Event: input değişince hesapla ──────────────────────────
document.addEventListener('input', e => {
    if (e.target.closest('.kalem-row')) satisHesapla();
    if (e.target.id === 'odenen-input') odenenHesapla();
});

bosKalemGizle();

// ── Ödeme tipi / Taksit ──────────────────────────────────────
function odemeTipiDegisti() {
    const tip = document.getElementById('odemeTipi').value;
    const alan = document.getElementById('taksitAlani');
    alan.style.display = tip === 'taksitli' ? '' : 'none';
    if (tip === 'taksitli') taksitHesapla();
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
</script>

<!-- Barkod tarayıcı modülü -->
<script src="<?= BASE_URL ?>/assets/js/barcode-scanner.js"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
