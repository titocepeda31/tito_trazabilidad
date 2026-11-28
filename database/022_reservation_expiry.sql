-- 022: vencimiento de reservas. Aplicar después de 021 con respaldo.
-- NULL conserva reservas anteriores sin vencimiento; no inventar fechas históricas.
ALTER TABLE maderas7_ttrazabilidad.serial_reservations
 ADD COLUMN IF NOT EXISTS expires_on DATE NULL;
CREATE INDEX IF NOT EXISTS idx_res_expiry
 ON maderas7_ttrazabilidad.serial_reservations(business_id,status,expires_on,id);
ALTER TABLE maderas7_ttrazabilidad.quotes
 MODIFY COLUMN status ENUM('draft','converted','sent','accepted','rejected','reserved','partial_closed','reservation_expired') NOT NULL DEFAULT 'draft';
