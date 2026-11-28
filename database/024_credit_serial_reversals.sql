-- 024: relacionar reversas serializadas con ajustes de crédito. Después de 023.
-- Respaldar y comprobar tipos y existencia de tablas antes de ejecutar.
ALTER TABLE maderas7_ttrazabilidad.serial_sale_reversals
 ADD COLUMN IF NOT EXISTS credit_adjustment_id BIGINT UNSIGNED NULL;
CREATE INDEX IF NOT EXISTS idx_serial_reversal_credit
 ON maderas7_ttrazabilidad.serial_sale_reversals(business_id,credit_adjustment_id);
ALTER TABLE maderas7_ttrazabilidad.serial_sale_reversals
 ADD CONSTRAINT fk_serial_reversal_credit FOREIGN KEY (credit_adjustment_id)
 REFERENCES maderas7_ttrazabilidad.credit_adjustments(id);
