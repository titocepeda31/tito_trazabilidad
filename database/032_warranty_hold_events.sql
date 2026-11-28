-- 032: custodia y disposición auditada de equipos retenidos.
-- Aplicar después de 031 con respaldo.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.warranty_hold_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 hold_event_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 action ENUM('review','send_manufacturer','receive_manufacturer','restock','retire') NOT NULL,
 from_status VARCHAR(24) NOT NULL,
 to_status VARCHAR(24) NOT NULL,
 recipient VARCHAR(150) NULL,
 note VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_hold_request(business_id,request_id),
 KEY idx_hold_unit(business_id,unit_id,id),
 KEY idx_hold_cycle(business_id,hold_event_id,id),
 CONSTRAINT fk_hold_unit FOREIGN KEY(unit_id)
 REFERENCES maderas7_ttrazabilidad.serial_units(id),
 CONSTRAINT fk_hold_origin_event FOREIGN KEY(hold_event_id)
 REFERENCES maderas7_ttrazabilidad.serial_unit_events(id),
 CONSTRAINT fk_hold_warehouse FOREIGN KEY(warehouse_id)
 REFERENCES maderas7_ttrazabilidad.warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
