<?php
declare(strict_types=1);

function config(): array {
    static $settings = null;
    if ($settings === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('API sin configurar');
        }
        $settings = require $path;
        if (!is_array($settings)) {
            throw new RuntimeException('Configuración inválida');
        }
        // Origen de producción de Tito Trazabilidad; conservar orígenes locales explícitos.
        $localOrigins = $settings['allowed_origins'] ?? [];
        if (!is_array($localOrigins)) {
            throw new RuntimeException('Configuración CORS inválida');
        }
        $settings['allowed_origins'] = array_values(array_unique(array_merge(
            $localOrigins,
            ['https://titotrazabilidad.vercel.app']
        )));
    }
    return $settings;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)($c['db_port'] ?? 3306), $c['db_name']),
            $c['db_user'],
            $c['db_password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

class HttpError extends RuntimeException {
    public function __construct(string $message, public int $status=400, public ?string $field=null){parent::__construct($message);}
}
function fail(string $message, int $status=400): never {throw new HttpError($message,$status);}
function requireString(array $b,string $key,int $max=120): string {
    $value=$b[$key]??null;
    if(!is_string($value)||($value=trim($value))===''||mb_strlen($value)>$max)fail('Campo inválido: '.$key,422);
    return $value;
}
function integerPrice(mixed $value): int {
    if(!is_int($value)||$value<0||$value>1000000000)fail('El precio debe ser un número entero en CLP.',422);
    return $value;
}
function decimalQuantity(mixed $value,bool $allowNegative=false,bool $allowFraction=true): string {
    if(!is_numeric($value)||is_bool($value))fail('Cantidad inválida.',422);
    $n=(float)$value;
    if(!is_finite($n)||abs($n)>1000000000||(!$allowNegative&&$n<0)||abs($n*1000-round($n*1000))>0.00001||(!$allowFraction&&abs($n-round($n))>0.00001))fail('Cantidad inválida para la unidad.',422);
    return number_format($n,3,'.','');
}
function move(PDO $db,int $businessId,int $productId,?int $saleId,int $userId,string $type,string $delta,string $after,?string $reason): void {
    $st=$db->prepare('INSERT INTO stock_movements(business_id,product_id,sale_id,changed_by,movement_type,delta_qty,balance_after,reason) VALUES (?,?,?,?,?,?,?,?)');
    $st->execute([$businessId,$productId,$saleId,$userId,$type,$delta,$after,$reason]);
}
