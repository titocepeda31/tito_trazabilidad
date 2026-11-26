<?php
declare(strict_types=1);
require getenv('TITO_BOOT');
$db=db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='maderas7_tposdb')throw new RuntimeException('Base inesperada: no instalar delegaciones.');
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pos_staff_permissions(
 business_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 can_approve_discounts TINYINT(1) NOT NULL DEFAULT 0,
 can_sell_credit TINYINT(1) NOT NULL DEFAULT 0,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(business_id,user_id),
 CONSTRAINT fk_pos_perm_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_pos_perm_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_pos_perm_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pos_staff_permission_changes(
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 target_user_id BIGINT UNSIGNED NOT NULL,
 changed_by BIGINT UNSIGNED NOT NULL,
 old_discounts TINYINT(1) NOT NULL,
 new_discounts TINYINT(1) NOT NULL,
 old_credit TINYINT(1) NOT NULL,
 new_credit TINYINT(1) NOT NULL,
 changed_at DATETIME NOT NULL,
 INDEX idx_pos_changes(business_id,id),
 CONSTRAINT fk_pos_change_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_pos_change_user FOREIGN KEY (target_user_id) REFERENCES users(id),
 CONSTRAINT fk_pos_change_actor FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pos_staff_events(
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(45) NOT NULL,
 sale_id BIGINT UNSIGNED NULL,
 client_id BIGINT UNSIGNED NULL,
 amount_clp BIGINT UNSIGNED NULL,
 description VARCHAR(250) NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX idx_pos_staff_event(business_id,id),
 CONSTRAINT fk_pos_event_business FOREIGN KEY (business_id) REFERENCES businesses(id),
 CONSTRAINT fk_pos_event_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
foreach(['pos_staff_permissions'=>['business_id','user_id','can_approve_discounts','can_sell_credit'],'pos_staff_permission_changes'=>['id','target_user_id','changed_by'],'pos_staff_events'=>['id','actor_id','event_type','sale_id']] as $name=>$cols){
 $actual=array_column($db->query('SHOW COLUMNS FROM '.$name)->fetchAll(PDO::FETCH_ASSOC),'Field');
 foreach($cols as $col)if(!in_array($col,$actual,true))throw new RuntimeException('Falta columna '.$name.'.'.$col);
}
echo "OK: delegaciones creadas; todos los cajeros parten sin permisos adicionales.\n";
