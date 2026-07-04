-- ============================================================
-- Migrasyon 2026-07-04 — Toplu fiyat geri alma desteği
-- islem_grubu: aynı toplu güncellemedeki kayıtları gruplar;
-- geri alma kayıtları 'geri:<grup>' önekiyle işaretlenir.
-- ============================================================

ALTER TABLE fiyat_gecmisi
    ADD COLUMN islem_grubu VARCHAR(40) DEFAULT NULL AFTER kaynak,
    ADD KEY islem_grubu (islem_grubu);
