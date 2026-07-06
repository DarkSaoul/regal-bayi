-- Kullanıcılar Modülü Büyük Genişletme — 2026-07-06
-- Uygulamadan önce yedek alın: backups/regal_bayi_kullanicilar_oncesi_2026-07-06.sql

ALTER TABLE kullanicilar
    ADD COLUMN zorla_cikis_tarihi DATETIME NULL AFTER aktif_oturum_token,
    ADD COLUMN sifre_degistir_zorunlu TINYINT(1) NOT NULL DEFAULT 0 AFTER zorla_cikis_tarihi,
    ADD COLUMN sifre_muaf TINYINT(1) NOT NULL DEFAULT 0 AFTER sifre_degistir_zorunlu,
    ADD COLUMN avatar VARCHAR(150) NULL AFTER sifre_muaf,
    ADD COLUMN izin_iade_yapabilir TINYINT(1) NOT NULL DEFAULT 1 AFTER avatar,
    ADD COLUMN totp_gizli_anahtar VARCHAR(255) NULL AFTER izin_iade_yapabilir,
    ADD COLUMN totp_aktif TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_gizli_anahtar,
    ADD COLUMN davet_token VARCHAR(64) NULL AFTER totp_aktif,
    ADD COLUMN davet_son_tarih DATETIME NULL AFTER davet_token,
    ADD COLUMN hesap_gecerlilik_tarihi DATE NULL AFTER davet_son_tarih,
    ADD COLUMN sistem_notu TEXT NULL AFTER hesap_gecerlilik_tarihi,
    ADD COLUMN sistem_notu_okundu TINYINT(1) NOT NULL DEFAULT 1 AFTER sistem_notu,
    ADD COLUMN bildirim_tercihi ENUM('varsayilan','kapali') NOT NULL DEFAULT 'varsayilan' AFTER sistem_notu_okundu,
    ADD UNIQUE KEY (davet_token);
