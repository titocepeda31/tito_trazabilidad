-- 030: vínculo histórico entre serie devuelta y reemplazo registrado.
-- Aplicar después de 029, con respaldo.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.warranty_replacements (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 original_sale_id BIGINT UNSIGNED NOT NULL,
 original_unit_id BIGINT UNSIGNED NOT NULL,
 replacement_sale_id BIGINT UNSIGNED NOT NULL,
 replacement_unit_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 case_id BIGINT UNSIGNED NULL,
 delivered_on DATE NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 coverage_review VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_replacement_original(original_unit_id,original_sale_id),
 UNIQUE KEY uq_replacement_new(replacement_unit_id,replacement_sale_id),
 UNIQUE KEY uq_replacement_request(business_id,request_id),
 KEY idx_replacement_client(business_id,client_id,id),
 CONSTRAINT fk_replacement_original FOREIGN KEY(original_unit_id,original_sale_id)
 REFERENCES maderas7_ttrazabilidad.serial_sale_units(unit_id,sale_id),
 CONSTRAINT fk_replacement_new FOREIGN KEY(replacement_unit_id,replacement_sale_id)
 REFERENCES maderas7_ttrazabilidad.serial_sale_units(unit_id,sale_id),
 CONSTRAINT fk_replacement_case FOREIGN KEY(case_id)
 REFERENCES maderas7_ttrazabilidad.service_cases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
