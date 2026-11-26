<?php
declare(strict_types=1);
if ($argc !== 4) exit(2);
try {
    $old = require $argv[1];
    if (!is_array($old)) throw new RuntimeException();
    $cfg = [];
    foreach (['db_host','db_port','db_user','db_password'] as $key) {
        if (array_key_exists($key, $old)) $cfg[$key] = $old[$key];
    }
    $cfg['db_name'] = $argv[3];
    $cfg['allowed_origins'] = [];
    foreach (['db_host','db_user','db_password'] as $key) {
        if (!isset($cfg[$key]) || !is_string($cfg[$key])) throw new RuntimeException();
    }
    $fp = fopen($argv[2], 'x');
    if (!$fp) throw new RuntimeException();
    $data = "<?php\nreturn ".var_export($cfg, true).";\n";
    $n = fwrite($fp, $data); fclose($fp);
    if ($n !== strlen($data)) { unlink($argv[2]); throw new RuntimeException(); }
    chmod($argv[2], 0600);
    echo "Configuración independiente creada; CORS pendiente.\n";
} catch (Throwable $e) { fwrite(STDERR, "No se pudo crear la configuración independiente.\n"); exit(1); }
