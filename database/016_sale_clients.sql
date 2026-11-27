-- 016: identidad comercial de ventas directas, aplicar después de 015 con respaldo.
ALTER TABLE maderas7_ttrazabilidad.sales
 ADD COLUMN IF NOT EXISTS client_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS client_name_snapshot VARCHAR(150) NULL,
 ADD COLUMN IF NOT EXISTS client_rut_snapshot VARCHAR(16) NULL,
 ADD COLUMN IF NOT EXISTS customer_mode VARCHAR(20) NULL,
 ADD COLUMN IF NOT EXISTS direct_payload_hash CHAR(64) NULL;
CREATE INDEX IF NOT EXISTS idx_sales_commercial_client ON maderas7_ttrazabilidad.sales(business_id,client_id,id);
-- No asignar RUT actuales a ventas históricas ni inventar consumidor final.
UPDATE maderas7_ttrazabilidad.sales s JOIN maderas7_ttrazabilidad.serial_sale_snapshots ss ON ss.sale_id=s.id AND ss.business_id=s.business_id
 SET s.client_id=ss.client_id,s.client_name_snapshot=ss.client_name,s.customer_mode='registered'
 WHERE s.customer_mode IS NULL AND s.client_id IS NULL;
