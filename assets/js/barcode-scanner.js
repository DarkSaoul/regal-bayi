/**
 * Regal Bayi — Kamera Barkod Tarayıcı
 * html5-qrcode kütüphanesi kullanır
 */

const BarcodeScanner = (() => {
    let html5QrCode = null;
    let onDetectedCb = null;
    let modalEl = null;
    let modalBs = null;

    // Modal HTML'i oluştur (ilk çağrıda)
    function initModal() {
        if (document.getElementById('barcodeScannerModal')) return;

        document.body.insertAdjacentHTML('beforeend', `
        <div class="modal fade" id="barcodeScannerModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-dark text-white py-2">
                        <h6 class="modal-title mb-0">
                            <i class="bi bi-upc-scan me-2"></i>Barkod Tara
                        </h6>
                        <button type="button" class="btn-close btn-close-white"
                                id="scannerCloseBtn"></button>
                    </div>
                    <div class="modal-body p-2">
                        <!-- Kamera seçici -->
                        <div id="scannerCamSelect" class="mb-2" style="display:none">
                            <select id="cameraSelect" class="form-select form-select-sm">
                            </select>
                        </div>
                        <!-- Video alanı -->
                        <div class="scanner-overlay">
                            <div id="barcode-scanner-container"></div>
                            <div class="scanner-line" id="scannerLine" style="display:none"></div>
                        </div>
                        <!-- Manuel giriş -->
                        <div class="mt-2">
                            <div class="input-group">
                                <input type="text" id="manualBarcodeInput"
                                       class="form-control"
                                       placeholder="Barkod manuel girin...">
                                <button class="btn btn-primary" id="manualBarcodeBtn">
                                    <i class="bi bi-check2"></i>
                                </button>
                            </div>
                        </div>
                        <!-- Durum -->
                        <div id="scannerStatus" class="mt-2 small text-muted text-center"></div>
                    </div>
                </div>
            </div>
        </div>`);

        modalEl = document.getElementById('barcodeScannerModal');
        modalBs = new bootstrap.Modal(modalEl);

        document.getElementById('scannerCloseBtn').addEventListener('click', close);
        document.getElementById('manualBarcodeBtn').addEventListener('click', () => {
            const val = document.getElementById('manualBarcodeInput').value.trim();
            if (val) { close(); onDetectedCb && onDetectedCb(val); }
        });
        document.getElementById('manualBarcodeInput').addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = e.target.value.trim();
                if (val) { close(); onDetectedCb && onDetectedCb(val); }
            }
        });
        modalEl.addEventListener('hidden.bs.modal', stop);
    }

    function setStatus(msg) {
        const el = document.getElementById('scannerStatus');
        if (el) el.textContent = msg;
    }

    async function start(callback) {
        initModal();
        onDetectedCb = callback;
        // Modal önce açılır ki kütüphane yükleme başarısız olursa bile
        // kullanıcı sessiz bir "hiçbir şey olmadı" durumuyla karşılaşmasın.
        modalBs.show();

        // html5-qrcode kütüphanesi yüklü mü?
        if (typeof Html5Qrcode === 'undefined') {
            setStatus('Kütüphane yükleniyor...');
            try {
                await loadScript('https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js');
            } catch (e) {
                setStatus('Kütüphane yüklenemedi. İnternet bağlantınızı kontrol edip tekrar deneyin, veya barkodu manuel girin.');
                return;
            }
        }

        try {
            const cameras = await Html5Qrcode.getCameras();
            if (!cameras || cameras.length === 0) {
                setStatus('Kamera bulunamadı. Manuel giriş yapın.');
                return;
            }

            // Birden fazla kamera varsa seçici göster (ön/arka)
            if (cameras.length > 1) {
                const sel = document.getElementById('cameraSelect');
                sel.innerHTML = cameras.map((c, i) =>
                    `<option value="${c.id}">${c.label || 'Kamera ' + (i+1)}</option>`
                ).join('');
                // Arka kamerayı varsayılan yap
                const backIdx = cameras.findIndex(c =>
                    /back|rear|environment/i.test(c.label));
                if (backIdx > -1) sel.selectedIndex = backIdx;
                document.getElementById('scannerCamSelect').style.display = '';

                sel.addEventListener('change', () => {
                    stop(false);
                    startCamera(sel.value);
                });
                startCamera(sel.value);
            } else {
                startCamera(cameras[0].id);
            }
        } catch (err) {
            setStatus('Kamera erişim hatası: ' + err);
        }
    }

    function startCamera(cameraId) {
        if (html5QrCode) html5QrCode.clear();
        html5QrCode = new Html5Qrcode('barcode-scanner-container');
        document.getElementById('scannerLine').style.display = '';

        html5QrCode.start(
            cameraId,
            {
                fps: 15,
                qrbox: { width: 260, height: 120 },
                aspectRatio: 1.5,
                formatsToSupport: [
                    Html5QrcodeSupportedFormats?.EAN_13,
                    Html5QrcodeSupportedFormats?.EAN_8,
                    Html5QrcodeSupportedFormats?.UPC_A,
                    Html5QrcodeSupportedFormats?.UPC_E,
                    Html5QrcodeSupportedFormats?.CODE_128,
                    Html5QrcodeSupportedFormats?.CODE_39,
                    Html5QrcodeSupportedFormats?.QR_CODE,
                ].filter(Boolean)
            },
            (decodedText) => {
                // Tarandı — titreşim ver (mobilde)
                if (navigator.vibrate) navigator.vibrate(100);
                close();
                onDetectedCb && onDetectedCb(decodedText);
            },
            () => {} // hata sessizce geç
        ).catch(err => setStatus('Kamera başlatılamadı: ' + err));

        setStatus('Barkodu kameraya gösterin...');
    }

    function stop(hideBs = true) {
        document.getElementById('scannerLine') &&
            (document.getElementById('scannerLine').style.display = 'none');
        if (html5QrCode) {
            html5QrCode.stop().catch(() => {}).finally(() => { html5QrCode = null; });
        }
    }

    function close() {
        stop();
        if (modalBs) modalBs.hide();
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src; s.onload = resolve; s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    return { start, close };
})();
