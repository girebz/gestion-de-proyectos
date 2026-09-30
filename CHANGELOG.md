# Registro de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/); versionado semántico.

## [Sin publicar]

## [0.1.0] - 2026-09-30

### Añadido
- Arranque del plugin con comprobación de requisitos (WordPress 6.9, PHP 8.1), autocarga PSR-4 propia y migraciones de esquema por versión.
- Núcleo: tablas `gdp_projects`, `gdp_project_members`, `gdp_audit_log`, `gdp_operations`, `gdp_connector_tokens`, `gdp_catalog_items`.
- Proyectos con código, financiador, plazo, presupuesto, estado, configuración y módulos activables; control optimista de versión.
- Perfiles por proyecto (director, ingeniero, investigador, apoyo, observador) con mapa de permisos finos; rol de sitio "Miembro de proyectos".
- Bitácora de auditoría de solo anexado con canal (admin, connector, import, cron, cli).
- Capa única de operaciones en dos tiempos: proponer con vista previa, confirmar, cancelar, revertir; caducidad de propuestas.
- Almacenamiento privado de adjuntos con entrega controlada.
- Identidad visual adoptada del sitio (nombre, logotipo, ícono, paleta y tipografía de theme.json) con correcciones manuales.
- Catálogos configurables con valores globales por defecto (tipos de documento, etapas de compra, estados, medios de verificación).
- Conector: categoría y habilidades de WordPress, servidor MCP propio en `/wp-json/gestion-de-proyectos/mcp`, tokens por usuario con alcance y caducidad, autenticación por cabecera, permisos de transporte y por herramienta.
- Herramientas del conector: estado del sistema, proyectos, usuarios, catálogos, bitácora, operaciones (proponer, listar, obtener, confirmar, cancelar, revertir).
- Panel: inicio, proyectos, operaciones, bitácora, conector (diagnóstico y asistente de integración paso a paso) y ajustes.
- Integración continua: sintaxis en PHP 8.1 a 8.4, estándares de WordPress, paquete instalable y publicación por etiqueta.
- Documentación: especificación funcional en LaTeX y bitácora de decisiones de arquitectura.
