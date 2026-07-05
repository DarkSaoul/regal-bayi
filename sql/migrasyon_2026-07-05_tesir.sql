-- ============================================================
-- Migrasyon 2026-07-05 — Teşhir süresi takibi
-- tesir_tarihi: seri no teşhire alındığında NOW() yazılır,
-- depoya dönünce NULL'lanır ("kaç gündür teşhirde" hesabı için)
-- ============================================================

ALTER TABLE seri_numaralari
    ADD COLUMN tesir_tarihi DATETIME DEFAULT NULL AFTER durum;

-- Halihazırda teşhirde olanlara bugünü yaz
UPDATE seri_numaralari SET tesir_tarihi = NOW() WHERE durum = 'tesirde';
