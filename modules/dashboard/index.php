<?php
define('BASE_URL', '/regal');
require_once __DIR__ . '/../../includes/functions.php';
auth();
$sayfa_basligi = 'Dashboard';
$pdo = db();

$bugun = date('Y-m-d');
$buAy  = date('Y-m');

// ── Türkçe tarih ──────────────────────────────────────────────
$tr_gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
$tr_aylar  = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$tr_tarih  = date('j') . ' ' . $tr_aylar[(int)date('n')-1] . ' ' . date('Y') . ', ' . $tr_gunler[(int)date('w')];

// ── Döviz kurları (TCMB — 1 saatlik cache) ───────────────────
function tcmbKurlari(): array {
    $cache = sys_get_temp_dir() . '/regal_tcmb.json';
    if (file_exists($cache) && (time() - filemtime($cache)) < 3600) {
        return json_decode(file_get_contents($cache), true) ?: [];
    }
    if (!function_exists('curl_init')) return [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://www.tcmb.gov.tr/kurlar/today.xml',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'RegalBayi/1.0',
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    if (!$data) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($data);
    if (!$xml) return [];
    $hedef = ['USD' => 'ABD Doları', 'EUR' => 'Euro', 'GBP' => 'İngiliz Sterlini', 'CHF' => 'İsviçre Frangı'];
    $kurlar = [];
    foreach ($xml->Currency as $c) {
        $kod = (string)($c->attributes()['CurrencyCode'] ?? '');
        if (!isset($hedef[$kod])) continue;
        $alis  = (float)str_replace(',', '.', (string)$c->ForexBuying);
        $satis = (float)str_replace(',', '.', (string)$c->ForexSelling);
        if ($alis > 0 && $satis > 0) {
            $kurlar[$kod] = ['alis' => $alis, 'satis' => $satis, 'isim' => $hedef[$kod]];
        }
    }
    if ($kurlar) file_put_contents($cache, json_encode($kurlar));
    return $kurlar;
}
$doviz = tcmbKurlari();
$dovizJson = json_encode($doviz);

// ── İstatistikler ─────────────────────────────────────────────
$s = $pdo->prepare("SELECT COALESCE(SUM(genel_toplam),0) FROM satislar WHERE tarih=? AND durum!='iptal'");
$s->execute([$bugun]); $gunlukSatis = $s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(genel_toplam),0) FROM satislar WHERE DATE_FORMAT(tarih,'%Y-%m')=? AND durum!='iptal'");
$s->execute([$buAy]); $aylikSatis = $s->fetchColumn();
$toplamMusteri = $pdo->query("SELECT COUNT(*) FROM musteriler")->fetchColumn();
$dusukStok     = $pdo->query("SELECT COUNT(*) FROM urunler WHERE stok_adedi <= min_stok AND aktif=1")->fetchColumn();
$bekleyenOdeme = $pdo->query("SELECT COALESCE(SUM(kalan_tutar),0) FROM satislar WHERE kalan_tutar>0 AND durum='bekliyor'")->fetchColumn();
$gecmisAy      = date('Y-m', strtotime('-1 month'));
$s = $pdo->prepare("SELECT COALESCE(SUM(genel_toplam),0) FROM satislar WHERE DATE_FORMAT(tarih,'%Y-%m')=? AND durum!='iptal'");
$s->execute([$gecmisAy]); $gecenAySatis = $s->fetchColumn();
$buyume      = $gecenAySatis > 0 ? round(($aylikSatis - $gecenAySatis) / $gecenAySatis * 100, 1) : null;
$gecmisKisat = gecikmisTaksitSayisi();

$sonSatislar = $pdo->query("
    SELECT s.*, CONCAT(m.ad,' ',COALESCE(m.soyad,'')) AS musteri_adi
    FROM satislar s LEFT JOIN musteriler m ON s.musteri_id=m.id
    ORDER BY s.created_at DESC LIMIT 8
")->fetchAll();

$grafik = $pdo->query("
    SELECT DATE_FORMAT(tarih,'%Y-%m') AS ay, SUM(genel_toplam) AS toplam
    FROM satislar WHERE durum!='iptal' AND tarih >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY ay ORDER BY ay
")->fetchAll();

$enCokSatan = $pdo->query("
    SELECT u.ad, SUM(sk.miktar) AS adet, SUM(sk.toplam) AS tutar
    FROM satis_kalemleri sk JOIN urunler u ON sk.urun_id=u.id JOIN satislar s ON sk.satis_id=s.id
    WHERE s.durum!='iptal' AND s.tarih >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY u.id ORDER BY adet DESC LIMIT 5
")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.hava-kart { min-width: 80px; text-align:center; padding: 8px 10px; border-radius:10px; background:#f8f9fa; border:1px solid #e9ecef; transition: background .2s; }
.hava-kart:hover { background:#e8f0fe; }
.hava-kart .hava-ikon { font-size: 1.6rem; line-height:1; }
.hava-kart .hava-gun { font-size:.7rem; color:#6c757d; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.hava-kart .hava-max { font-size:.95rem; font-weight:700; color:#212529; }
.hava-kart .hava-min { font-size:.75rem; color:#6c757d; }
.hava-kart.bugun { background:linear-gradient(135deg,#e8f4fd,#dbeafe); border-color:#93c5fd; }
.kur-kart { border-radius:10px; padding:10px 14px; }
.kur-kart .kur-kod { font-size:1rem; font-weight:700; }
.kur-kart .kur-alis { font-size:.8rem; color:#198754; }
.kur-kart .kur-satis { font-size:.8rem; color:#dc3545; }
.kur-kart .kur-deger { font-size:1.1rem; font-weight:700; }
</style>

<!-- ── Sayfa Başlığı ─────────────────────────────────────────── -->
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h4><i class="bi bi-speedometer2 text-primary"></i> Dashboard</h4>
        <small class="text-muted"><i class="bi bi-calendar3 me-1"></i><?= $tr_tarih ?></small>
    </div>
    <a href="<?= BASE_URL ?>/modules/satislar/yeni.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Yeni Satış
    </a>
</div>

<!-- ── Hava Durumu + Döviz Satırı ───────────────────────────── -->
<div class="row g-3 mb-3">

    <!-- Hava Durumu -->
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-semibold small">
                        <i class="bi bi-cloud-sun text-warning me-1"></i>
                        5 Günlük Hava Durumu
                    </span>
                    <span class="small text-muted" id="havaSehir">
                        <i class="bi bi-geo-alt"></i> Konum alınıyor...
                    </span>
                </div>
                <div id="havaDurumuWidget" class="d-flex gap-2 flex-wrap">
                    <div class="text-muted small py-2">
                        <span class="spinner-border spinner-border-sm me-1"></span> Yükleniyor...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Döviz Kurları -->
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-semibold small">
                        <i class="bi bi-currency-exchange text-success me-1"></i>
                        Döviz Kurları
                        <small class="text-muted fw-normal">(TCMB — Bugün)</small>
                    </span>
                </div>
                <?php if (empty($doviz)): ?>
                <div class="text-muted small py-2">
                    <i class="bi bi-wifi-off me-1"></i> Kur verisi alınamadı.
                </div>
                <?php else: ?>
                <div class="row g-2">
                    <?php
                    $kurIkonlar = ['USD'=>'💵','EUR'=>'💶','GBP'=>'💷','CHF'=>'🇨🇭'];
                    foreach ($doviz as $kod => $kur): ?>
                    <div class="col-6">
                        <div class="kur-kart bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="kur-kod"><?= $kurIkonlar[$kod] ?? '💱' ?> <?= $kod ?></span>
                                <span class="kur-deger text-primary"><?= number_format($kur['satis'], 4, ',', '.') ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="kur-alis">Alış: <?= number_format($kur['alis'], 4, ',', '.') ?></span>
                                <span class="kur-satis">Satış: <?= number_format($kur['satis'], 4, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Döviz Çevirici ────────────────────────────────────────── -->
<?php if (!empty($doviz)): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body py-2 px-3">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <span class="fw-semibold small"><i class="bi bi-arrow-left-right text-primary me-1"></i>Döviz Çevirici</span>
            </div>
            <div class="col-md-2">
                <input type="number" id="ceviriMiktar" class="form-control form-control-sm"
                       value="1" min="0" step="any" placeholder="Miktar">
            </div>
            <div class="col-md-2">
                <select id="ceviriKod" class="form-select form-select-sm">
                    <?php foreach ($doviz as $kod => $kur): ?>
                    <option value="<?= $kod ?>"><?= $kod ?> — <?= $kur['isim'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary" onclick="ceviriYon()" id="ceviriYonBtn" title="Yönü değiştir">
                    <i class="bi bi-arrow-left-right"></i>
                </button>
            </div>
            <div class="col-auto">
                <span class="fw-semibold small text-muted" id="ceviriYonLabel">→ Türk Lirası</span>
            </div>
            <div class="col-md-3">
                <div class="input-group input-group-sm">
                    <input type="text" id="ceviriSonuc" class="form-control fw-bold text-primary bg-light" readonly>
                    <span class="input-group-text" id="ceviriSonucBirim">₺</span>
                </div>
            </div>
            <div class="col-auto text-muted" style="font-size:.75rem" id="ceviriKurBilgi"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── İstatistik Kartları ───────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-cart-check"></i></div>
                <div>
                    <div class="text-muted small">Bugün Satış</div>
                    <div class="fw-bold fs-5"><?= para($gunlukSatis) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-graph-up-arrow"></i></div>
                <div>
                    <div class="text-muted small">Aylık Satış</div>
                    <div class="fw-bold fs-5"><?= para($aylikSatis) ?></div>
                    <?php if ($buyume !== null): ?>
                    <div class="small <?= $buyume>=0?'text-success':'text-danger' ?>">
                        <?= $buyume>=0?'↑':'↓' ?> %<?= abs($buyume) ?> geçen aya göre
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="text-muted small">Bekleyen Tahsilat</div>
                    <div class="fw-bold fs-5 text-warning"><?= para($bekleyenOdeme) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-people"></i></div>
                <div>
                    <div class="text-muted small">Toplam Müşteri</div>
                    <div class="fw-bold fs-5"><?= number_format($toplamMusteri) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Uyarılar ─────────────────────────────────────────────── -->
<?php if ($dusukStok > 0 || $gecmisKisat > 0): ?>
<div class="row g-3 mb-3">
    <?php if ($dusukStok > 0): ?>
    <div class="col-md-6">
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-0">
            <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
            <span><strong><?= $dusukStok ?> ürün</strong> kritik stok seviyesinde.</span>
            <a href="<?= BASE_URL ?>/modules/stok/dusuk.php" class="btn btn-sm btn-danger ms-auto">Görüntüle</a>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($gecmisKisat > 0): ?>
    <div class="col-md-6">
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-0">
            <i class="bi bi-calendar-x fs-5 flex-shrink-0"></i>
            <span><strong><?= $gecmisKisat ?> taksit</strong> vadesi geçmiş.</span>
            <a href="<?= BASE_URL ?>/modules/finans/taksit_takvimi.php?filtre=gecmis" class="btn btn-sm btn-danger ms-auto">Görüntüle</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Grafik + En Çok Satan ────────────────────────────────── -->
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart text-primary"></i> Aylık Satış (Son 6 Ay)
            </div>
            <div class="card-body">
                <canvas id="satisGrafik" height="110"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-trophy text-warning"></i> En Çok Satan (30 Gün)
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                <?php if (empty($enCokSatan)): ?>
                    <li class="list-group-item text-muted text-center py-3">Henüz satış yok</li>
                <?php else: ?>
                    <?php foreach ($enCokSatan as $u): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-truncate me-2" style="max-width:150px"><?= escH($u['ad']) ?></span>
                        <div class="text-end">
                            <span class="badge bg-primary"><?= $u['adet'] ?> adet</span>
                            <div class="small text-muted"><?= para($u['tutar']) ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ── Son Satışlar ──────────────────────────────────────────── -->
<div class="card shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-clock-history text-primary"></i> Son Satışlar</span>
        <a href="<?= BASE_URL ?>/modules/satislar/" class="btn btn-sm btn-outline-primary">Tümü</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Fatura No</th><th>Müşteri</th><th>Tarih</th>
                <th>Tutar</th><th>Ödeme</th><th>Durum</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($sonSatislar)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">Henüz satış kaydı yok</td></tr>
            <?php else: ?>
                <?php foreach ($sonSatislar as $s): ?>
                <tr>
                    <td><strong><?= escH($s['fatura_no']) ?></strong></td>
                    <td><?= escH($s['musteri_adi'] ?: 'Perakende') ?></td>
                    <td><?= tarih($s['tarih']) ?></td>
                    <td><?= para($s['genel_toplam']) ?></td>
                    <td><?= ucfirst(str_replace('_',' ',$s['odeme_tipi'])) ?></td>
                    <td>
                        <?php
                        $renk  = $s['durum']==='tamamlandi' ? 'success' : ($s['durum']==='iptal' ? 'danger' : 'warning');
                        $label = $s['durum']==='tamamlandi' ? 'Tamamlandı' : ($s['durum']==='iptal' ? 'İptal' : 'Bekliyor');
                        ?>
                        <span class="badge bg-<?= $renk ?>"><?= $label ?></span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/satislar/detay.php?id=<?= $s['id'] ?>"
                           class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
// ── Satış Grafiği ─────────────────────────────────────────────
new Chart(document.getElementById('satisGrafik'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($grafik,'ay')) ?>,
        datasets: [{
            label: 'Satış (₺)',
            data: <?= json_encode(array_map('floatval', array_column($grafik,'toplam'))) ?>,
            backgroundColor: 'rgba(13,110,253,.7)',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// ── Hava Durumu ───────────────────────────────────────────────
const TR_GUNLER = ['Paz','Pzt','Sal','Çar','Per','Cum','Cmt'];
const TR_AYLAR  = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];

function weatherIcon(code) {
    if (code === 0)                    return ['bi-sun','text-warning'];
    if (code <= 2)                     return ['bi-cloud-sun','text-warning'];
    if (code === 3)                    return ['bi-cloud','text-secondary'];
    if (code <= 48)                    return ['bi-cloud-fog','text-secondary'];
    if (code <= 57)                    return ['bi-cloud-drizzle','text-info'];
    if (code <= 67)                    return ['bi-cloud-rain','text-primary'];
    if (code <= 77)                    return ['bi-cloud-snow','text-info'];
    if (code <= 82)                    return ['bi-cloud-rain-heavy','text-primary'];
    if (code <= 86)                    return ['bi-cloud-snow','text-info'];
    return ['bi-cloud-lightning-rain','text-danger'];
}

function weatherDesc(code) {
    if (code === 0) return 'Açık';
    if (code <= 2)  return 'Az Bulutlu';
    if (code === 3) return 'Bulutlu';
    if (code <= 48) return 'Sisli';
    if (code <= 57) return 'Çiseleyen';
    if (code <= 67) return 'Yağmurlu';
    if (code <= 77) return 'Karlı';
    if (code <= 82) return 'Sağanak';
    if (code <= 86) return 'Kar Yağışlı';
    return 'Fırtınalı';
}

function renderHava(data, sehir) {
    document.getElementById('havaSehir').innerHTML =
        `<i class="bi bi-geo-alt-fill text-danger"></i> ${sehir}`;
    const widget = document.getElementById('havaDurumuWidget');
    const bugunIdx = 0;
    widget.innerHTML = data.daily.time.map((tarih, i) => {
        const d    = new Date(tarih + 'T12:00:00');
        const gun  = i === 0 ? 'Bugün' : TR_GUNLER[d.getDay()];
        const gun2 = `${d.getDate()} ${TR_AYLAR[d.getMonth()]}`;
        const code = data.daily.weathercode[i];
        const [ikon, renk] = weatherIcon(code);
        const max  = Math.round(data.daily.temperature_2m_max[i]);
        const min  = Math.round(data.daily.temperature_2m_min[i]);
        return `<div class="hava-kart flex-fill ${i===0?'bugun':''}" title="${weatherDesc(code)}">
            <div class="hava-gun">${gun}</div>
            <div class="small text-muted" style="font-size:.65rem">${gun2}</div>
            <div class="hava-ikon my-1"><i class="bi ${ikon} ${renk}"></i></div>
            <div class="hava-max">${max}°</div>
            <div class="hava-min">${min}°</div>
        </div>`;
    }).join('');
}

async function fetchWeather(lat, lon, sehir) {
    try {
        const url = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&daily=weathercode,temperature_2m_max,temperature_2m_min&timezone=Europe%2FIstanbul&forecast_days=5`;
        const res  = await fetch(url);
        const data = await res.json();
        renderHava(data, sehir);
    } catch(e) {
        document.getElementById('havaDurumuWidget').innerHTML =
            '<span class="text-muted small"><i class="bi bi-wifi-off me-1"></i>Hava durumu alınamadı.</span>';
    }
}

async function getSehirAdi(lat, lon) {
    try {
        const res  = await fetch(`https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=${lat}&longitude=${lon}&localityLanguage=tr`);
        const data = await res.json();
        return data.city || data.locality || data.countryName || 'Bilinmiyor';
    } catch(e) { return 'Bilinmiyor'; }
}

// Önce tarayıcı konumu, olmazsa IP konumu
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        async pos => {
            const { latitude: lat, longitude: lon } = pos.coords;
            const sehir = await getSehirAdi(lat, lon);
            fetchWeather(lat, lon, sehir);
        },
        async () => {
            // Konum reddedildi — IP bazlı konum
            try {
                const res  = await fetch('https://ipapi.co/json/');
                const data = await res.json();
                fetchWeather(data.latitude, data.longitude, data.city || 'Bilinmiyor');
            } catch(e) {
                // IP de başarısız — Ankara varsayılan
                fetchWeather(39.9208, 32.8541, 'Ankara');
            }
        },
        { timeout: 5000 }
    );
} else {
    fetchWeather(39.9208, 32.8541, 'Ankara');
}

// ── Döviz Çevirici ───────────────────────────────────────────
const dovizKurlar = <?= $dovizJson ?>;
let ceviriTlMi = true; // true: döviz → TL, false: TL → döviz

function ceviriHesapla() {
    const miktar = parseFloat(document.getElementById('ceviriMiktar').value) || 0;
    const kod    = document.getElementById('ceviriKod').value;
    const kur    = dovizKurlar[kod];
    if (!kur) return;

    const sonucEl    = document.getElementById('ceviriSonuc');
    const birimEl    = document.getElementById('ceviriSonucBirim');
    const kurBilgiEl = document.getElementById('ceviriKurBilgi');

    if (ceviriTlMi) {
        // döviz → TL (satış kuru kullanılır)
        const sonuc = miktar * kur.satis;
        sonucEl.value = sonuc.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
        birimEl.textContent = '₺';
        kurBilgiEl.textContent = `1 ${kod} = ${kur.satis.toLocaleString('tr-TR',{minimumFractionDigits:4})} ₺ (TCMB Satış)`;
    } else {
        // TL → döviz (alış kuru kullanılır)
        const sonuc = miktar / kur.alis;
        sonucEl.value = sonuc.toLocaleString('tr-TR', {minimumFractionDigits:4, maximumFractionDigits:4});
        birimEl.textContent = kod;
        kurBilgiEl.textContent = `1 ₺ = ${(1/kur.alis).toLocaleString('tr-TR',{minimumFractionDigits:6})} ${kod} (TCMB Alış)`;
    }
}

function ceviriYon() {
    ceviriTlMi = !ceviriTlMi;
    const label  = document.getElementById('ceviriYonLabel');
    const mikInp = document.getElementById('ceviriMiktar');
    if (ceviriTlMi) {
        label.textContent   = '→ Türk Lirası';
        mikInp.placeholder  = 'Döviz miktarı';
    } else {
        label.textContent   = '→ Döviz';
        mikInp.placeholder  = 'TL miktarı';
    }
    ceviriHesapla();
}

document.getElementById('ceviriMiktar')?.addEventListener('input', ceviriHesapla);
document.getElementById('ceviriKod')?.addEventListener('change', ceviriHesapla);
if (Object.keys(dovizKurlar).length) ceviriHesapla();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
