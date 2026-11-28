-- 037: clasificación tributaria versionada y revisión de impuestos DTE.
-- Aplicar después de 036 con respaldo. No emite ni modifica ventas.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.product_tax_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 treatment ENUM('unknown','vat19','exempt','non_taxable') NOT NULL,
 effective_on DATE NOT NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_product_tax_request(business_id,request_id,product_id),
 KEY idx_product_tax_effective(business_id,product_id,effective_on,id),
 CONSTRAINT fk_product_tax_product FOREIGN KEY(product_id)
 REFERENCES maderas7_ttrazabilidad.products(id),
 CONSTRAINT fk_product_tax_business FOREIGN KEY(business_id)
 REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_product_tax_actor FOREIGN KEY(actor_id)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.dte_tax_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 result_status ENUM('observed','reconciled') NOT NULL,
 result_json MEDIUMTEXT NOT NULL,
 evidence_reference VARCHAR(250) NOT NULL,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_dte_tax_request(business_id,request_id),
 KEY idx_dte_tax_review(business_id,document_id,id),
 CONSTRAINT fk_dte_tax_review_document FOREIGN KEY(document_id)
 REFERENCES maderas7_ttrazabilidad.dte_documents(id),
 CONSTRAINT fk_dte_tax_review_business FOREIGN KEY(business_id)
 REFERENCES maderas7_ttrazabilidad.businesses(id),
 CONSTRAINT fk_dte_tax_review_actor FOREIGN KEY(actor_id)
 REFERENCES maderas7_ttrazabilidad.users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
