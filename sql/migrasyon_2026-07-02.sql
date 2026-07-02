-- ============================================================
-- Migrasyon / Veri Onarımı — 2026-07-02
-- Kod düzeltmeleriyle birlikte mevcut verideki tutarsızlıkları giderir.
-- Çalıştırma: /opt/lampp/bin/mysql -u root regal_bayi < migrasyon_2026-07-02.sql
-- NOT: Çalıştırmadan önce yedek alın!
-- ============================================================

-- 1) Tahsilatı alınmış ama işaretlenmemiş taksitleri kapat.
--    Kural: taksitler vade sırasıyla ödenmiş sayılır; kümülatif taksit
--    toplamı satışın ödenen tutarını aşmadığı sürece taksit "ödendi" olur.
UPDATE taksit_plani tp
JOIN (
    SELECT tp2.id, SUM(tp3.tutar) AS kumulatif
    FROM taksit_plani tp2
    JOIN taksit_plani tp3
      ON tp3.satis_id = tp2.satis_id AND tp3.taksit_no <= tp2.taksit_no
    GROUP BY tp2.id
) k ON k.id = tp.id
JOIN satislar s ON s.id = tp.satis_id
SET tp.odendi = 1
WHERE s.durum != 'iptal'
  AND tp.odendi = 0
  AND k.kumulatif <= s.odenen_tutar + 0.01;

-- 2) İptal edilmiş satışların ödenmemiş taksitlerini kaldır
--    (gecikmiş taksit sayaçlarını kirletiyordu).
DELETE tp FROM taksit_plani tp
JOIN satislar s ON s.id = tp.satis_id
WHERE s.durum = 'iptal' AND tp.odendi = 0;

-- 3) Müşteri borçlarını açık satışların kalanından yeniden hesapla
--    (artır/azalt yaklaşımıyla oluşmuş sapmaları düzeltir).
UPDATE musteriler m
SET m.toplam_borc = (
    SELECT COALESCE(SUM(s.kalan_tutar), 0)
    FROM satislar s
    WHERE s.musteri_id = m.id AND s.durum = 'bekliyor'
);

-- 4) İptal edilmiş satışlarda 'satildi' kalmış seri numaralarını depoya al.
UPDATE seri_numaralari sn
JOIN satislar s ON s.id = sn.satis_id
SET sn.durum = 'stokta', sn.satis_id = NULL
WHERE s.durum = 'iptal' AND sn.durum = 'satildi';
