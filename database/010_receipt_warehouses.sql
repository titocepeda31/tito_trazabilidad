-- 010: ubicación histórica de nuevas recepciones.
-- Importar después de 009 y antes de desplegar PHP. No reconstruye datos antiguos.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.receipt_warehouse_snapshots (
 receipt_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 warehouse_name VARCHAR(120) NOT NULL,
 warehouse_code VARCHAR(80) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_receipt_warehouse(business_id,warehouse_id,receipt_id),
 CONSTRAINT fk_receipt_location_receipt FOREIGN KEY(receipt_id) REFERENCES maderas7_ttrazabilidad.goods_receipts(id),
 CONSTRAINT fk_receipt_location_warehouse FOREIGN KEY(warehouse_id) REFERENCES maderas7_ttrazabilidad.warehouses(id),
 CONSTRAINT fk_receipt_location_business FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
