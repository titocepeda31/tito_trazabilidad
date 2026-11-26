<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base de datos inesperada');
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS onesignal_push_subscriptions(
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 admin_user_id BIGINT UNSIGNED NOT NULL,
 subscription_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_tito_push_sub(subscription_id),
 KEY idx_tito_push_admin(business_id,admin_user_id),
 CONSTRAINT fk_tito_push_business FOREIGN KEY(business_id) REFERENCES businesses(id),
 CONSTRAINT fk_tito_push_admin FOREIGN KEY(admin_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$cols=array_column($db->query('SHOW COLUMNS FROM onesignal_push_subscriptions')->fetchAll(PDO::FETCH_ASSOC),'Field');
foreach(['id','business_id','admin_user_id','subscription_id','created_at','updated_at'] as $col){
 if(!in_array($col,$cols,true))throw new RuntimeException('Falta columna '.$col);
}
echo "OK: suscripciones de OneSignal configuradas.\n";
