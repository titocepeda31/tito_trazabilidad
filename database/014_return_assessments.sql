-- 014: registro independiente de evaluaciones de postventa; aplicar después de 013.
CREATE TABLE IF NOT EXISTS maderas7_ttrazabilidad.customer_return_assessments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 outcome VARCHAR(24) NOT NULL,
 observations VARCHAR(1000) NOT NULL,
 evidence_reference VARCHAR(250) NULL,
 custody VARCHAR(24) NOT NULL,
 items_json MEDIUMTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_assessment_request(business_id,request_id),
 KEY idx_assessment_sale(business_id,sale_id,id),
 KEY idx_assessment_history(business_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
