-- 015: ficha comercial en el registro existente; aplicar después de 014.
-- MariaDB. Respaldar la BD; no modifica IDs ni autorización de crédito.
ALTER TABLE maderas7_ttrazabilidad.habitual_clients
 ADD COLUMN IF NOT EXISTS client_type VARCHAR(12) NULL,
 ADD COLUMN IF NOT EXISTS rut VARCHAR(16) NULL,
 ADD COLUMN IF NOT EXISTS business_activity VARCHAR(150) NULL,
 ADD COLUMN IF NOT EXISTS address VARCHAR(200) NULL,
 ADD COLUMN IF NOT EXISTS commune VARCHAR(100) NULL,
 ADD COLUMN IF NOT EXISTS contact_name VARCHAR(150) NULL,
 ADD COLUMN IF NOT EXISTS billing_email VARCHAR(254) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_habitual_clients_business_rut
 ON maderas7_ttrazabilidad.habitual_clients(business_id,rut);
