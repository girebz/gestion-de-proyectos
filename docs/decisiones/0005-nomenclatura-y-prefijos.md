# 0005. Nombre, prefijos, espacio de nombres y dominio de traducción

Fecha: 2026-09-30. Estado: aceptada.

## Decisión

- Nombre visible: "Gestión de Proyectos". Identificador técnico y carpeta: `gestion-de-proyectos`.
- Espacio de nombres PHP: `GDP\`, con autocarga PSR-4 desde `src/`.
- Prefijo `gdp_` en tablas, opciones, transitorios, ganchos (`gdp_*`), capacidades y eventos de cron; prefijo `gdp-` en identificadores de HTML y CSS.
- Categoría de habilidades y espacio de nombres REST: `gestion-de-proyectos`; ruta MCP: `mcp`.
- Dominio de traducción: `gestion-de-proyectos`; textos en español preparados para traducción.
- Convención de nombres de archivo por clase (PSR-4), excluyendo la regla de nombres de archivo de los estándares de WordPress.

## Consecuencias

- Mientras el plugin sea privado, el nombre genérico no colisiona; si se publicara en el directorio de WordPress habría que distinguirlo.
- Toda extensión futura debe respetar los prefijos para no colisionar con otros plugins.
