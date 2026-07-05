-- Sistem Ayarları Büyük Genişletme — 2026-07-06
-- Uygulamadan önce yedek alın: backups/regal_bayi_ayarlar_oncesi_2026-07-06.sql

-- Ayar değişiklik geçmişi (audit + geri yükleme)
CREATE TABLE ayar_gecmisi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anahtar VARCHAR(100) NOT NULL,
    eski_deger TEXT NULL,
    yeni_deger TEXT NULL,
    not_metni VARCHAR(255) NULL,
    kullanici_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY anahtar (anahtar),
    CONSTRAINT ayar_gecmisi_fk1 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Yeni ayarlar (görsel/marka, sistem&altyapı, çalışma zamanı)
INSERT INTO ayarlar (anahtar, deger, grup, aciklama) VALUES
    ('firma_logo', '', 'firma', 'Fatura/fiş/sözleşme ve giriş sayfasında gösterilen firma logosu dosya adı'),
    ('favicon', '', 'firma', 'Tarayıcı sekmesi simgesi dosya adı'),
    ('login_arkaplan', '', 'firma', 'Giriş sayfası arkaplan görseli dosya adı'),
    ('kase_imza', '', 'firma', 'Fatura/sözleşmede gösterilen kaşe/imza görseli dosya adı'),
    ('sosyal_instagram', '', 'firma', 'Instagram kullanıcı adı/linki — fatura altında gösterilir'),
    ('sosyal_facebook', '', 'firma', 'Facebook sayfa linki — fatura altında gösterilir'),
    ('tema_ikincil_renk', '#6c757d', 'gorunum', 'İkincil/vurgu rengi (hex)'),
    ('yazi_tipi', 'sistem', 'gorunum', 'Uygulama genelinde kullanılan yazı tipi'),
    ('fatura_kagit_boyutu', 'A4', 'gorunum', 'Fatura yazdırma için varsayılan kağıt boyutu (A4/A5/80mm)'),
    ('sidebar_duzen', 'sabit', 'gorunum', 'Sol menü düzeni: sabit veya daraltilabilir'),
    ('zaman_dilimi', 'Europe/Istanbul', 'sistem', 'Uygulamanın kullandığı zaman dilimi'),
    ('etiket_genislik_mm', '60', 'sistem', 'Ürün barkod etiketi genişliği (mm)'),
    ('etiket_yukseklik_mm', '35', 'sistem', 'Ürün barkod etiketi yüksekliği (mm)'),
    ('bakim_modu', '0', 'sistem', 'Açıksa yönetici dışındaki kullanıcılar bakım sayfası görür'),
    ('bakim_mesaji', 'Sistem bakımdadır, kısa süre sonra tekrar deneyin.', 'sistem', 'Bakım modunda gösterilecek mesaj'),
    ('calisma_gunleri', '1,2,3,4,5,6', 'sistem', 'Çalışılan haftanın günleri (1=Pazartesi..7=Pazar, virgülle ayrılmış)'),
    ('mesai_baslangic', '09:00', 'sistem', 'Mesai başlangıç saati'),
    ('mesai_bitis', '19:00', 'sistem', 'Mesai bitiş saati'),
    ('resmi_tatiller', '', 'sistem', 'Resmi tatil tarihleri, virgülle ayrılmış YYYY-AA-GG listesi'),
    ('dashboard_kasiyer_finans', '1', 'sistem', 'Kasiyer dashboard''da kasa/ciro/tahsilat bilgilerini görsün mü (1/0)'),
    ('dashboard_depo_ciro', '0', 'sistem', 'Depo rolü dashboard''da parasal (ciro/envanter değeri) bilgi görsün mü (1/0)')
ON DUPLICATE KEY UPDATE deger=VALUES(deger);
