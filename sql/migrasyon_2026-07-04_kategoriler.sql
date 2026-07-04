-- ============================================================
-- Migrasyon 2026-07-04 — Kategoriler modülü genişletmesi
-- sira: elle sıralama; ikon/renk: görsel ayrım;
-- varsayilan_kdv/hedef_marj: ürün formunda otomatik doldurma
-- ============================================================

ALTER TABLE kategoriler
    ADD COLUMN sira INT NOT NULL DEFAULT 0 AFTER ust_id,
    ADD COLUMN ikon VARCHAR(50) DEFAULT NULL AFTER sira,
    ADD COLUMN renk VARCHAR(7) DEFAULT NULL AFTER ikon,
    ADD COLUMN aciklama VARCHAR(255) DEFAULT NULL AFTER renk,
    ADD COLUMN varsayilan_kdv DECIMAL(5,2) DEFAULT NULL AFTER aciklama,
    ADD COLUMN hedef_marj DECIMAL(5,2) DEFAULT NULL AFTER varsayilan_kdv;

-- Mevcut alfabetik düzeni başlangıç sırası olarak yaz
SET @s := 0;
UPDATE kategoriler SET sira = (@s := @s + 10) WHERE ust_id IS NULL ORDER BY ad;
SET @s := 0;
UPDATE kategoriler SET sira = (@s := @s + 10) WHERE ust_id IS NOT NULL ORDER BY ust_id, ad;
