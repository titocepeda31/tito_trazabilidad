-- 011: venta inmediata serializada; importar después de 010 antes de PHP.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_sale_snapshots (
 sale_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 client_name VARCHAR(150) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_serial_sale_client(business_id,client_id),
 CONSTRAINT fk_serial_sale_sale FOREIGN KEY(sale_id) REFERENCES maderas7_ttrazabilidad.sales(id),
 CONSTRAINT fk_serial_sale_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_sale_units (
 unit_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 sale_item_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 unit_price_clp BIGINT UNSIGNED NOT NULL,
 KEY idx_serial_sale_units(business_id,sale_id,sale_item_id),
 CONSTRAINT fk_serial_sale_unit FOREIGN KEY(unit_id) REFERENCES maderas7_ttrazabilidad.serial_units(id),
 CONSTRAINT fk_serial_sale_snapshot FOREIGN KEY(sale_id) REFERENCES maderas7_ttrazabilidad.serial_sale_snapshots(sale_id),
 CONSTRAINT fk_serial_sale_item FOREIGN KEY(sale_item_id) REFERENCES maderas7_ttrazabilidad.sale_items(id),
 CONSTRAINT fk_serial_sale_warehouse FOREIGN KEY(warehouse_id) REFERENCES maderas7_ttrazabilidad.warehouses(id)
) ENGINE=InnoDB;
