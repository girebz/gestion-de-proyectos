# 0008. Autocarga PSR-4 propia y paquete instalable sin Composer en el sitio

Fecha: 2026-09-30. Estado: aceptada.

## Decisión

El plugin incluye un autocargador PSR-4 propio (`src/Autoloader.php`) y usa `vendor/autoload.php` solo si existe. Composer se emplea únicamente para herramientas de desarrollo (estándares, análisis estático, pruebas). El paquete instalable se construye con `bin/build-zip.sh` a partir de `.distignore`, y la integración continua lo publica al etiquetar `v*`.

## Consecuencias

- El ZIP se instala en cualquier hosting compartido sin ejecutar Composer.
- Si en el futuro se añade una dependencia de producción, se incluirá en el ZIP mediante `composer install --no-dev` dentro del script de construcción (ya previsto).
