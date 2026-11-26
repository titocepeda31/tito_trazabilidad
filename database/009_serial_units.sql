-- 009: control de unidades serializadas. Importar después de 008, antes del backend.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_product_controls (
 product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 enabled_by BIGINT UNSIGNED NOT NULL,
 enabled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_serial_control_tenant(business_id,product_id),
 CONSTRAINT fk_serial_control_product FOREIGN KEY(product_id) REFERENCES maderas7_ttrazabilidad.products(id),
 CONSTRAINT fk_serial_control_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_units (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 serial_number VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 receipt_id BIGINT UNSIGNED NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 unit_cost_clp BIGINT UNSIGNED NOT NULL,
 received_at DATE NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'in_stock',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_serial_unit(business_id,product_id,serial_number),
 KEY idx_serial_lookup(business_id,serial_number),
 KEY idx_serial_receipt(business_id,receipt_id),
 KEY idx_serial_supplier(business_id,supplier_id),
 CONSTRAINT fk_serial_unit_control FOREIGN KEY(business_id,product_id) REFERENCES maderas7_ttrazabilidad.serial_product_controls(business_id,product_id),
 CONSTRAINT fk_serial_unit_receipt FOREIGN KEY(receipt_id) REFERENCES maderas7_ttrazabilidad.goods_receipts(id),
 CONSTRAINT fk_serial_unit_supplier FOREIGN KEY(business_id,supplier_id) REFERENCES maderas7_ttrazabilidad.suppliers(business_id,id),
 CONSTRAINT fk_serial_unit_warehouse FOREIGN KEY(warehouse_id) REFERENCES maderas7_ttrazabilidad.warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_unit_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 details TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_serial_unit_events(business_id,unit_id,id),
 CONSTRAINT fk_serial_event_unit FOREIGN KEY(unit_id) REFERENCES maderas7_ttrazabilidad.serial_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
