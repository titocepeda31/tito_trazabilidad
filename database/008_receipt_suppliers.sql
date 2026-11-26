-- Tito Trazabilidad: importar después de 007 y antes de actualizar el backend.
-- Relación e identificación histórica del proveedor; los costos permanecen en las líneas de recepción.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.receipt_supplier_snapshots (
 receipt_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL,
 supplier_name VARCHAR(150) NOT NULL,
 tax_country VARCHAR(2) NOT NULL,
 tax_id VARCHAR(50) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_receipt_supplier_history(business_id,supplier_id,receipt_id),
 CONSTRAINT fk_receipt_supplier_catalog FOREIGN KEY(business_id,supplier_id) REFERENCES maderas7_ttrazabilidad.suppliers(business_id,id),
 CONSTRAINT fk_receipt_supplier_receipt FOREIGN KEY(receipt_id) REFERENCES maderas7_ttrazabilidad.goods_receipts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
