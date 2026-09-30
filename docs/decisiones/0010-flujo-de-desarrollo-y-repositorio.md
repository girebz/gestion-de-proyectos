# 0010. Repositorio, ramas, integración continua y versiones

Fecha: 2026-09-30. Estado: aceptada.

## Decisión

- Repositorio `girebz/gestion-de-proyectos` en GitHub; rama principal `main`, protegida por la integración continua.
- Cada módulo o funcionalidad se desarrolla en una rama propia y se integra por solicitud de cambios cuando pasa sintaxis (PHP 8.1 a 8.4), estándares de WordPress y pruebas.
- Versionado semántico; cada versión se etiqueta `vX.Y.Z` y la integración continua adjunta el ZIP instalable a la publicación de GitHub.
- La especificación funcional (`docs/especificacion`, LaTeX) y esta bitácora se versionan junto al código y se actualizan antes de implementar un cambio de alcance.
- Ningún secreto en el repositorio: tokens, claves y credenciales viven en la base de datos o en la configuración del servidor.
- Entorno de pruebas: un WordPress aparte (subdominio de pruebas o instalación local con la integración SQLite) donde se prueba cada versión antes de instalarla en el sitio en producción.

## Consecuencias

- Cualquier sesión de desarrollo, con cualquier modelo, parte del último commit, la especificación y esta bitácora, no de la memoria de una conversación.
- El sitio en producción solo recibe ZIP de versiones etiquetadas.
