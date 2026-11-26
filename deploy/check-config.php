<?php
declare(strict_types=1);
if ($argc !== 3) exit(2);
$stage = 'configuración';
function diagnosticFail(string $message): never {
    fwrite(STDERR, "BLOQUEADO: ".$message."\n");
    exit(1);
}
try {
    $c = require $argv[1];
    if (!is_array($c)) diagnosticFail('config.php no devuelve un arreglo.');
    if (($c['db_name'] ?? '') !== $argv[2]) diagnosticFail('El nombre de la base en config.php no coincide con la base independiente esperada.');
    foreach (['db_host','db_user','db_password'] as $key) {
        if (!isset($c[$key]) || !is_string($c[$key])) diagnosticFail('Falta un parámetro de conexión: '.$key);
    }
    echo "OK: configuración apunta a la base independiente.\n";
    $stage = 'extensiones PHP CLI';
    foreach (['pdo_mysql','mbstring','json'] as $ext) {
        if (!extension_loaded($ext)) diagnosticFail('PHP CLI '.PHP_VERSION.' no tiene habilitada la extensión '.$ext.'.');
    }
    echo "OK: extensiones PHP.\n";
    $stage = 'conexión MariaDB';
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)($c['db_port'] ?? 3306), $c['db_name']), $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $argv[2]) diagnosticFail('MariaDB seleccionó una base diferente de la esperada.');
    echo "OK: conexión MariaDB y base independiente.\n";
    foreach (['users','businesses'] as $table) {
        $stage = 'lectura de tabla '.$table;
        $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1');
        echo "OK: tabla ".$table." accesible.\n";
    }
    echo "Conexión a la nueva base de datos verificada.\n";
} catch (PDOException $e) {
    $code = (int)($e->errorInfo[1] ?? 0);
    $reasons = [
        1044=>'El usuario MySQL no tiene permiso sobre la base seleccionada.',
        1045=>'MariaDB rechazó las credenciales o el acceso del usuario desde este host.',
        1049=>'La base de datos indicada no existe en este servidor.',
        1142=>'El usuario no tiene permiso de lectura sobre la tabla.',
        1146=>'La tabla requerida no existe en la base seleccionada.',
        2002=>'No se pudo conectar al servidor MariaDB (host, socket o servicio).',
        2003=>'No se pudo establecer conexión con MariaDB.',
        2005=>'No se pudo resolver el host de MariaDB.',
    ];
    diagnosticFail($stage.'; código MariaDB '.$code.'. '.($reasons[$code] ?? 'Error de acceso a MariaDB; revisar servidor y permisos.'));
} catch (Throwable $e) {
    diagnosticFail($stage.'; error de tipo '.get_class($e).'.');
}
