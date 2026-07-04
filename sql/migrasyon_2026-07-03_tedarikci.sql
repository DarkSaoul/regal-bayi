-- Tedarikçi modülü genişletme — 2026-07-03
-- IBAN + vergi dairesi, manuel borç/açılış defteri, satın alma siparişleri

-- 1) Tedarikçiye IBAN + vergi dairesi
ALTER TABLE tedarikciler
    ADD COLUMN vergi_dairesi VARCHAR(100) NULL AFTER vergi_no,
    ADD COLUMN iban VARCHAR(34) NULL AFTER vergi_dairesi;

-- 2) Manuel borç / açılış bakiyesi / vadeli borç kalemleri
CREATE TABLE IF NOT EXISTS tedarikci_borclar (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    tedarikci_id INT(11) NOT NULL,
    tarih        DATE NOT NULL,
    tutar        DECIMAL(12,2) NOT NULL,
    aciklama     VARCHAR(255) NULL,
    vade_tarihi  DATE NULL,
    kullanici_id INT(11) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tb_tedarikci (tedarikci_id),
    KEY idx_tb_vade (vade_tarihi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 3) Satın alma siparişleri (başlık)
CREATE TABLE IF NOT EXISTS tedarikci_siparisleri (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    siparis_no     VARCHAR(30) NOT NULL,
    tedarikci_id   INT(11) NOT NULL,
    tarih          DATE NOT NULL,
    beklenen_tarih DATE NULL,
    durum          ENUM('bekliyor','teslim_alindi','iptal') NOT NULL DEFAULT 'bekliyor',
    toplam_tutar   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notlar         TEXT NULL,
    kullanici_id   INT(11) NULL,
    teslim_tarihi  DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_siparis_no (siparis_no),
    KEY idx_ts_tedarikci (tedarikci_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 4) Sipariş kalemleri
CREATE TABLE IF NOT EXISTS siparis_kalemleri (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    siparis_id  INT(11) NOT NULL,
    urun_id     INT(11) NOT NULL,
    miktar      INT(11) NOT NULL,
    birim_fiyat DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    KEY idx_sk_siparis (siparis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
