-- ============================================================
-- Migrasyon 2026-07-05 — Sayım oturumları
-- Her sayım tek kayıt olarak saklanır (denetim + fark analizi);
-- ürün bazlı farklar sayim_detaylari'nda.
-- ============================================================

CREATE TABLE IF NOT EXISTS sayimlar (
    id INT(11) NOT NULL AUTO_INCREMENT,
    kategori_id INT(11) DEFAULT NULL,          -- kategori kapsamlı sayımsa (tur takibi için)
    kapsam VARCHAR(255) DEFAULT NULL,          -- insan okunur kapsam metni
    aciklama VARCHAR(255) DEFAULT NULL,
    sayilan INT NOT NULL DEFAULT 0,            -- işlenen (sayılan) ürün adedi
    degisen INT NOT NULL DEFAULT 0,
    atlanan INT NOT NULL DEFAULT 0,            -- çakışma nedeniyle atlanan
    net_fark INT NOT NULL DEFAULT 0,
    maliyet_etkisi DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fire_islendi TINYINT(1) NOT NULL DEFAULT 0,
    kullanici_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY kategori_id (kategori_id),
    KEY kullanici_id (kullanici_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS sayim_detaylari (
    id INT(11) NOT NULL AUTO_INCREMENT,
    sayim_id INT(11) NOT NULL,
    urun_id INT(11) NOT NULL,
    onceki INT NOT NULL DEFAULT 0,
    sayilan INT NOT NULL DEFAULT 0,
    fark INT NOT NULL DEFAULT 0,
    maliyet DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- fark × alış fiyatı
    PRIMARY KEY (id),
    KEY urun_id (urun_id),
    CONSTRAINT sayim_det_ibfk_1 FOREIGN KEY (sayim_id) REFERENCES sayimlar(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
