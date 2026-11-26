#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
HOME_DIR="$(cd -- "$HOME" && pwd -P)"
APP="$HOME_DIR/tito-trazabilidad-privado/api"
WEB="$HOME_DIR/public_html/tito-trazabilidad-api"
OLD="$HOME_DIR/tito-pos-privado/api"
PHPBIN="/opt/cpanel/ea-php83/root/usr/bin/php"
DB_NAME="$(id -un)_ttrazabilidad"
fail(){ echo "BLOQUEADO: $*" >&2; exit 1; }

[[ "$REPO" == "$HOME_DIR/repositories/tito_trazabilidad" ]] || fail "ruta del repositorio inesperada"
[[ -x "$PHPBIN" ]] || fail "PHP 8.3 CLI no disponible"
[[ "$("$PHPBIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" == "8.3" ]] || fail "versión PHP CLI incorrecta"
[[ -d "$HOME_DIR/public_html" ]] || fail "public_html no encontrado"
for d in "$HOME_DIR/tito-trazabilidad-privado" "$APP" "$APP/private" "$APP/public" "$APP/migrations" "$APP/vendor" "$APP/runtime" "$APP/backups" "$WEB"; do
  [[ ! -L "$d" ]] || fail "enlace simbólico no permitido: $d"
done
mkdir -p "$APP/private" "$APP/public" "$APP/migrations" "$APP/runtime" "$APP/backups"
chmod 700 "$HOME_DIR/tito-trazabilidad-privado" "$APP" "$APP/private" "$APP/runtime" "$APP/backups"
LOCK="$APP/runtime/deploy.lock"
mkdir "$LOCK" 2>/dev/null || fail "otro despliegue en curso o bloqueo anterior"
trap 'rmdir "$LOCK" 2>/dev/null || true' EXIT

# Solo lectura del proyecto original: copiar dependencias si coinciden.
if [[ ! -f "$APP/vendor/autoload.php" ]]; then
  [[ -f "$OLD/vendor/autoload.php" && -f "$OLD/composer.lock" ]] || fail "vendor o composer.lock de Tito POS ausentes; instalar dependencias por separado"
  cmp -s "$OLD/composer.lock" "$REPO/backend/composer.lock" || fail "composer.lock original no coincide; instalar dependencias por separado"
  cp -a "$OLD/vendor" "$APP/"
else
  [[ -f "$APP/composer.lock" ]] && cmp -s "$APP/composer.lock" "$REPO/backend/composer.lock" || fail "dependencias cambiaron; revisar Composer antes de publicar"
fi

# Validar PHP antes de publicar y no ejecutar migraciones.
for file in "$REPO"/backend/private/*.php "$REPO"/backend/migrations/*.php "$REPO/backend/public/index.php" "$REPO/deploy/public/index.php"; do
  "$PHPBIN" -l "$file" >/dev/null || fail "PHP inválido: $file"
done

# Inicializar configuración solo si no existe. No se imprime ni versiona.
[[ ! -L "$APP/private/config.php" ]] || fail "config.php es enlace simbólico"
if [[ ! -f "$APP/private/config.php" ]]; then
  [[ -f "$OLD/private/config.php" ]] || fail "config.php original no encontrado; crear config.php nuevo manualmente"
  "$PHPBIN" "$REPO/deploy/init-config.php" "$OLD/private/config.php" "$APP/private/config.php" "$DB_NAME"
fi
chmod 600 "$APP/private/config.php"
"$PHPBIN" "$REPO/deploy/check-config.php" "$APP/private/config.php" "$DB_NAME"
"$PHPBIN" -r 'require $argv[1]; if (!class_exists("Slim\\Factory\\AppFactory")) exit(1);' "$APP/vendor/autoload.php" || fail "Slim no disponible"

# Respaldo únicamente del proyecto nuevo. Excluye credenciales y vendor.
BACKUP="$(mktemp -d "$APP/backups/deploy-XXXXXXXX")"
for d in private migrations public; do
  mkdir -p "$BACKUP/$d"
  for file in "$REPO"/backend/"$d"/*.php; do
    name="$(basename "$file")"
    if [[ -f "$APP/$d/$name" ]]; then cp -p "$APP/$d/$name" "$BACKUP/$d/$name"; fi
  done
done
for f in composer.json composer.lock; do
  if [[ -f "$APP/$f" ]]; then cp -p "$APP/$f" "$BACKUP/$f"; fi
done
for d in private migrations public; do
  for file in "$REPO"/backend/"$d"/*.php; do
    name="$(basename "$file")"
    [[ "$name" != "config.php" && "$name" != *_local.php ]] || fail "archivo de configuración local no permitido"
    [[ ! -L "$APP/$d/$name" ]] || fail "destino es enlace simbólico"
    install -m 600 "$file" "$APP/$d/$name.new"
    mv -f "$APP/$d/$name.new" "$APP/$d/$name"
  done
done
install -m 600 "$REPO/backend/composer.json" "$APP/composer.json"
install -m 600 "$REPO/backend/composer.lock" "$APP/composer.lock"

# Solo el punto de entrada queda dentro de public_html.
mkdir -p "$WEB"
chmod 755 "$WEB"
for f in index.php .htaccess; do
  [[ ! -L "$WEB/$f" ]] || fail "destino público es enlace simbólico"
  if [[ -f "$WEB/$f" ]]; then cp -p "$WEB/$f" "$BACKUP/$f"; fi
  install -m 644 "$REPO/deploy/public/$f" "$WEB/$f.new"
  mv -f "$WEB/$f.new" "$WEB/$f"
done
echo "OK: API Tito Trazabilidad desplegada. Base verificada: $DB_NAME. Sin migraciones ni modificaciones en Tito POS."
echo "Siguiente: comprobar /tito-trazabilidad-api/health y configurar CORS para Vercel."
