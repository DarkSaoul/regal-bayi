-- Yedekleme Modülü Büyük Genişletme — 2026-07-06
-- Uygulamadan önce yedek alın: backups/regal_bayi_yedekleme_oncesi_2026-07-06.sql

CREATE TABLE yedekleme_gecmisi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dosya_adi VARCHAR(150) NOT NULL,
    tip ENUM('manuel','otomatik') NOT NULL DEFAULT 'manuel',
    kapsam ENUM('tam','hizli','sadece_sema') NOT NULL DEFAULT 'tam',
    dosyalar_dahil TINYINT(1) NOT NULL DEFAULT 0,
    sikistirilmis TINYINT(1) NOT NULL DEFAULT 0,
    sifreli TINYINT(1) NOT NULL DEFAULT 0,
    boyut_bayt BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 VARCHAR(64) NULL,
    not_metni VARCHAR(255) NULL,
    basarili TINYINT(1) NOT NULL DEFAULT 1,
    hata_metni VARCHAR(500) NULL,
    ikinci_konum_kopyalandi TINYINT(1) NOT NULL DEFAULT 0,
    kullanici_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL,
    KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ayarlar (anahtar, deger, grup, aciklama) VALUES
    ('yedek_sikligi', 'haftalik', 'sistem', 'Otomatik yedekleme sıklığı (gunluk/haftalik/aylik)'),
    ('yedek_saklama_gun', '90', 'sistem', 'Yedeklerin saklanacağı gün sayısı (0 = sınırsız)'),
    ('yedek_saklama_adet', '0', 'sistem', 'Saklanacak maksimum yedek adedi (0 = sınırsız, gün bazlı politika ile birlikte çalışır)'),
    ('yedek_ikinci_konum', '', 'sistem', 'Yedeklerin ayrıca kopyalanacağı ikinci bir yerel/ağ yolu (boş = kapalı)'),
    ('yedek_dosyalari_dahil', '0', 'sistem', 'Yedeğe uploads/ klasörünü (ürün/marka görselleri, belgeler) de dahil et'),
    ('yedek_sifrele', '0', 'sistem', 'Yedekleri şifrele (.env içindeki YEDEK_SIFRELEME_ANAHTARI gereklidir)'),
    ('yedek_haric_tablolar', 'aktivite_loglari,ayar_gecmisi', 'sistem', '"Hızlı Yedek" kapsamında hariç tutulacak tablolar (virgülle ayrılmış)')
ON DUPLICATE KEY UPDATE deger=VALUES(deger);
