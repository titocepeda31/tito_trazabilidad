<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

function respond(ResponseInterface $response, array $data, int $status=200): ResponseInterface {
    $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return $response->withStatus($status)->withHeader('Content-Type','application/json; charset=utf-8')->withHeader('Cache-Control','no-store');
}
function requestBody(ServerRequestInterface $request): array {
    $raw=(string)$request->getBody();
    if(strlen($raw)>65536)fail('Solicitud demasiado grande.',413);
    try{$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(JsonException){fail('JSON inválido.',400);}
    if(!is_array($data)||array_is_list($data))fail('Se esperaba un objeto JSON.',422);
    return $data;
}
function authUser(ServerRequestInterface $request, PDO $db): array {
    $header=$request->getHeaderLine('Authorization');
    if(!preg_match('/^Bearer ([a-f0-9]{64})$/i',$header,$m))fail('Necesitas iniciar sesión.',401);
    $tokenHash=hash('sha256',strtolower($m[1]));
    $st=$db->prepare('SELECT users.id,users.business_id,users.email,users.role,businesses.name AS business_name FROM sessions JOIN users ON users.id=sessions.user_id JOIN businesses ON businesses.id=users.business_id WHERE sessions.token_hash=? AND sessions.expires_at>UTC_TIMESTAMP() AND users.is_active=1 LIMIT 1');
    $st->execute([$tokenHash]);
    $user=$st->fetch();
    if(!$user)fail('Sesión expirada o inválida.',401);
    return $user;
}

/** Mitigación básica de fuerza bruta; los intentos se guardan fuera de public_html. */
function throttleLogin(string $email, ?bool $failure): void {
    $dir=__DIR__.'/../runtime/login';
    if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)){
        throw new RuntimeException('No se pudo iniciar el control de acceso.');
    }
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $file=$dir.'/'.hash('sha256',$ip.'|'.$email).'.json';
    $fp=fopen($file,'c+');
    if(!$fp)throw new RuntimeException('No se pudo acceder al control de acceso.');
    try{
        if(!flock($fp,LOCK_EX))throw new RuntimeException('No se pudo bloquear el control de acceso.');
        $raw=stream_get_contents($fp);
        $data=json_decode($raw?:'{}',true);
        if(!is_array($data))$data=[];
        if((int)($data['since']??0)<time()-900)$data=['since'=>time(),'attempts'=>0];
        $attempts=(int)($data['attempts']??0);
        if($failure===false && $attempts>=8)fail('Demasiados intentos. Reintenta más tarde.',429);
        if($failure===true)$data['attempts']=$attempts+1;
        if($failure===null)$data=['since'=>time(),'attempts'=>0];
        rewind($fp);ftruncate($fp,0);
        fwrite($fp,json_encode($data,JSON_THROW_ON_ERROR));fflush($fp);
    }finally{flock($fp,LOCK_UN);fclose($fp);}
}
