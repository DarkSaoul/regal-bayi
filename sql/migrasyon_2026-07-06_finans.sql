-- Kasa & Finans Modülü Büyük Genişletme — 2026-07-06
-- Uygulamadan önce yedek alın: backups/regal_bayi_finans_oncesi_2026-07-06.sql

-- 1) kasa_hareketleri: hesap ayrımı (kasa/banka), gider belgesi, onay akışı
ALTER TABLE kasa_hareketleri
    ADD COLUMN hesap ENUM('kasa','banka') NOT NULL DEFAULT 'kasa' AFTER tip,
    ADD COLUMN belge VARCHAR(255) NULL AFTER kategori,
    ADD COLUMN onay_durumu ENUM('onaylandi','bekliyor','reddedildi') NOT NULL DEFAULT 'onaylandi' AFTER belge,
    ADD COLUMN onaylayan_id INT NULL AFTER onay_durumu,
    ADD KEY idx_hesap (hesap),
    ADD KEY idx_onay (onay_durumu),
    ADD CONSTRAINT kasa_hareketleri_fk_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES kullanicilar(id) ON DELETE SET NULL;

-- 2) Kasa kategorileri — artık koda gömülü değil, yönetilebilir liste
CREATE TABLE kasa_kategoriler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(100) NOT NULL UNIQUE,
    tip ENUM('giris','cikis','ikisi') NOT NULL DEFAULT 'cikis',
    aylik_limit DECIMAL(12,2) NULL,
    sistem TINYINT(1) NOT NULL DEFAULT 0,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    sira INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- sistem=1: yalnızca Satış/Tahsilat (kasa_sil.php'nin koruduğu, elle seçilemeyen kategoriler).
-- İade ve Diğer bilinçli olarak elle seçilebilir/silinebilir bırakıldı (orijinal davranış).
INSERT INTO kasa_kategoriler (ad, tip, sistem, sira) VALUES
    ('Satış', 'giris', 1, 1),
    ('Tahsilat', 'giris', 1, 2),
    ('İade', 'cikis', 0, 3),
    ('Kira', 'cikis', 0, 10),
    ('Elektrik/Su', 'cikis', 0, 11),
    ('Personel', 'cikis', 0, 12),
    ('Tedarikçi Ödemesi', 'cikis', 0, 13),
    ('Diğer', 'ikisi', 0, 99);

-- 3) Tekrarlayan gider şablonları
CREATE TABLE gider_sablonlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    kategori_id INT NOT NULL,
    tutar DECIMAL(12,2) NOT NULL,
    periyot ENUM('aylik','haftalik') NOT NULL DEFAULT 'aylik',
    gun INT NULL COMMENT 'aylık: ayın kaçıncı günü (1-28), haftalık: haftanın kaçıncı günü (1-7, 1=Pazartesi)',
    son_olusturma DATE NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    kullanici_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY kategori_id (kategori_id),
    CONSTRAINT gider_sablonlari_fk1 FOREIGN KEY (kategori_id) REFERENCES kasa_kategoriler(id),
    CONSTRAINT gider_sablonlari_fk2 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 4) Vardiya (kasa devri + fiziksel sayım)
CREATE TABLE kasa_vardiyalari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kullanici_id INT NOT NULL,
    devir_kullanici_id INT NULL,
    baslangic DATETIME NOT NULL,
    bitis DATETIME NULL,
    acilis_tutari DECIMAL(12,2) NOT NULL DEFAULT 0,
    sistem_bakiye DECIMAL(12,2) NULL,
    fiili_tutar DECIMAL(12,2) NULL,
    fark DECIMAL(12,2) NULL,
    sayim_detay TEXT NULL,
    notlar VARCHAR(255) NULL,
    durum ENUM('acik','kapali') NOT NULL DEFAULT 'acik',
    KEY kullanici_id (kullanici_id),
    CONSTRAINT kasa_vardiyalari_fk1 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id),
    CONSTRAINT kasa_vardiyalari_fk2 FOREIGN KEY (devir_kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 5) Yeni ayarlar
INSERT INTO ayarlar (anahtar, deger, grup, aciklama) VALUES
    ('kasa_min_bakiye_uyari', '0', 'finans', 'Kasa bakiyesi bu tutarın altına düşünce uyarı gösterilir (0 = kapalı)'),
    ('gider_onay_limiti', '0', 'finans', 'Kasiyerin onaysız girebileceği maksimum tek seferlik gider tutarı (0 = sınırsız, onay gerekmez)')
ON DUPLICATE KEY UPDATE deger=VALUES(deger);
