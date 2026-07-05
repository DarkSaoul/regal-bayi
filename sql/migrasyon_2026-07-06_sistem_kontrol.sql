-- Sistem Geneli Kontrol Ayarları — 2026-07-06
-- Uygulamadan önce yedek alın: backups/regal_bayi_sistemkontrol_oncesi_2026-07-06.sql

ALTER TABLE kullanicilar
    ADD COLUMN son_giris DATETIME NULL AFTER aktif,
    ADD COLUMN sifre_degistirilme_tarihi DATETIME NULL AFTER son_giris,
    ADD COLUMN aktif_oturum_token VARCHAR(64) NULL AFTER sifre_degistirilme_tarihi;

INSERT INTO ayarlar (anahtar, deger, grup, aciklama) VALUES
    -- Modül açma/kapama (yalnızca opsiyonel/ek modüller — çekirdek satış/stok/müşteri kapatılamaz)
    ('modul_teshir_aktif', '1', 'sistem', 'Teşhir Yönetimi modülü aktif mi'),
    ('modul_vardiya_aktif', '1', 'sistem', 'Vardiya (kasa devri) modülü aktif mi'),
    ('modul_taksit_takvimi_aktif', '1', 'sistem', 'Taksit Takvimi modülü aktif mi'),
    ('modul_gider_sablonlari_aktif', '1', 'sistem', 'Tekrarlayan Gider Şablonları modülü aktif mi'),
    ('modul_capraz_kontrol_aktif', '1', 'sistem', 'Tedarikçi Çapraz Kontrol modülü aktif mi'),
    ('modul_kdv_raporu_aktif', '1', 'sistem', 'KDV Özeti modülü aktif mi'),
    ('modul_nakit_akis_aktif', '1', 'sistem', 'Nakit Akış Tahmini modülü aktif mi'),

    -- Giriş sonrası varsayılan yönlendirme
    ('giris_sonrasi_yonetici', '', 'sistem', 'Yönetici girişten sonra hangi sayfaya yönlensin (boş=Dashboard)'),
    ('giris_sonrasi_kasiyer', '', 'sistem', 'Kasiyer girişten sonra hangi sayfaya yönlensin (boş=Dashboard)'),
    ('giris_sonrasi_depo', '', 'sistem', 'Depo girişten sonra hangi sayfaya yönlensin (boş=Dashboard)'),

    -- Şifre politikası
    ('sifre_min_uzunluk', '8', 'sistem', 'Yeni şifre minimum uzunluğu'),
    ('sifre_buyuk_harf_zorunlu', '1', 'sistem', 'Şifrede en az bir büyük harf zorunlu mu'),
    ('sifre_kucuk_harf_zorunlu', '1', 'sistem', 'Şifrede en az bir küçük harf zorunlu mu'),
    ('sifre_rakam_zorunlu', '1', 'sistem', 'Şifrede en az bir rakam zorunlu mu'),
    ('sifre_gecerlilik_gun', '0', 'sistem', 'Şifrenin geçerli kalacağı gün sayısı (0 = süresiz)'),

    -- Brute-force / oturum
    ('bf_max_deneme', '5', 'sistem', 'Hesap+IP başına izin verilen başarısız giriş denemesi'),
    ('bf_kilit_dakika', '15', 'sistem', 'Deneme limiti aşılınca kilit süresi (dakika)'),
    ('oturum_zaman_asimi_dakika', '30', 'sistem', 'Hareketsizlik sonrası oturumun kapanacağı süre (dakika)'),
    ('tek_oturum_zorunlu', '0', 'sistem', 'Bir kullanıcı aynı anda yalnızca bir cihazda oturum açabilsin mi'),

    -- Bildirim & otomasyon ana anahtarları
    ('bildirimler_aktif', '1', 'sistem', 'Dashboard ve üst çubuktaki tüm uyarı rozetleri gösterilsin mi'),
    ('otomasyon_aktif', '1', 'sistem', 'Tekrarlayan gider vade tespiti, kapanış hatırlatması gibi otomasyonlar çalışsın mı'),

    -- Performans / harici servis
    ('tcmb_kur_cache_dakika', '60', 'sistem', 'TCMB döviz kuru önbellek süresi (dakika)'),
    ('dashboard_hava_durumu_aktif', '1', 'sistem', 'Dashboard hava durumu widget''ı (harici API çağrısı) aktif mi'),

    -- Kullanıcı politikaları
    ('kullanici_email_zorunlu', '0', 'sistem', 'Yeni kullanıcı eklerken e-posta alanı zorunlu mu'),
    ('pasif_hesap_uyari_gun', '90', 'sistem', 'Bu kadar gündür giriş yapmayan hesaplar için uyarı gösterilir (0 = kapalı)'),

    -- Veri & denetim
    ('veri_maskeleme_aktif', '0', 'sistem', 'Açıkken müşteri telefon/TC gibi hassas alanlar listelerde maskelenir (demo/eğitim modu)'),
    ('salt_okunur_mod', '0', 'sistem', 'Açıkken tüm sistemde veri değiştiren işlemler engellenir (yalnızca görüntüleme)'),
    ('gecmis_saklama_gun', '365', 'sistem', 'Ayar geçmişi, taksit hatırlatma/erteleme geçmişi gibi kayıtların saklanacağı gün sayısı'),
    ('disk_uyari_esik_gb', '1', 'sistem', 'Sunucu disk boş alanı bu değerin (GB) altına düşünce uyarı gösterilir (0 = kapalı)')
ON DUPLICATE KEY UPDATE deger=VALUES(deger);
