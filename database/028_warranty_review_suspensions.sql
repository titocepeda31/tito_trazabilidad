-- 028: revisión de plazo, períodos de suspensión y enlace a garantía histórica.
-- Aplicar después de 027, con respaldo.
ALTER TABLE maderas7_ttrazabilidad.sale_warranty_coverages
 ADD COLUMN IF NOT EXISTS legal_review_until DATE NULL,
 ADD COLUMN IF NOT EXISTS legacy_warranty_id BIGINT UNSIGNED NULL;
ALTER TABLE maderas7_ttrazabilidad.sale_warranty_coverages
 ADD CONSTRAINT fk_coverage_legacy_warranty FOREIGN KEY(legacy_warranty_id)
 REFERENCES maderas7_ttrazabilidad.unit_warranties(id);

ALTER TABLE maderas7_ttrazabilidad.warranty_coverage_events
 MODIFY COLUMN kind ENUM('base_date','reported','confirmed','observed','note','policy','legal_review','suspend','resume') NOT NULL,
 ADD COLUMN IF NOT EXISTS details_json TEXT NULL;

CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.warranty_suspensions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 coverage_id BIGINT UNSIGNED NOT NULL,
 starts_on DATE NOT NULL,
 resumed_on DATE NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 resumed_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_warranty_suspension_coverage(business_id,coverage_id,id),
 CONSTRAINT fk_warranty_suspension_coverage FOREIGN KEY(coverage_id)
 REFERENCES maderas7_ttrazabilidad.sale_warranty_coverages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
