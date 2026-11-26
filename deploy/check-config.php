<?php
declare(strict_types=1);
if ($argc !== 3) exit(2);
try {
    $c = require $argv[1];
    if (!is_array($c) || ($c['db_name'] ?? '') !== $argv[2]) throw new RuntimeException();
    if (!extension_loaded('mbstring')) throw new RuntimeException();
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)($c['db_port'] ?? 3306), $c['db_name']), $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $argv[2]) throw new RuntimeException();
    $pdo->query('SELECT 1 FROM users LIMIT 1');
    $pdo->query('SELECT 1 FROM businesses LIMIT 1');
    echo "Conexión a la nueva base de datos verificada.\n";
} catch (Throwable $e) { fwrite(STDERR, "No se pudo verificar la base de datos independiente. Revisar configuración, permisos y tablas.\n"); exit(1); }
