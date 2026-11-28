-- 025: anticipos por cotización; aplicar después de 024 con respaldo.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.quote_advance_movements (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 quote_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('receipt','refund') NOT NULL,
 amount_clp BIGINT UNSIGNED NOT NULL,
 method ENUM('efectivo','transferencia','tarjeta','otro') NOT NULL,
 reference VARCHAR(120) NULL,
 reason VARCHAR(250) NOT NULL,
 cash_session_id BIGINT UNSIGNED NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_quote_advance_request(business_id,request_id),
 KEY idx_quote_advance_quote(business_id,quote_id,id),
 CONSTRAINT fk_quote_advance_quote FOREIGN KEY(quote_id) REFERENCES maderas7_ttrazabilidad.quotes(id),
 CONSTRAINT fk_quote_advance_client FOREIGN KEY(client_id) REFERENCES maderas7_ttrazabilidad.habitual_clients(id),
 CONSTRAINT fk_quote_advance_actor FOREIGN KEY(actor_id) REFERENCES maderas7_ttrazabilidad.users(id),
 CONSTRAINT fk_quote_advance_session FOREIGN KEY(cash_session_id) REFERENCES maderas7_ttrazabilidad.cash_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE maderas7_ttrazabilidad.credit_payments
 ADD COLUMN IF NOT EXISTS advance_quote_id BIGINT UNSIGNED NULL;
CREATE INDEX IF NOT EXISTS idx_credit_payment_advance
 ON maderas7_ttrazabilidad.credit_payments(business_id,advance_quote_id);
ALTER TABLE maderas7_ttrazabilidad.credit_payments
 ADD CONSTRAINT fk_credit_payment_advance FOREIGN KEY(advance_quote_id)
 REFERENCES maderas7_ttrazabilidad.quotes(id);

ALTER TABLE maderas7_ttrazabilidad.cash_movements
 MODIFY COLUMN movement_type ENUM('cash_sale','credit_payment','customer_refund','credit_refund','withdrawal','addition','quote_advance','quote_advance_refund') NOT NULL,
 ADD COLUMN IF NOT EXISTS source_quote_advance_id BIGINT UNSIGNED NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_cash_quote_advance
 ON maderas7_ttrazabilidad.cash_movements(source_quote_advance_id);
ALTER TABLE maderas7_ttrazabilidad.cash_movements
 ADD CONSTRAINT fk_cash_quote_advance FOREIGN KEY(source_quote_advance_id)
 REFERENCES maderas7_ttrazabilidad.quote_advance_movements(id);
