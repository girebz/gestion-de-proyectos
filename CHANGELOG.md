# Registro de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/); versionado semántico.

## [Sin publicar]

## [0.2.0] - 2026-09-30

### Añadido
- Módulo de planificación y tiempo (etapa 1): estructura de desglose con código jerárquico, actividades, hitos y resúmenes; frentes de trabajo, responsables, entregables, partidas y costo planificado.
- Motor de programación por ruta crítica independiente de WordPress (`src/Planning`): calendario laboral, dependencias FS, SS, FF y SF con adelantos y retrasos, restricciones de fecha, fechas reales, holguras total y libre, detección de ciclos y agregación de resúmenes; convenciones en la decisión 0011.
- Feriados de Chile generados por reglas legales (Pascua, traslados al lunes, 31 de octubre, puentes de Fiestas Patrias, 2 de enero, solsticio calculado).
- Tablas `gdp_activities`, `gdp_dependencies`, `gdp_calendars`, `gdp_calendar_exceptions`, `gdp_baselines`, `gdp_baseline_activities`, `gdp_assignments`, `gdp_progress` (esquema 2).
- Pantallas: lista de actividades con filtros, avance rápido y reordenamiento; formulario con editor de predecesoras, asignaciones e historial; carta Gantt SVG propia con arrastre, zoom, línea base, ruta crítica y dependencias; tablero por estado con arrastre; calendarios con carga de feriados; líneas base con comparación; alertas; informe semanal con exportación en LaTeX, CSV, JSON e iCalendar; tarjeta en la ficha del proyecto.
- Operaciones en dos tiempos para actividades (`activity`: create, update, delete, set_progress, set_dependencies, move, set_assignment, remove_assignment, create_baseline) con reversión, incluida la restauración de eliminaciones en cascada.
- Herramientas del conector: `get-schedule`, `get-activity`, `get-critical-path`, `get-alerts`, `weekly-report`, `list-baselines`, `get-calendar`, `propose-activity-change`.
- Tareas programadas: recálculo diario con alertas guardadas y aviso por correo; resumen semanal por correo a directores e ingenieros cuando las notificaciones están activas.
- Suite de pruebas unitarias del motor (PHPUnit; ejecutor mínimo `tests/bin/run.php` para entornos sin Composer) y `phpunit.xml.dist`, con lo que la integración continua ejecuta las pruebas.
- Ganchos para módulos en el panel (`gdp_admin_register`, `gdp_admin_menu`, `gdp_admin_assets`, `gdp_project_view_cards`).

### Cambiado
- Versión de esquema 2; la desinstalación con borrado de datos elimina también las tablas del módulo.

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
