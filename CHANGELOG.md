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
- Papelera: toda eliminación (actividades con sus contenidas, líneas base, calendarios) pasa por la capa de operaciones con instantánea previa y se restaura desde la papelera; la eliminación de un proyecto exige teclear su código. Editor de catálogos (frentes de trabajo y demás) en el panel.
- Carta Gantt: creación de dependencias arrastrando desde el conector de una barra, desenlace con un clic sobre la flecha, borrado de la restricción con doble clic, zoom trimestral, filtros por frente, estado, criticidad, responsable y texto, e impresión con hoja de estilos propia.
- Tablero agrupado por estado, por frente de trabajo o por responsable.
- Carga de trabajo por persona y semana con alerta de sobreasignación (pantalla, alertas e informe).
- Curva S de avance planificado frente a real en el informe semanal (pantalla y LaTeX con `pgfplots`).
- Fotografías semanales del cronograma (`gdp_schedule_snapshots`, esquema 3) y comparación con la semana anterior en el informe: frentes que se abren y se cierran, entradas y salidas de la ruta crítica, término previsto y avance.
- Calendario integrado (mes, semana, agenda) con hitos e inicios y términos de actividades; suscripción iCalendar con clave por usuario; filtro `gdp_calendar_events` para que otros módulos aporten sus vencimientos.
- Alerta cuando la desviación del término respecto de la línea base vigente supera el umbral de aprobación del financiador, configurable por proyecto.
- Exportación del cronograma en XLSX (hojas Cronograma, Dependencias y Frentes, escritor propio) y en XML de Microsoft Project (MSPDI), además de CSV, LaTeX, JSON e iCalendar; enlaces de exportación en la lista de actividades.
- Doble factor de autenticación delegado: ajuste "exigir doble factor", detección de Two Factor, WP 2FA y Wordfence Login Security (filtros `gdp_two_factor_provider` y `gdp_user_has_two_factor` para otros), negación de los permisos sensibles (documentos, montos, exportaciones, bitácora) a quien no lo tenga, aviso con enlace al perfil, comprobación en el diagnóstico del conector y estado en `system-status`.
- Plantilla de traducción `languages/gestion-de-proyectos.pot` (944 cadenas) generada con WP-CLI (`composer run make-pot`); todas las cadenas con marcadores llevan comentario para traductores y los marcadores múltiples van numerados.
- Importación de cronogramas desde CSV, XLSX y XML de Microsoft Project con vista previa (cabeceras en español e inglés, jerarquía por código o nivel, predecesoras en notación compacta que pueden apuntar a actividades existentes, frentes y responsables resueltos, restricción "no empezar antes de" para filas con fecha y sin predecesoras); operación reversible; acción `import` en `propose-activity-change` para el conector.

### Corregido
- Aviso de clave indefinida al importar filas sin prioridad (XML de Project).

### Cambiado
- Versión de esquema 3; la desinstalación con borrado de datos elimina también las tablas del módulo.
- `OperationManager::execute()` propone y confirma en un paso para los formularios del panel y las importaciones, de modo que también esas escrituras quedan registradas y son reversibles.

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
