-- Importar después de 011 antes del backend. Validar tipos PK existentes.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_reservations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL, warehouse_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL, client_name VARCHAR(150) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL, request_id CHAR(36) NOT NULL, payload_hash CHAR(64) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending', note VARCHAR(250) NOT NULL DEFAULT '',
 sale_id BIGINT UNSIGNED NULL, closed_by BIGINT UNSIGNED NULL, closed_at DATETIME NULL, cancel_reason VARCHAR(250) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_res_request(business_id,request_id), UNIQUE KEY uq_res_sale(sale_id), UNIQUE KEY uq_res_tenant(business_id,id),
 KEY idx_res_warehouse(business_id,warehouse_id,status,id),
 CONSTRAINT fk_res_biz FOREIGN KEY(business_id) REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_res_warehouse FOREIGN KEY(warehouse_id) REFERENCES maderas7_ttrazabilidad.warehouses(id),
 CONSTRAINT fk_res_sale FOREIGN KEY(sale_id) REFERENCES maderas7_ttrazabilidad.sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_reservation_items (
 reservation_id BIGINT UNSIGNED NOT NULL, business_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL,
 PRIMARY KEY(reservation_id,product_id), KEY idx_res_product(business_id,product_id,reservation_id),
 CONSTRAINT fk_res_item_parent FOREIGN KEY(business_id,reservation_id) REFERENCES maderas7_ttrazabilidad.serial_reservations(business_id,id),
 CONSTRAINT fk_res_item_control FOREIGN KEY(business_id,product_id) REFERENCES maderas7_ttrazabilidad.serial_product_controls(business_id,product_id)
) ENGINE=InnoDB;
