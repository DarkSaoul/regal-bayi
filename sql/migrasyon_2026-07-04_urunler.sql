-- ============================================================
-- Migrasyon 2026-07-04 — Ürünler modülü genişletmesi
-- urunler: resim + birim sütunları
-- fiyat_gecmisi: ürün bazlı fiyat değişiklik geçmişi
-- ============================================================

ALTER TABLE urunler
    ADD COLUMN resim VARCHAR(255) DEFAULT NULL AFTER aciklama,
    ADD COLUMN birim VARCHAR(20) NOT NULL DEFAULT 'Adet' AFTER renk;

CREATE TABLE IF NOT EXISTS fiyat_gecmisi (
    id INT(11) NOT NULL AUTO_INCREMENT,
    urun_id INT(11) NOT NULL,
    eski_alis DECIMAL(10,2) DEFAULT NULL,
    yeni_alis DECIMAL(10,2) DEFAULT NULL,
    eski_satis DECIMAL(10,2) DEFAULT NULL,
    yeni_satis DECIMAL(10,2) DEFAULT NULL,
    kaynak VARCHAR(30) NOT NULL DEFAULT 'duzenleme', -- olusturma | duzenleme | toplu_fiyat | ice_aktar
    kullanici_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY urun_id (urun_id),
    CONSTRAINT fiyat_gecmisi_ibfk_1 FOREIGN KEY (urun_id) REFERENCES urunler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
