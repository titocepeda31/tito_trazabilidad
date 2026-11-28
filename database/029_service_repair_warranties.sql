-- 029: garantía por reparación entregada, independiente de las coberturas de venta.
-- Aplicar después de 028 con respaldo; comprobar tipos de claves.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.service_repair_warranties (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 case_id BIGINT UNSIGNED NOT NULL,
 provider_kind ENUM('internal','external','manufacturer') NOT NULL,
 provider_name VARCHAR(150) NOT NULL,
 technician_name VARCHAR(150) NOT NULL,
 work_description VARCHAR(1000) NOT NULL,
 components VARCHAR(1000) NOT NULL,
 billing_kind ENUM('warranty','paid','other') NOT NULL,
 completed_on DATE NOT NULL,
 delivered_on DATE NOT NULL,
 commercial_duration SMALLINT UNSIGNED NULL,
 commercial_unit ENUM('days','months','years') NULL,
 commercial_ends_on DATE NULL,
 legal_review_basis ENUM('completion','delivery') NULL,
 legal_review_until DATE NULL,
 legal_review_note VARCHAR(1000) NULL,
 terms VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_repair_warranty_case(case_id),
 KEY idx_repair_warranty_business(business_id,id),
 CONSTRAINT fk_repair_warranty_case FOREIGN KEY(case_id)
 REFERENCES maderas7_ttrazabilidad.service_cases(id),
 CONSTRAINT fk_repair_warranty_actor FOREIGN KEY(created_by)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE maderas7_ttrazabilidad.service_cases
 ADD COLUMN IF NOT EXISTS prior_repair_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS closure_outcome ENUM('repaired','not_repaired') NULL;
ALTER TABLE maderas7_ttrazabilidad.service_cases
 ADD CONSTRAINT fk_service_prior_repair FOREIGN KEY(prior_repair_id)
 REFERENCES maderas7_ttrazabilidad.service_repair_warranties(id);
