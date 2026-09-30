#!/usr/bin/env bash
# Construye el ZIP instalable del plugin en build/gestion-de-proyectos-<versión>.zip
# Uso: bash bin/build-zip.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="gestion-de-proyectos"
VERSION="$(grep -E '^\s*\*\s*Version:' "$ROOT/$SLUG.php" | head -1 | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')"
BUILD="$ROOT/build"
STAGE="$BUILD/$SLUG"

if [[ -z "$VERSION" ]]; then
	echo "No se pudo leer la versión desde $SLUG.php" >&2
	exit 1
fi

rm -rf "$STAGE"
mkdir -p "$STAGE"

# Copia todo salvo lo listado en .distignore (sin depender de rsync).
( cd "$ROOT" && tar --exclude-from="$ROOT/.distignore" --exclude="./build" -cf - . ) | ( cd "$STAGE" && tar -xf - )

# Si existen dependencias de producción de Composer, se incluyen sin las de desarrollo.
if command -v composer >/dev/null 2>&1 && [[ -f "$ROOT/composer.json" ]]; then
	( cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet 2>/dev/null || true )
	rm -f "$STAGE/composer.json" "$STAGE/composer.lock"
fi

( cd "$BUILD" && rm -f "$SLUG-$VERSION.zip" && zip -qr "$SLUG-$VERSION.zip" "$SLUG" -x "*.DS_Store" )
rm -rf "$STAGE"

echo "Paquete generado: build/$SLUG-$VERSION.zip"
