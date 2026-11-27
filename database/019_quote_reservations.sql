-- 019: vínculo cotización-reserva serializada. Aplicar después de 018 con respaldo.
-- Ejecutar con backend coordinado: un backend antiguo no protege los precios vinculados.
ALTER TABLE maderas7_ttrazabilidad.quotes
 MODIFY COLUMN status ENUM('draft','converted','sent','accepted','rejected','reserved') NOT NULL DEFAULT 'draft';
ALTER TABLE maderas7_ttrazabilidad.serial_reservations
 ADD COLUMN IF NOT EXISTS quote_id BIGINT UNSIGNED NULL;
CREATE INDEX IF NOT EXISTS idx_res_quote
 ON maderas7_ttrazabilidad.serial_reservations(business_id,quote_id,id);
-- Integridad de empresa y vigencia se comprueba en transacción por API.
-- Añadir FK una vez, previa verificación de que no exista y que los tipos coincidan.
ALTER TABLE maderas7_ttrazabilidad.serial_reservations
 ADD CONSTRAINT fk_serial_reservation_quote FOREIGN KEY (quote_id)
 REFERENCES maderas7_ttrazabilidad.quotes(id);
