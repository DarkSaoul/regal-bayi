-- Regal Bayi Yönetim Sistemi - Veritabanı Şeması
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS regal_bayi CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;
USE regal_bayi;

-- Kullanıcılar
CREATE TABLE kullanicilar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kullanici_adi VARCHAR(50) UNIQUE NOT NULL,
    sifre VARCHAR(255) NOT NULL,
    ad_soyad VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    rol ENUM('yonetici','kasiyer','depo') DEFAULT 'kasiyer',
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Kategoriler
CREATE TABLE kategoriler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(100) NOT NULL,
    ust_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ust_id) REFERENCES kategoriler(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tedarikçiler
CREATE TABLE tedarikciler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    yetkili VARCHAR(100),
    telefon VARCHAR(20),
    email VARCHAR(100),
    adres TEXT,
    vergi_no VARCHAR(20),
    notlar TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Ürünler
CREATE TABLE urunler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(50) UNIQUE NOT NULL,
    barkod VARCHAR(50),
    ad VARCHAR(200) NOT NULL,
    kategori_id INT,
    marka VARCHAR(100) DEFAULT 'Regal',
    model VARCHAR(100),
    renk VARCHAR(50),
    aciklama TEXT,
    alis_fiyati DECIMAL(10,2) DEFAULT 0,
    satis_fiyati DECIMAL(10,2) DEFAULT 0,
    kdv_orani DECIMAL(5,2) DEFAULT 20,
    garanti_suresi INT DEFAULT 24 COMMENT 'ay cinsinden',
    stok_adedi INT DEFAULT 0,
    tesir_adedi INT DEFAULT 0,
    min_stok INT DEFAULT 1,
    seri_no_takip TINYINT(1) DEFAULT 0,
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kategori_id) REFERENCES kategoriler(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Seri Numaraları
CREATE TABLE seri_numaralari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_id INT NOT NULL,
    seri_no VARCHAR(100) NOT NULL,
    durum ENUM('stokta','satildi','ariza','iade','tesirde') DEFAULT 'stokta',
    satis_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (urun_id) REFERENCES urunler(id)
) ENGINE=InnoDB;

-- Stok Hareketleri
CREATE TABLE stok_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_id INT NOT NULL,
    hareket_tipi ENUM('giris','cikis','iade_giris','fire') NOT NULL,
    miktar INT NOT NULL,
    onceki_stok INT DEFAULT 0,
    sonraki_stok INT DEFAULT 0,
    belge_no VARCHAR(50),
    aciklama VARCHAR(255),
    tedarikci_id INT DEFAULT NULL,
    kullanici_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (urun_id) REFERENCES urunler(id),
    FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Müşteriler
CREATE TABLE musteriler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tip ENUM('bireysel','kurumsal') DEFAULT 'bireysel',
    ad VARCHAR(100) NOT NULL,
    soyad VARCHAR(100),
    firma_adi VARCHAR(150),
    tc_no VARCHAR(11),
    vergi_no VARCHAR(11),
    telefon VARCHAR(20),
    telefon2 VARCHAR(20),
    email VARCHAR(100),
    adres TEXT,
    sehir VARCHAR(50),
    notlar TEXT,
    toplam_borc DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Satışlar
CREATE TABLE satislar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fatura_no VARCHAR(30) UNIQUE NOT NULL,
    musteri_id INT,
    kullanici_id INT,
    tarih DATE NOT NULL,
    ara_toplam DECIMAL(12,2) DEFAULT 0,
    kdv_toplam DECIMAL(12,2) DEFAULT 0,
    indirim_toplam DECIMAL(12,2) DEFAULT 0,
    genel_toplam DECIMAL(12,2) DEFAULT 0,
    odeme_tipi ENUM('nakit','kredi_karti','havale','taksitli','karisik') DEFAULT 'nakit',
    taksit_sayisi INT DEFAULT 1,
    odenen_tutar DECIMAL(12,2) DEFAULT 0,
    kalan_tutar DECIMAL(12,2) DEFAULT 0,
    durum ENUM('tamamlandi','bekliyor','iptal') DEFAULT 'tamamlandi',
    notlar TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Satış Kalemleri
CREATE TABLE satis_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    satis_id INT NOT NULL,
    urun_id INT NOT NULL,
    seri_no_id INT DEFAULT NULL,
    miktar INT DEFAULT 1,
    birim_fiyat DECIMAL(10,2) NOT NULL,
    kdv_orani DECIMAL(5,2) DEFAULT 20,
    kdv_tutar DECIMAL(10,2) DEFAULT 0,
    indirim DECIMAL(10,2) DEFAULT 0,
    toplam DECIMAL(10,2) NOT NULL,
    tesir_satis TINYINT(1) DEFAULT 0,
    FOREIGN KEY (satis_id) REFERENCES satislar(id) ON DELETE CASCADE,
    FOREIGN KEY (urun_id) REFERENCES urunler(id),
    FOREIGN KEY (seri_no_id) REFERENCES seri_numaralari(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Ödemeler
CREATE TABLE odemeler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    satis_id INT,
    musteri_id INT,
    tarih DATE NOT NULL,
    tutar DECIMAL(12,2) NOT NULL,
    odeme_tipi ENUM('nakit','kredi_karti','havale','eft') DEFAULT 'nakit',
    taksit_no INT DEFAULT NULL,
    aciklama VARCHAR(255),
    kullanici_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (satis_id) REFERENCES satislar(id) ON DELETE SET NULL,
    FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Taksit Planı
CREATE TABLE taksit_plani (
    id INT AUTO_INCREMENT PRIMARY KEY,
    satis_id INT NOT NULL,
    taksit_no INT NOT NULL,
    tutar DECIMAL(10,2) NOT NULL,
    vade_tarihi DATE NOT NULL,
    odendi TINYINT(1) DEFAULT 0,
    odeme_tarihi DATE DEFAULT NULL,
    odeme_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (satis_id) REFERENCES satislar(id) ON DELETE CASCADE,
    FOREIGN KEY (odeme_id) REFERENCES odemeler(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Kasa Hareketleri
CREATE TABLE kasa_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tarih DATE NOT NULL,
    tip ENUM('giris','cikis') NOT NULL,
    tutar DECIMAL(12,2) NOT NULL,
    aciklama VARCHAR(255),
    kategori VARCHAR(100),
    odeme_id INT DEFAULT NULL,
    kullanici_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (odeme_id) REFERENCES odemeler(id) ON DELETE SET NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Aktivite Logları (brute-force koruması da bu tabloyu kullanır)
CREATE TABLE aktivite_loglari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kullanici_id INT DEFAULT NULL,
    aksiyon VARCHAR(100) NOT NULL,
    modul VARCHAR(50) DEFAULT NULL,
    hedef_id INT DEFAULT NULL,
    detay VARCHAR(500) DEFAULT NULL,
    ip_adresi VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at),
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ===== BAŞLANGIÇ VERİLERİ =====

-- Yönetici kullanıcı (şifre: admin123)
INSERT INTO kullanicilar (kullanici_adi, sifre, ad_soyad, email, rol) VALUES
('admin', '$2y$10$PbD75BQJr8qlAW7FnQZuXOBQhEyoeEVwjN3UXVdN1Ksl0xg0Olaqu', 'Sistem Yöneticisi', 'admin@regalbayi.com', 'yonetici');

-- Kategoriler
INSERT INTO kategoriler (id, ad, ust_id) VALUES
(1, 'Büyük Beyaz Eşya', NULL),
(2, 'Küçük Ev Aletleri', NULL),
(3, 'Buzdolabı', 1),
(4, 'Çamaşır Makinesi', 1),
(5, 'Bulaşık Makinesi', 1),
(6, 'Fırın & Ocak', 1),
(7, 'Klima', 1),
(8, 'Kettle & Çaydanlık', 2),
(9, 'Tost Makinesi & Izgara', 2),
(10, 'Blender & Mutfak Robotu', 2),
(11, 'Süpürge', 2),
(12, 'Ütü', 2);

-- Örnek tedarikçi
INSERT INTO tedarikciler (ad, yetkili, telefon, email) VALUES
('Regal Türkiye Distribütörü', 'Satış Departmanı', '0212 000 00 00', 'satis@regal.com.tr');

-- Ayarlar
CREATE TABLE IF NOT EXISTS ayarlar (
    anahtar VARCHAR(100) PRIMARY KEY,
    deger TEXT,
    grup VARCHAR(50) DEFAULT 'genel',
    aciklama VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
