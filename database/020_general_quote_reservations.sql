-- 020: ampliar las líneas de reserva a productos normales y serializados.
-- Aplicar después de 019, con respaldo y backend coordinado. No repetir DROP/ADD FK.
-- Verificar que exista fk_res_item_control y que products.id sea BIGINT UNSIGNED.
-- Las reservas previas y sus cantidades se conservan.
ALTER TABLE maderas7_ttrazabilidad.serial_reservation_items
 DROP FOREIGN KEY fk_res_item_control,
 MODIFY COLUMN quantity DECIMAL(14,3) UNSIGNED NOT NULL,
 ADD CONSTRAINT fk_res_item_product FOREIGN KEY (product_id)
 REFERENCES maderas7_ttrazabilidad.products(id);
