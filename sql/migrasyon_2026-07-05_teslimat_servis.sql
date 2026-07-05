-- Teslimat Akışı: Dış Servis Firması Takibi — 2026-07-05
-- Uygulamadan önce yedek alın: backups/regal_bayi_teslimat_servis_oncesi_2026-07-05.sql
--
-- İş akışı: Müşteri kurulum gerektiren bir ürün alırsa, ayrı bir servis
-- firması mağazadan ürünü alıp müşteriye götürüyor. Bu sistem yalnızca
-- bayi içi kayıt tutuyor (servis firması sisteme giriş yapmıyor).
-- teslimat_durum akışı: yok -> hazirlaniyor -> serviste -> teslim_edildi

ALTER TABLE satislar
    MODIFY teslimat_durum ENUM('yok','hazirlaniyor','serviste','teslim_edildi') NOT NULL DEFAULT 'yok',
    ADD COLUMN servis_firma VARCHAR(150) NULL AFTER teslimat_durum,
    ADD COLUMN servis_eleman VARCHAR(100) NULL AFTER servis_firma,
    ADD COLUMN servis_telefon VARCHAR(20) NULL AFTER servis_eleman,
    ADD COLUMN servis_alis_tarihi DATETIME NULL AFTER servis_telefon,
    ADD COLUMN teslim_onay_tarihi DATE NULL AFTER servis_alis_tarihi,
    ADD COLUMN teslim_onay_notu VARCHAR(255) NULL AFTER teslim_onay_tarihi;
