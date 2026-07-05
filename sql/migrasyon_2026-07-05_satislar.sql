-- Satışlar Modülü Büyük Genişletme — 2026-07-05
-- Uygulamadan önce yedek alın: backups/regal_bayi_satislar_oncesi_2026-07-05.sql

-- 1) satislar: satış tipi (normal / ön sipariş / değişim), bölünmüş ödeme,
--    teslimat & montaj takibi, kısmi iade toplamı, değişim bağlantısı
ALTER TABLE satislar
    MODIFY odeme_tipi ENUM('nakit','kredi_karti','havale','taksitli','karisik','bolunmus') DEFAULT 'nakit',
    ADD COLUMN tip ENUM('satis','on_siparis') NOT NULL DEFAULT 'satis' AFTER fatura_no,
    ADD COLUMN stok_dusuldu TINYINT(1) NOT NULL DEFAULT 1 AFTER tip,
    ADD COLUMN teslimat_tarihi DATE NULL AFTER notlar,
    ADD COLUMN teslimat_adresi TEXT NULL AFTER teslimat_tarihi,
    ADD COLUMN teslimat_durum ENUM('yok','hazirlaniyor','yolda','teslim_edildi') NOT NULL DEFAULT 'yok' AFTER teslimat_adresi,
    ADD COLUMN montaj_tarihi DATE NULL AFTER teslimat_durum,
    ADD COLUMN montaj_notu VARCHAR(255) NULL AFTER montaj_tarihi,
    ADD COLUMN iade_toplam DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER kalan_tutar,
    ADD COLUMN degisim_satis_id INT NULL AFTER iade_toplam,
    ADD KEY idx_teslimat (teslimat_durum, teslimat_tarihi),
    ADD KEY idx_tip (tip);

-- 2) satis_kalemleri: satış anı maliyet snapshot'ı (kârlılık raporu) + iade edilen miktar
ALTER TABLE satis_kalemleri
    ADD COLUMN birim_maliyet DECIMAL(10,2) NULL AFTER birim_fiyat,
    ADD COLUMN iade_miktar INT NOT NULL DEFAULT 0 AFTER miktar;

-- 3) Park edilen (askıya alınan) sepetler
CREATE TABLE IF NOT EXISTS park_sepetler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kullanici_id INT NOT NULL,
    ad VARCHAR(100) NOT NULL DEFAULT '',
    veri LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY kullanici_id (kullanici_id),
    CONSTRAINT park_sepetler_fk1 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 4) Kısmi iadeler
CREATE TABLE IF NOT EXISTS satis_iadeleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    satis_id INT NOT NULL,
    tarih DATE NOT NULL,
    tutar DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- iade edilen toplam (KDV dahil)
    nakit_iade DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- kasadan fiilen çıkan
    borc_dusum DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- kalan borçtan düşülen
    aciklama VARCHAR(255) NULL,
    kullanici_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY satis_id (satis_id),
    CONSTRAINT satis_iadeleri_fk1 FOREIGN KEY (satis_id) REFERENCES satislar(id) ON DELETE CASCADE,
    CONSTRAINT satis_iadeleri_fk2 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS satis_iade_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    iade_id INT NOT NULL,
    satis_kalem_id INT NOT NULL,
    urun_id INT NOT NULL,
    miktar INT NOT NULL,
    tutar DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- bu kaleme düşen iade tutarı (KDV dahil)
    urun_durum ENUM('saglam','hasarli','tesir') NOT NULL DEFAULT 'saglam',
    KEY iade_id (iade_id),
    CONSTRAINT sik_fk1 FOREIGN KEY (iade_id) REFERENCES satis_iadeleri(id) ON DELETE CASCADE,
    CONSTRAINT sik_fk2 FOREIGN KEY (satis_kalem_id) REFERENCES satis_kalemleri(id) ON DELETE CASCADE,
    CONSTRAINT sik_fk3 FOREIGN KEY (urun_id) REFERENCES urunler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 5) Müşteri risk limiti (0 = limitsiz)
ALTER TABLE musteriler
    ADD COLUMN risk_limiti DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER toplam_borc;
