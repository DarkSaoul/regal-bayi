-- ============================================================
-- Migrasyon 2026-07-05 — Müşteriler modülü genişletmesi
-- aktif: arşivleme (soft delete); musteri_notlari: tarihli not geçmişi
-- ============================================================

ALTER TABLE musteriler
    ADD COLUMN aktif TINYINT(1) NOT NULL DEFAULT 1 AFTER toplam_borc;

CREATE TABLE IF NOT EXISTS musteri_notlari (
    id INT(11) NOT NULL AUTO_INCREMENT,
    musteri_id INT(11) NOT NULL,
    not_metni VARCHAR(500) NOT NULL,
    kullanici_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY musteri_id (musteri_id),
    CONSTRAINT musteri_not_ibfk_1 FOREIGN KEY (musteri_id) REFERENCES musteriler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
