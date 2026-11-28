-- 034: apertura de inventario serializado sin compra ficticia.
-- Respaldar y verificar FK de 009 antes de aplicar. Ejecutar una sola vez.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_opening_batches (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 warehouse_name VARCHAR(150) NOT NULL,
 counted_on DATE NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 unit_cost_clp BIGINT UNSIGNED NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 note VARCHAR(1000) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_serial_opening_request(business_id,request_id),
 KEY idx_serial_opening_product(business_id,product_id,id),
 CONSTRAINT fk_serial_opening_product FOREIGN KEY(business_id,product_id)
 REFERENCES maderas7_ttrazabilidad.serial_product_controls(business_id,product_id),
 CONSTRAINT fk_serial_opening_warehouse FOREIGN KEY(warehouse_id)
 REFERENCES maderas7_ttrazabilidad.warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE maderas7_ttrazabilidad.serial_units
 DROP FOREIGN KEY fk_serial_unit_receipt,
 DROP FOREIGN KEY fk_serial_unit_supplier;
ALTER TABLE maderas7_ttrazabilidad.serial_units
 MODIFY COLUMN receipt_id BIGINT UNSIGNED NULL,
 MODIFY COLUMN supplier_id BIGINT UNSIGNED NULL,
 MODIFY COLUMN unit_cost_clp BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS opening_id BIGINT UNSIGNED NULL;
ALTER TABLE maderas7_ttrazabilidad.serial_units
 ADD CONSTRAINT fk_serial_unit_receipt FOREIGN KEY(receipt_id)
 REFERENCES maderas7_ttrazabilidad.goods_receipts(id),
 ADD CONSTRAINT fk_serial_unit_supplier FOREIGN KEY(business_id,supplier_id)
 REFERENCES maderas7_ttrazabilidad.suppliers(business_id,id),
 ADD CONSTRAINT fk_serial_unit_opening FOREIGN KEY(opening_id)
 REFERENCES maderas7_ttrazabilidad.serial_opening_batches(id);
