-- Tedarikçi Ödemeleri tablosu — eksik migration düzeltmesi (2026-07-06)
-- Bu tablo daha önce canlı veritabanına manuel olarak eklenmiş ama bir migration
-- dosyasına yazılmamıştı; sıfırdan kurulumlarda (install.php) eksik kalıyordu.
CREATE TABLE IF NOT EXISTS tedarikci_odemeleri (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    tedarikci_id INT(11) NOT NULL,
    tarih        DATE NOT NULL,
    tutar        DECIMAL(12,2) NOT NULL,
    odeme_tipi   ENUM('nakit','kredi_karti','havale') DEFAULT 'nakit',
    aciklama     VARCHAR(255) NULL,
    kullanici_id INT(11) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_to_tedarikci (tedarikci_id),
    KEY idx_to_kullanici (kullanici_id),
    CONSTRAINT tedarikci_odemeleri_ibfk_1 FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE CASCADE,
    CONSTRAINT tedarikci_odemeleri_ibfk_2 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
