-- Git Tabanlı Güncelleme Bildirimi/Uygulama Sistemi — 2026-07-06
-- Uygulamadan önce yedek alın: backups/regal_bayi_gitguncelleme_oncesi_2026-07-06.sql

CREATE TABLE migration_gecmisi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_adi VARCHAR(150) NOT NULL UNIQUE,
    uygulandi_tarih TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    basarili TINYINT(1) NOT NULL DEFAULT 1,
    hata_metni TEXT NULL,
    kullanici_id INT NULL,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE git_guncelleme_gecmisi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eski_commit VARCHAR(40) NOT NULL,
    yeni_commit VARCHAR(40) NOT NULL,
    migration_sayisi INT NOT NULL DEFAULT 0,
    yedek_dosya_adi VARCHAR(150) NULL,
    basarili TINYINT(1) NOT NULL DEFAULT 1,
    hata_metni TEXT NULL,
    geri_alindi TINYINT(1) NOT NULL DEFAULT 0,
    kullanici_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ayarlar (anahtar, deger, grup, aciklama) VALUES
    ('git_guncelleme_kontrolu_aktif', '1', 'sistem', 'GitHub üzerinde yeni sürüm olup olmadığı otomatik kontrol edilsin mi'),
    ('git_son_kontrol_zamani', '', 'sistem', 'En son GitHub güncelleme kontrolünün yapıldığı zaman (önbellek amaçlı)')
ON DUPLICATE KEY UPDATE deger=VALUES(deger);

-- Şu ana kadarki tüm migration dosyaları zaten manuel olarak canlıya uygulanmıştı;
-- yeni takip sistemi bunları "tekrar uygulanacak" sanmasın diye geçmişe kaydediliyor.
INSERT INTO migration_gecmisi (dosya_adi, uygulandi_tarih) VALUES
    ('migrasyon_2026-07-02.sql', '2026-07-02 20:46:00'),
    ('migrasyon_2026-07-03_tedarikci.sql', '2026-07-03 15:05:00'),
    ('migrasyon_2026-07-04_urunler.sql', '2026-07-04 23:21:00'),
    ('migrasyon_2026-07-04_kategoriler.sql', '2026-07-04 23:36:00'),
    ('migrasyon_2026-07-04_fiyat_grubu.sql', '2026-07-04 23:48:00'),
    ('migrasyon_2026-07-05_sayim.sql', '2026-07-05 11:12:00'),
    ('migrasyon_2026-07-05_tesir.sql', '2026-07-05 11:00:00'),
    ('migrasyon_2026-07-05_musteriler.sql', '2026-07-05 16:38:00'),
    ('migrasyon_2026-07-05_satislar.sql', '2026-07-05 16:53:00'),
    ('migrasyon_2026-07-05_teslimat_servis.sql', '2026-07-05 22:34:00'),
    ('migrasyon_2026-07-06_finans.sql', '2026-07-05 23:09:00'),
    ('migrasyon_2026-07-06_taksit.sql', '2026-07-05 23:49:00'),
    ('migrasyon_2026-07-06_ayarlar.sql', '2026-07-06 00:11:00'),
    ('migrasyon_2026-07-06_sistem_kontrol.sql', '2026-07-06 00:39:00'),
    ('migrasyon_2026-07-06_yedekleme.sql', '2026-07-06 03:44:00'),
    ('migrasyon_2026-07-06_kullanicilar.sql', '2026-07-06 06:52:00'),
    ('migrasyon_2026-07-06_git_guncelleme.sql', NOW());
