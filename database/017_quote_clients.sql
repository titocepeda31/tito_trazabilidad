-- 017: identidad del cliente en cotizaciones. Aplicar después de 016 con respaldo.
-- No asociar cotizaciones históricas por coincidencia de nombres.
ALTER TABLE maderas7_ttrazabilidad.quotes
 ADD COLUMN IF NOT EXISTS client_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS customer_rut VARCHAR(16) NULL,
 ADD COLUMN IF NOT EXISTS customer_email VARCHAR(254) NULL;
CREATE INDEX IF NOT EXISTS idx_quotes_commercial_client
 ON maderas7_ttrazabilidad.quotes(business_id,client_id,id);
