# 0007. Adjuntos fuera de la carpeta pública con entrega controlada

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

Contratos, órdenes de compra e informes de laboratorio no deben ser accesibles por URL directa, como ocurre con la biblioteca de medios de WordPress.

## Decisión

`GDP\Core\Storage` mantiene un directorio privado dentro de `wp-content/uploads` (nombre configurable, por defecto `gdp-privado`) con `.htaccess` que deniega el acceso web (Apache 2.2 y 2.4) e `index.php` vacío. Los archivos se entregan solo mediante `admin-post.php?action=gdp_file` con nonce y comprobación de permiso delegada en los módulos por el filtro `gdp_file_access`. En Nginx la denegación debe configurarse en el servidor; el diagnóstico lo advierte.

## Consecuencias

- Los módulos que adjunten archivos guardan la ruta relativa dentro del directorio privado y responden al filtro de acceso.
- Los respaldos incluyen el directorio privado; la desinstalación no lo borra automáticamente.
