-- 013: ejecutar UNA VEZ después de 012 y antes de desplegar backend.
-- Respaldar y comprobar tipos PK, FKs y que la tabla de ventas serializadas existe.
-- La PK compuesta permite varias ventas de una unidad sin borrar su historia.
ALTER TABLE maderas7_ttrazabilidad.serial_sale_units DROP PRIMARY KEY, ADD PRIMARY KEY(unit_id,sale_id);
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.serial_sale_reversals (
 unit_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 business_id BIGINT UNSIGNED NOT NULL,
 return_id BIGINT UNSIGNED NULL,
 warehouse_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 reversal_kind VARCHAR(24) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(unit_id,sale_id),
 KEY idx_serial_reversal_sale(business_id,sale_id),
 KEY idx_serial_reversal_return(business_id,return_id),
 CONSTRAINT fk_serial_reversal_sale_unit FOREIGN KEY(unit_id,sale_id) REFERENCES maderas7_ttrazabilidad.serial_sale_units(unit_id,sale_id),
 CONSTRAINT fk_serial_reversal_return FOREIGN KEY(return_id) REFERENCES maderas7_ttrazabilidad.customer_returns(id),
 CONSTRAINT fk_serial_reversal_wh FOREIGN KEY(warehouse_id) REFERENCES maderas7_ttrazabilidad.warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
