-- Taksit Takvimi Büyük Genişletme — 2026-07-06
-- Uygulamadan önce yedek alın: backups/regal_bayi_taksit_oncesi_2026-07-06.sql

ALTER TABLE taksit_plani
    ADD COLUMN orijinal_vade_tarihi DATE NULL AFTER vade_tarihi,
    ADD COLUMN takip_durumu ENUM('normal','takipte') NOT NULL DEFAULT 'normal' AFTER odeme_id,
    ADD COLUMN son_hatirlatma_tarihi DATE NULL AFTER takip_durumu;

-- Hatırlatma geçmişi (WhatsApp/e-posta) — aynı taksiti günde birden fazla rahatsız etmemek + geçmiş takibi
CREATE TABLE taksit_hatirlatmalari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taksit_id INT NOT NULL,
    tarih DATE NOT NULL,
    kanal ENUM('whatsapp','eposta') NOT NULL DEFAULT 'whatsapp',
    kullanici_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY taksit_id (taksit_id),
    CONSTRAINT taksit_hatirlatma_fk1 FOREIGN KEY (taksit_id) REFERENCES taksit_plani(id) ON DELETE CASCADE,
    CONSTRAINT taksit_hatirlatma_fk2 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Erteleme geçmişi — vade tarihi her değiştirildiğinde denetim kaydı
CREATE TABLE taksit_erteleme_gecmisi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taksit_id INT NOT NULL,
    eski_vade_tarihi DATE NOT NULL,
    yeni_vade_tarihi DATE NOT NULL,
    sebep VARCHAR(255) NULL,
    kullanici_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY taksit_id (taksit_id),
    CONSTRAINT taksit_erteleme_fk1 FOREIGN KEY (taksit_id) REFERENCES taksit_plani(id) ON DELETE CASCADE,
    CONSTRAINT taksit_erteleme_fk2 FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT INTO ayarlar (anahtar, deger, grup, aciklama) VALUES
    ('taksit_gecikme_cezasi_oran', '0', 'finans', 'Gecikmiş taksitler için aylık gecikme cezası oranı (%). 0 = kapalı, yalnızca bilgi amaçlı gösterilir, otomatik tahsil edilmez.'),
    ('taksit_erken_odeme_indirim', '0', 'finans', 'Kalan taksitler peşin kapatılırsa uygulanacak indirim oranı (%). 0 = kapalı.'),
    ('taksit_takip_esik_gun', '30', 'finans', 'Bu kadar gün geciken taksitler "Takip Önerilir" rozetiyle işaretlenir.')
ON DUPLICATE KEY UPDATE deger=VALUES(deger);
