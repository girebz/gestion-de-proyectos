# Registro de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/); versionado semántico.

## [Sin publicar]

## [0.7.0] - 2026-10-01

### Añadido
- Módulo de tableros (etapa 1): tablero público de difusión insertable con el código corto `[gdp_avance proyecto="CODIGO" bloques="..."]`, con mensaje central y resumen, medidores de avance y plazo transcurrido, indicadores (automáticos o escritos a mano), etapas con nombre y frase públicos, logros y próximos hitos destacados, instituciones y financiamiento, y llamado a la acción con el correo de contacto ofuscado; solo muestra lo declarado publicable en la configuración y nunca montos, personas, compras ni documentos; publicación activable y aviso solo para administradores cuando no está activa.
- Tablero del equipo insertable con `[gdp_tablero_equipo proyecto="CODIGO"]` (foro o página privada): avance real frente al planificado según la curva S, plazo transcurrido, actividades atrasadas y que vencen en el horizonte configurable, ruta crítica, acuerdos abiertos, documentos con respuesta pendiente, compras abiertas, lo terminado y las reuniones de los últimos siete días, próxima reunión y presupuesto; reservado a usuarios con sesión iniciada (opción de exigir membresía del proyecto), con montos solo para quien tiene `procurement.view_amounts` y enlace al panel para quien puede entrar.
- Pantalla Tableros (configuración del tablero público con etapas, destacados, indicadores, instituciones y contacto; opciones del tablero del equipo; vista previa de ambos; códigos cortos para copiar e indicación para wpForo), tarjeta en la ficha del proyecto, paso en la guía de inicio, hoja de estilos del sitio con la identidad (`assets/css/dashboards.css`) y herramienta del conector `get-dashboard`.
- Caché de los tableros por proyecto (transitorios de 30 y 5 minutos) invalidada con cada cambio registrado mediante la nueva acción `gdp_audit_logged` de la bitácora; configuración guardada en `settings.dashboards` del proyecto, sin cambio de esquema; el archivo de ejemplo del proyecto de relaves incorpora la configuración de sus tableros; pruebas unitarias del saneamiento y de los cálculos.

## [0.6.0] - 2026-10-01

### Añadido
- Módulo de exportación, importación y respaldo (etapa 1): exportación de un proyecto por módulos o completa en JSON (formato `gestion-de-proyectos/export`, versión 1), ZIP con adjuntos, XLSX (una hoja por tabla) y CSV (un archivo por tabla), con las referencias traducidas a códigos y nombres (`_refs`), usuarios referidos y diccionario de datos; variante anonimizada (usuarios con seudónimo, sin contactos, correos, bitácora ni montos individuales). Los tokens del conector, las operaciones y la clave de la suscripción iCalendar nunca se exportan.
- Diccionario de datos generado de las definiciones del esquema (tabla, campo, tipo lógico y SQL, unidad, significado, tabla referida), consultable en el panel y descargable en CSV y JSON.
- Importación de JSON o ZIP mediante la capa de operaciones (`data`/`import`): validación de formato y esquema, reconocimiento de cada registro por su clave natural, reasignación de identificadores en orden de carga con segundo paso para autorreferencias, usuarios resueltos por nombre de usuario o correo, conflictos por versión, vista previa por tabla con ejemplos, aplicación parcial por tablas, reversión completa (elimina lo creado y restaura lo actualizado, archivos incluidos); modos de proyecto nuevo (con otro código) y actualización del proyecto existente; recálculo del cronograma al terminar.
- Respaldos del sitio (`respaldos/` en el directorio privado): ZIP con `datos.json`, `datos.sql` (sentencias INSERT para MariaDB o MySQL), `diccionario.json`, `meta.json` y `adjuntos/`; creación manual, semanal con retención (`backups_enabled`, `backups_keep`), copia a una carpeta externa (`backups_copy_dir`) y acción `gdp_backup_created`; restauración por la capa de operaciones (`data`/`restore_backup`) que crea antes un respaldo de seguridad y es revertible.
- Pantalla Datos (Exportar, Importar con vista previa de la propuesta, Diccionario, Respaldos), tarjeta en la ficha del proyecto, herramientas del conector `export-project`, `get-data-dictionary`, `propose-data-change`, `list-backups` y `create-backup`; limpieza diaria de archivos de importación; filtros `gdp_data_modules`, `gdp_data_refs`, `gdp_data_keys`, `gdp_data_dictionary` y `gdp_data_attachments` para módulos futuros.
- Archivo de ejemplo `docs/ejemplos/relaves-coquimbo.json` con los datos conocidos del proyecto de relaves (equipo, estructura de desglose con 33 actividades, compras y cotizaciones, documentos, reuniones y acuerdos), importable como proyecto nuevo; decisión de arquitectura 0013; pruebas unitarias del conocimiento del esquema.

## [0.5.0] - 2026-10-01

### Añadido
- Módulo de reuniones y acuerdos (etapa 1): reuniones con código correlativo anual y patrón configurable (`REU-{NNN}/{AAAA}`), tipo (equipo, financiador, proveedor, comité, terreno, otra), estado (programada, realizada, cancelada), fecha y horas, lugar, temas, resumen, transcripción, organizador, actividad asociada y notas; asistentes miembros del proyecto o externos (nombre, organización, correo, asistió).
- Acuerdos por reunión con código (`REU-001/2026.2`), responsable (usuario del sitio resuelto por nombre, usuario o correo, o nombre externo), plazo, estado (pendiente, en curso, cumplido, cancelado), origen (manual, propuesto, conector) y registro de seguimiento por revisión; conversión en actividad del cronograma (restricción de término por el plazo, entregable con el código, vínculo) con estado efectivo que sigue al de la actividad.
- Propuesta automática de acuerdos desde un resumen o transcripción (`AgreementExtractor`): marcas de compromiso, viñetas bajo encabezados, responsables (asistentes nombrados o nombre en la frase), plazos absolutos y relativos, confianza por acuerdo; revisión fila por fila antes de confirmar por la capa de operaciones.
- Seguimiento de reunión en reunión: acuerdos abiertos de reuniones anteriores en cada acta, revisión con estado y nota, tabla de revisiones en el acta de la reunión en que se hicieron.
- Actas descargables en LaTeX y Word con la identidad del sitio; eventos del calendario integrado (reunión, plazo de acuerdo); aviso diario de acuerdos vencidos por correo (`gdp_meetings_recipients`); tarjeta en la ficha del proyecto; reuniones y acuerdos como entidades enlazables.
- Operaciones en dos tiempos `meeting` (create, update, delete, set_status, set_attendees, add_agreements, propose_from_text, update_agreement, delete_agreement, review_agreement, agreement_to_activity) y herramientas del conector `list-meetings`, `get-meeting`, `list-agreements`, `propose-meeting-change`.
- Pantallas Reuniones (lista, formulario con asistentes, acta con acuerdos, propuesta desde texto y seguimiento) y Acuerdos del proyecto con filtros; tablas `gdp_meetings`, `gdp_meeting_attendees`, `gdp_agreements` (esquema 6); pruebas unitarias de la propuesta de acuerdos.

## [0.4.0] - 2026-10-01

### Añadido
- Módulo de adquisiciones y presupuesto (etapa 1): compras con código correlativo, partida, proveedor, responsable, actividad, moneda (pesos, unidades de fomento, dólares), neto, impuesto y total, convertidas a pesos con el valor de la unidad de fomento de la fecha de referencia; ciclo por etapas del catálogo (solicitud de cotización, cotización recibida, seguimiento, elección, solicitud interna, orden de compra, factura, pago) con historial de fecha, responsable, nota y documento; estados abierta, cerrada y anulada; aprobación formal reservada a `procurement.approve`.
- Proveedores globales o del proyecto con contacto y categoría; cotizaciones por compra (solicitada, recibida, elegida, descartada) con ítems, subconjunto a contratar y comparador por ítem; elegir una cotización copia proveedor, moneda y monto a la compra.
- Comprobaciones al emitir la orden: aprobación, partida y saldo, antigüedad de la cotización en unidades de fomento (reajuste) y valor implícito de la orden frente al oficial con tolerancia configurable; una discrepancia es un conflicto que impide confirmar.
- Presupuesto por partida: asignado (`gdp_budget_lines`), comprometido (orden emitida), ejecutado (pagada), pendiente y saldo, con aviso cuando la suma de partidas supera el presupuesto del proyecto.
- Valores diarios de la unidad de fomento (`gdp_uf_rates`) cargados a mano u obtenidos de mindicador.cl, con tarea diaria; herramienta `get-uf-rate`.
- Avisos diarios de compras con cotizaciones recibidas sin decidir y de cotizaciones solicitadas sin respuesta del proveedor; eventos del calendario integrado (entrega esperada, vencimiento de cotización).
- Exportación para la rendición en CSV y XLSX (hojas Rendición y Presupuesto).
- Operaciones en dos tiempos `purchase` (create, update, delete, set_stage, approve, add_quote, update_quote, delete_quote, choose_quote, set_quote_items, create_supplier, update_supplier, delete_supplier, set_budget) y herramientas del conector `list-purchases`, `get-purchase`, `list-suppliers`, `get-budget`, `get-uf-rate`, `propose-purchase-change`; los montos se ocultan a quien no tenga `procurement.view_amounts`.
- Pantallas Compras (lista, formulario, ficha con cotizaciones, etapas y comprobaciones), Proveedores y Presupuesto (partidas, unidad de fomento, reglas del módulo); tarjeta en la ficha del proyecto; las compras son entidades enlazables.
- Partidas presupuestarias por defecto en el catálogo (recursos humanos, gastos de operación, equipamiento, infraestructura, difusión y transferencia, gastos de administración).
- Tablas `gdp_suppliers`, `gdp_purchases`, `gdp_purchase_stages`, `gdp_quotes`, `gdp_quote_items`, `gdp_budget_lines`, `gdp_uf_rates` (esquema 5); pruebas unitarias de la aritmética de la unidad de fomento; `OperationManager::execute()` devuelve también la vista previa.

## [0.3.0] - 2026-10-01

### Añadido
- Módulo de control documental (etapa 1): documentos por tipo del catálogo (carta, oficio, contrato, orden de compra, cotización, factura, acta, informe, otro) con sentido, número, fecha, emisor, destinatario, asunto, cuerpo, estado (borrador, enviado, recibido, respondido, aprobado, cerrado, anulado), plazo de respuesta, responsable, actividad relacionada y notas; control optimista de versión.
- Numeración correlativa automática por tipo numerado, con patrón configurable por proyecto (`{PREFIJO}-{NNN}/{AAAA}` por defecto, correlativo anual cuando el patrón lleva el año) y prefijos derivados del catálogo.
- Versiones de archivo en el directorio privado (`documentos/<proyecto>/<documento>/`), con huella SHA-256, nota y entrega controlada por `documents.view`; al eliminar un documento los archivos se apartan y vuelven al restaurarlo.
- Tablas genéricas `gdp_links` (vínculos entre entidades con relación: responde a, se refiere a, respalda, se relaciona con) y `gdp_external_refs` (número, estado y enlace en sistemas institucionales); los módulos declaran sus entidades enlazables con el filtro `gdp_link_entities`.
- Plazos de respuesta: estado vencido o por vencer en la lista, la ficha del proyecto y el calendario integrado (eventos "Respuesta documental" y "Documento" por `gdp_calendar_events`); revisión diaria con aviso por correo a directores e ingenieros (`gdp_documents_recipients`).
- Borradores de carta en LaTeX (clase `letter`, babel español) y en Word (.docx escrito sin bibliotecas) a partir de los datos del documento y la identidad del sitio.
- Operaciones en dos tiempos `document`: create, update, delete (papelera), set_status (la aprobación exige `documents.approve`; "respondido" puede enlazar el documento de respuesta), link, unlink, set_external_ref, remove_external_ref.
- Herramientas del conector `list-documents`, `get-document` y `propose-document-change`.
- Pantallas: lista con filtros y resumen de vencimientos, formulario con subida inicial, ficha con versiones, vínculos, referencias externas e historial, patrón de numeración por proyecto; tarjeta en la ficha del proyecto.
- Tablas `gdp_documents`, `gdp_document_versions`, `gdp_links`, `gdp_external_refs` (esquema 4); pruebas unitarias de numeración y escapado LaTeX.

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
