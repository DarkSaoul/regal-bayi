// ── Yardımcı ─────────────────────────────────────────────────
function paraFormat(sayi) {
    return new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(sayi) + ' ₺';
}

// ── Satış sayfası hesaplama ───────────────────────────────────
function satisHesapla() {
    let ara = 0, kdvT = 0, indT = 0;
    document.querySelectorAll('.kalem-row').forEach(row => {
        const fiyat   = parseFloat(row.querySelector('.k-fiyat')?.value)   || 0;
        const miktar  = parseInt(row.querySelector('.k-miktar')?.value)    || 0;
        const kdv     = parseFloat(row.querySelector('.k-kdv')?.value)     || 0;
        const indirim = parseFloat(row.querySelector('.k-indirim')?.value) || 0;
        const satirAra = fiyat * miktar - indirim;
        const satirKdv = satirAra * kdv / 100;
        const toplam   = satirAra + satirKdv;
        const el = row.querySelector('.k-toplam');
        if (el) el.textContent = paraFormat(toplam);
        ara  += satirAra;
        kdvT += satirKdv;
        indT += indirim;
    });
    const genel = ara + kdvT;
    const setEl = (id, val) => { const e = document.getElementById(id); if (e) e.textContent = val; };
    setEl('ara-toplam',   paraFormat(ara));
    setEl('kdv-toplam',   paraFormat(kdvT));
    setEl('genel-toplam', paraFormat(genel));
    const gi = document.getElementById('genel-input');
    if (gi) gi.value = genel.toFixed(2);
    const indSatir = document.getElementById('satirIndirimSatir');
    if (indSatir) {
        indSatir.style.setProperty('display', indT > 0 ? '' : 'none', 'important');
        const indEl = document.getElementById('satir-indirim');
        if (indEl) indEl.textContent = paraFormat(indT);
    }
    odenenHesapla();
}

function odenenHesapla() {
    const genel  = parseFloat(document.getElementById('genel-input')?.value)   || 0;
    const odenen = parseFloat(document.getElementById('odenen-input')?.value)  || 0;
    const kalan  = Math.max(0, genel - odenen);
    const el = document.getElementById('kalan-tutar');
    if (el) el.textContent = paraFormat(kalan);
}

function tamOdemeAl() {
    const genel = parseFloat(document.getElementById('genel-input')?.value) || 0;
    const inp = document.getElementById('odened-input') || document.getElementById('odenen-input');
    if (inp) { inp.value = genel.toFixed(2); odenenHesapla(); }
}

function hizliNakit(tutar) {
    const inp = document.getElementById('odenen-input');
    if (inp) {
        inp.value = (parseFloat(inp.value || 0) + tutar).toFixed(2);
        odenenHesapla();
    }
}

// ── Barkod/Kod ile ürün ekleme ────────────────────────────────
function barkodEkle() {
    const val = document.getElementById('barkodInput')?.value.trim();
    if (!val) return;
    const urunler = window._urunler || [];
    const u = urunler.find(u =>
        u.barkod === val ||
        u.kod === val ||
        u.kod.toLowerCase() === val.toLowerCase()
    );
    if (u) {
        let bulundu = false;
        document.querySelectorAll('.kalem-row').forEach(row => {
            const sel = row.querySelector('.k-urun');
            if (sel && sel.value == u.id) {
                const mk = row.querySelector('.k-miktar');
                if (mk) {
                    mk.value = parseInt(mk.value || 1) + 1;
                    mk.classList.add('bg-warning');
                    setTimeout(() => mk.classList.remove('bg-warning'), 600);
                }
                satisHesapla();
                bulundu = true;
            }
        });
        if (!bulundu && typeof kalemEkle === 'function') kalemEkle(u);
        const inp = document.getElementById('barkodInput');
        if (inp) { inp.value = ''; inp.focus(); }
    } else {
        const inp = document.getElementById('barkodInput');
        if (inp) {
            inp.classList.add('is-invalid');
            setTimeout(() => inp.classList.remove('is-invalid'), 1200);
        }
    }
}

// ── Kamera ile barkod tara ────────────────────────────────────
function kameraIleTara(hedef) {
    // hedef: 'satis' | 'stok'
    if (typeof BarcodeScanner === 'undefined') {
        const s = document.createElement('script');
        s.src = (window._baseUrl || '') + '/assets/js/barcode-scanner.js';
        s.onload = () => BarcodeScanner.start(kod => _barkodIsle(kod, hedef));
        document.head.appendChild(s);
    } else {
        BarcodeScanner.start(kod => _barkodIsle(kod, hedef));
    }
}

function _barkodIsle(kod, hedef) {
    if (hedef === 'satis') {
        const inp = document.getElementById('barkodInput');
        if (inp) { inp.value = kod; barkodEkle(); }
    } else if (hedef === 'stok') {
        const inp = document.getElementById('stokBarkodInput');
        if (inp) {
            inp.value = kod;
            // Ürünler arasında eşleştir
            const sel = document.getElementById('urunSec');
            if (sel && window._stokUrunler) {
                const u = window._stokUrunler.find(u => u.barkod === kod || u.kod === kod);
                if (u) {
                    sel.value = u.id;
                    sel.dispatchEvent(new Event('change'));
                    inp.value = '';
                }
            }
        }
    }
}

// ── İndirim tipi ₺/% değiştir ────────────────────────────────
function indirimTipDegistir(btn) {
    const row    = btn.closest('tr') || btn.closest('.kalem-row');
    const input  = row.querySelector('.k-indirim');
    const fiyat  = parseFloat(row.querySelector('.k-fiyat')?.value) || 0;
    const miktar = parseInt(row.querySelector('.k-miktar')?.value)  || 1;
    const eski   = parseFloat(input.value) || 0;

    if (btn.dataset.tip === 'tl') {
        const yuzde = fiyat * miktar > 0 ? (eski / (fiyat * miktar)) * 100 : 0;
        input.value = yuzde.toFixed(1);
        input.placeholder = '%';
        btn.dataset.tip = 'yuzde';
        btn.textContent = '%';
        btn.classList.add('btn-warning');
        btn.classList.remove('btn-outline-secondary');
    } else {
        const tl = (eski / 100) * fiyat * miktar;
        input.value = tl.toFixed(2);
        input.placeholder = '0';
        btn.dataset.tip = 'tl';
        btn.textContent = '₺';
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-outline-secondary');
    }
    satisHesapla();
}

// ── Taksit ────────────────────────────────────────────────────
function odemeTipiDegisti() {
    const tip  = document.getElementById('odemeTipi')?.value;
    const alan = document.getElementById('taksitAlani');
    if (alan) alan.style.display = tip === 'taksitli' ? '' : 'none';
    if (tip === 'taksitli') taksitHesapla();
}

function taksitHesapla() {
    const secili  = document.querySelector('input[name="taksit_sayisi"]:checked');
    if (!secili) return;
    const ozelAlan = document.getElementById('ozelTaksitAlan');
    let sayi;
    if (secili.value === 'ozel') {
        if (ozelAlan) ozelAlan.style.display = '';
        sayi = parseInt(document.getElementById('ozelTaksitSayisi')?.value) || 0;
    } else {
        if (ozelAlan) ozelAlan.style.display = 'none';
        sayi = parseInt(secili.value);
    }
    const genel  = parseFloat(document.getElementById('genel-input')?.value) || 0;
    const bilgi  = document.getElementById('taksitBilgi');
    if (bilgi) {
        if (sayi >= 2 && genel > 0) {
            bilgi.innerHTML = `<i class="bi bi-info-circle text-primary"></i>
                <strong>${sayi} taksit</strong> × <strong class="text-primary">${paraFormat(genel/sayi)}</strong>/ay`;
        } else if (sayi >= 2) {
            bilgi.innerHTML = `<i class="bi bi-info-circle text-muted"></i> Ürün ekleyince taksit tutarı görünecek`;
        } else {
            bilgi.innerHTML = '';
        }
    }
    // Buton stilleri
    document.querySelectorAll('input[name="taksit_sayisi"]').forEach(i => {
        const lbl = i.nextElementSibling;
        if (!lbl) return;
        if (i.checked) {
            lbl.classList.remove('btn-outline-primary', 'btn-outline-secondary');
            lbl.classList.add(i.value === 'ozel' ? 'btn-secondary' : 'btn-primary');
        } else {
            lbl.classList.remove('btn-primary', 'btn-secondary');
            lbl.classList.add(i.value === 'ozel' ? 'btn-outline-secondary' : 'btn-outline-primary');
        }
    });
}

// ── Genel event listener'lar ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Input değişince hesapla
    document.addEventListener('input', e => {
        if (e.target.closest('.kalem-row')) satisHesapla();
        if (e.target.id === 'odened-input' || e.target.id === 'odenen-input') odenenHesapla();
        if (e.target.id === 'ozelTaksitSayisi') taksitHesapla();
    });

    // Özel taksit input'una focus → radio seç
    document.getElementById('ozelTaksitSayisi')?.addEventListener('focus', () => {
        const r = document.getElementById('tOzel');
        if (r) r.checked = true;
        taksitHesapla();
    });

    // Barkod Enter tuşu
    document.getElementById('barkodInput')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); barkodEkle(); }
    });

    // Tooltip'ler
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el =>
        new bootstrap.Tooltip(el)
    );

    // Kalem tablosunu mobilde data-label ile işaretle
    _kalemMobilEtiketle();
});

function _kalemMobilEtiketle() {
    const table = document.getElementById('kalemTable');
    if (!table) return;
    const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(row => {
        [...row.querySelectorAll('td')].forEach((td, i) => {
            if (headers[i]) td.setAttribute('data-label', headers[i]);
        });
    });
}

// Boş kalem mesajı
function bosKalemGizle() {
    const el = document.getElementById('bosKalemMesaj');
    if (el) el.style.display = document.querySelectorAll('.kalem-row').length ? 'none' : '';
}
