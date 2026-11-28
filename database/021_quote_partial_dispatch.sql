-- 021: entregas parciales vinculadas a cotizaciones. Aplicar después de 020.
-- Respaldar y verificar esquema/FKs antes de actualizar el backend.
ALTER TABLE maderas7_ttrazabilidad.quotes
 MODIFY COLUMN status ENUM('draft','converted','sent','accepted','rejected','reserved','partial_closed') NOT NULL DEFAULT 'draft';
ALTER TABLE maderas7_ttrazabilidad.serial_reservation_items
 ADD COLUMN IF NOT EXISTS dispatched_quantity DECIMAL(14,3) UNSIGNED NOT NULL DEFAULT 0;
-- Solo reservas históricas completamente despachadas. Repetir únicamente previa auditoría.
UPDATE maderas7_ttrazabilidad.serial_reservation_items i
 JOIN maderas7_ttrazabilidad.serial_reservations r ON r.id=i.reservation_id AND r.business_id=i.business_id
 SET i.dispatched_quantity=i.quantity WHERE r.status='dispatched' AND i.dispatched_quantity=0;
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.quote_dispatches (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 quote_id BIGINT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 total_clp BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_quote_dispatch_sale(sale_id),
 UNIQUE KEY uq_quote_dispatch_request(business_id,request_id),
 KEY idx_quote_dispatch_quote(business_id,quote_id,id),
 CONSTRAINT fk_qdispatch_quote FOREIGN KEY(quote_id) REFERENCES maderas7_ttrazabilidad.quotes(id),
 CONSTRAINT fk_qdispatch_res FOREIGN KEY(business_id,reservation_id) REFERENCES maderas7_ttrazabilidad.serial_reservations(business_id,id),
 CONSTRAINT fk_qdispatch_sale FOREIGN KEY(sale_id) REFERENCES maderas7_ttrazabilidad.sales(id),
 CONSTRAINT fk_qdispatch_actor FOREIGN KEY(actor_id) REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
