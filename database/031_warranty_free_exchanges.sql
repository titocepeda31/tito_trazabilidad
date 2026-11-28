-- 031: cambio gratuito de serie, sin nueva venta ni reversa financiera.
-- Aplicar después de 030 con respaldo.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.warranty_free_exchanges (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 root_unit_id BIGINT UNSIGNED NOT NULL,
 returned_unit_id BIGINT UNSIGNED NOT NULL,
 replacement_unit_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 case_id BIGINT UNSIGNED NULL,
 delivered_on DATE NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 coverage_review VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_free_exchange_out(sale_id,root_unit_id,returned_unit_id),
 UNIQUE KEY uq_free_exchange_in(sale_id,root_unit_id,replacement_unit_id),
 UNIQUE KEY uq_free_exchange_request(business_id,request_id),
 KEY idx_free_exchange_client(business_id,client_id,id),
 KEY idx_free_exchange_unit(business_id,replacement_unit_id,id),
 CONSTRAINT fk_free_exchange_root FOREIGN KEY(root_unit_id,sale_id)
 REFERENCES maderas7_ttrazabilidad.serial_sale_units(unit_id,sale_id),
 CONSTRAINT fk_free_exchange_returned FOREIGN KEY(returned_unit_id)
 REFERENCES maderas7_ttrazabilidad.serial_units(id),
 CONSTRAINT fk_free_exchange_new FOREIGN KEY(replacement_unit_id)
 REFERENCES maderas7_ttrazabilidad.serial_units(id),
 CONSTRAINT fk_free_exchange_warehouse FOREIGN KEY(warehouse_id)
 REFERENCES maderas7_ttrazabilidad.warehouses(id),
 CONSTRAINT fk_free_exchange_case FOREIGN KEY(case_id)
 REFERENCES maderas7_ttrazabilidad.service_cases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE maderas7_ttrazabilidad.service_cases
 ADD COLUMN IF NOT EXISTS physical_unit_id BIGINT UNSIGNED NULL,
 MODIFY COLUMN closure_outcome ENUM('repaired','not_repaired','replaced') NULL;
ALTER TABLE maderas7_ttrazabilidad.service_cases
 ADD CONSTRAINT fk_service_physical_unit FOREIGN KEY(physical_unit_id)
 REFERENCES maderas7_ttrazabilidad.serial_units(id);
