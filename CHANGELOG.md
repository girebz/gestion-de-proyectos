# Registro de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/); versionado semántico.

## [Sin publicar]

## [0.10.0] - 2026-10-02

### Añadido
- Tablero de finanzas en el sitio con el código corto `[gdp_finanzas proyecto="CODIGO"]`, en modo lectura, para la página privada del equipo o un tema del foro. Nueve pestañas: **Resumen**, la vista integral del director (alertas por gravedad con enlace a su detalle, saldo de caja del Fondo como cifra principal, plazo transcurrido frente a lo pagado y a lo pagado o comprometido, cifras del Fondo, uso de cada fuente, cuota siguiente con sus brechas, garantía alternativa, último mes de pago útil y condiciones de giro, aporte de la universidad y próximas acciones); **Cuotas**, con los compromisos que cierran la brecha y la ficha de giro; **Ítems**, con asignado, pagado, comprometido, rechazado y disponible por fuente, topes de las bases, respaldos exigidos y pagos de cada ítem; **Pagos**, con filtros por estado, ítem, fuente y texto, el detalle de cada pago, sus respaldos y los hallazgos para la rendición; **Rendiciones**, mes a mes con plazos y días hábiles, y las registradas con sus pagos, estados declarados y documentos; **Caja**, con la programación frente a lo real, sus controles y la conciliación con la cartola; **Convenio**, con el financiamiento por fuente, modificaciones, garantías y reglas vigentes; **Paso a paso**, con las acciones pendientes, el ciclo mensual con los plazos del proyecto y las hojas de ejecución de SISREC con los valores listos para copiar y el selector de la rendición, cuota o proveedor; y **Reportes**, con el informe del estado financiero para imprimir o guardar en PDF, un texto redactado con las cifras del día para informes y correos, planillas CSV de pagos, ítems, cuotas y rendiciones, y los documentos que genera el módulo.
- Gráficos del tablero, sin bibliotecas externas y siempre con leyenda, nombre accesible y tabla equivalente: uso de cada fuente (pagado, comprometido, sin comprometer y marca de lo recibido), avance de cada cuota (aprobado, rendido, pagado y por pagar), ejecución por ítem, curva de caja del Fondo (transferido con la parte proyectada, gasto programado y pagado, con cruz de lectura por puntero y teclado) y franja de rendiciones mensuales con su estado. Los tonos salen del color principal del sitio; los estados van con símbolo y texto.
- Menú de ayuda del tablero: un selector de tareas (rendir el mes pendiente, ingresar o cargar una rendición, presentar una sin movimiento, corregir una devuelta, regularizar, solicitar la cuota, aceptar una transferencia, registrar un proveedor, programar la caja, cerrar el proyecto, preparar un informe, revisar compromisos u observados) que lleva a la hoja o pestaña con los datos ya resueltos; temas breves (cómo leer el tablero, ciclo mensual, qué falta para la cuota, estados de pagos y rendiciones, respaldos por ítem, qué reporte usar) y glosario; y una explicación breve junto a cada cifra.
- Atributo `vista` para mostrar una sola pestaña (`resumen`, `cuotas`, `items`, `pagos`, `rendiciones`, `caja`, `convenio`, `paso` o `reportes`); la página no ofrece enlaces a pestañas ausentes y su menú de ayuda solo lista las tareas que puede abrir.
- La pestaña Tablero del equipo de Proyectos → Tableros entrega los códigos del tablero de finanzas a quien puede ver las finanzas del proyecto.
- Decisión 0017 y criterios de aceptación en la especificación.

### Seguridad
- El tablero exige, además de la regla del tablero del equipo (sesión iniciada y, si se configura, pertenencia al proyecto), el permiso "Ver finanzas y rendición de cuentas" (`finance.view`); sin él solo se ve un aviso. Los documentos se ofrecen con el permiso que exige su descarga (ficha de giro, expediente y carta de gasto cero con `finance.view`; planilla de carga masiva, ZIP de respaldos y programación de caja con `finance.export`) y las etiquetas de los documentos de respaldo solo a quien puede abrirlos (`documents.view`). Sin sesión iniciada no se cargan los scripts del tablero.

## [0.9.1] - 2026-10-02

### Corregido
- La exportación por proyecto no ofrecía el módulo de finanzas: el formulario recorría una lista fija de módulos, de modo que las tablas del convenio, las cuotas, los pagos, las rendiciones y la programación de caja quedaban fuera de toda exportación y de los respaldos por proyecto sin aviso. Ahora se ofrece todo módulo que declare tablas, con su etiqueta (filtro nuevo `gdp_data_module_labels`) o con su identificador.

### Cambiado
- Mover las tablas de finanzas exige los permisos del módulo además de los de datos: exportarlas, "Exportar expedientes, planillas y fichas de giro" (`finance.export`), y escribirlas por importación, "Registrar pagos, rendiciones y estados" (`finance.edit`). Sin ellos, la casilla aparece deshabilitada, la herramienta del conector las excluye y la vista previa de la importación avisa que se omiten. Filtro nuevo `gdp_data_module_permissions` para que otros módulos declaren permisos propios.
- La advertencia del editor de grupos sobre "Exportar datos" sin finanzas indica ahora que lo expuesto son los montos de compras, cotizaciones y partidas.

### Seguridad
- La importación en un proyecto existente ya no permite cambiar lo que el panel reserva: el equipo exige "Gestionar los miembros" (`project.members`), los grupos de permisos, que son globales, exigen administrar el plugin, y el registro del proyecto exige "Editar el proyecto" (`project.edit`); sin esos permisos, esas tablas se omiten con aviso y el proyecto solo ubica el destino. Antes, un grupo a medida con "Importar datos" podía cambiar su propio perfil en el equipo o los permisos de su grupo con un archivo preparado. Los perfiles predefinidos no cambian: quienes importan (director e ingeniero de proyectos) tienen esos permisos.
- Al confirmar una importación se conservan las omisiones que mostró la vista previa, aunque confirme otra persona con más permisos.
- Exportar la bitácora exige "Ver la bitácora" (`audit.view`): guarda cada registro antes y después de cada cambio, con los montos de pagos, compras y partidas, y la dirección de origen. Antes bastaba "Exportar datos", que tiene el perfil de investigador. El editor de grupos advierte además cuando un grupo ve la bitácora sin ver las finanzas.

## [0.9.0] - 2026-10-02

### Añadido
- Módulo de finanzas y rendición de cuentas (pantalla Finanzas, con nueve pestañas): convenio con otorgante, actos, fechas, montos por fuente y datos del proyecto en la plataforma de rendición; cuotas del programa de desembolso con ventana, informe habilitante, aporte pecuniario asociado y fechas de solicitud, transferencia, ingreso, comprobante y aceptación; ítems con asignado vigente por fuente (Fondo, aporte pecuniario, aporte no pecuniario) y su proyección a la clasificación de la plataforma; pagos como unidad de rendición (fuente, ítem, compromiso, egreso, documento, cuota imputada, estado y respaldos exigidos por ítem); rendiciones por mes con sus estados declarados (de preparada a aprobada, aprobada parcialmente o devuelta) y plazos interno, de la plataforma y de subsanación; garantías; modificaciones del convenio con reitemizaciones que ajustan los asignados al aprobarse.
- Estado de cuentas a la fecha, siempre con el Fondo y el aporte pecuniario de la universidad por separado: transferido o enterado, pagado, rendido, aprobado, observado, comprometido, saldo de caja calculado, saldo de la cartola y diferencia; avance por cuota con imputación cronológica de los pagos; disponible por ítem y topes de personal y administración; línea de tiempo de las rendiciones con días hábiles restantes y atrasos; diferencia de cierre.
- Respuesta a "cuánto falta para la cuota siguiente": brechas de pago, rendición y aprobación, garantía alternativa, último mes de pago útil calculado hacia atrás desde la fecha objetivo del giro, fecha límite de facturación (regla nueva `plazo_pago_factura`), compromisos que cierran la brecha y las siete condiciones de giro con su detalle.
- Asistente de rendición: acciones ordenadas por severidad y vencimiento, y once guías del perfil del Fondo Regional para la Productividad y el Desarrollo de Coquimbo convertidas en hojas de ejecución, con la pantalla de SISREC, la indicación breve, cada valor ya en el formato que la pantalla pide y su botón de copiar, hojas por pago, pasos que se marcan con fecha, nota y documento, y selector de la rendición, cuota o proveedor cuando la hoja se abre sin ella.
- Perfiles de fondo con registro de reglas por proyecto (valor, fuente y vigencia; el convenio prevalece sobre el perfil), ítems, respaldos exigidos, guías y enlaces públicos; filtro `gdp_finance_profiles` para perfiles nuevos.
- Programación de caja en el formato de la Dirección de Investigación con seis controles (las dos sumas del formato, caja no negativa, condición de giro, ventana del convenio e ítems) medidos desde la línea base del primer mes del plan; conciliación con el centro de costo a partir de la cartola pegada, con emparejamiento automático por egreso, por grupo de pagos de un mismo egreso o por monto y fecha.
- Exportaciones: planilla de carga masiva (XLSX con las columnas del manual, sin tildes ni eñe), ZIP de respaldos con una carpeta por folio y sus subcarpetas CE y T, expediente imprimible, carta de rendición sin movimiento, ficha de giro y programación de caja con hoja de controles.
- Grupos de permisos a medida (pantalla Grupos, solo administradores): nombre, descripción y selección explícita de permisos por módulo, asignables a los miembros como los perfiles predefinidos, con advertencias de combinaciones que filtran información; cinco permisos nuevos (`finance.view`, `finance.edit`, `finance.reconcile`, `finance.export`, `finance.rules`), los tres de escritura sujetos al doble factor.
- Herramientas del conector `get-finance-status`, `get-next-installment`, `list-finance-renditions`, `list-finance-payments`, `get-finance-assistant` y `propose-finance-change`; eventos del calendario integrado (plazos de rendición, último pago útil, ventanas de cuota, comprobantes de ingreso, vencimiento de garantías); resumen diario de acciones y aviso por correo de las de severidad alta.
- Exportación e importación de las doce tablas del módulo y de los grupos de permisos, con descripciones de cada columna en el diccionario de datos; filtros `gdp_data_polymorphic`, `gdp_data_personal_columns` y `gdp_data_import_row` (reasignación de documentos guardados en JSON); pruebas unitarias de imputación, brechas, plazos, controles, condiciones, validación de pagos, carga masiva y emparejamiento de la cartola; decisión de arquitectura 0016.

### Cambiado
- Los perfiles de proyecto se resuelven con los grupos a medida (`Roles::permissions_map()`); los perfiles predefinidos conservan su nombre y prevalecen sobre un grupo con el mismo identificador. Director e ingeniero de proyectos reciben los permisos de finanzas; investigador, apoyo administrativo y observador no los tienen.
- El menú Finanzas solo aparece a quien puede ver las finanzas de al menos un proyecto; el selector de proyecto de las pantallas de planificación y finanzas ya no desborda en pantallas angostas.
- Esquema de base de datos versión 7 (trece tablas nuevas); la desinstalación con borrado de datos elimina también esas tablas y los resúmenes diarios del asistente.

## [0.8.0] - 2026-10-02

### Añadido
- Seis códigos cortos para la página privada del equipo (o los foros), reservados a usuarios con sesión iniciada: `[gdp_gantt]` (carta Gantt), `[gdp_kanban]` (tablero de tarjetas), `[gdp_calendario]`, `[gdp_alertas]`, `[gdp_carga]` (carga de trabajo) e `[gdp_informe_semanal]`, todos con el atributo `proyecto`. Muestran las mismas vistas de la pantalla de planificación en modo lectura, con la regla de acceso del tablero del equipo (sesión iniciada y, si se configura, pertenencia al proyecto); quien puede entrar al panel conserva los enlaces hacia él y quien tiene permiso para ver la planificación conserva las descargas del informe. La navegación (mes, semana, agenda, semana del informe, periodo de la carga) usa parámetros con prefijo `gdp_` sobre la dirección de la página, de modo que funciona en páginas, entradas y temas de foro.
- Hoja de estilos del sitio `assets/css/frontend.css` (botones, tablas y controles dentro de `.gdp-front`, con la identidad del sitio en variables CSS), tabla con los seis códigos en la pestaña Tablero del equipo de la pantalla Tableros y pruebas unitarias del contexto de presentación.

### Cambiado
- Las vistas de calendario, alertas, carga de trabajo, informe semanal, carta Gantt y tablero se extrajeron de las pantallas del panel a clases compartidas (`src/Modules/Planning/Views/`), parametrizadas por un contexto (`ViewContext`) que decide enlaces, parámetros, edición y descargas; las pantallas del panel las usan sin cambios visibles. Los datos y la configuración del script de la carta Gantt y el tablero viven en `CanvasView`.
- La carta Gantt mide la altura real de las filas de su tabla para alinear las barras con ellas (antes se desplazaban un píxel por fila); las tablas de alertas y de avance por frente se desplazan horizontalmente en pantallas angostas.

## [0.7.3] - 2026-10-01

### Cambiado
- Códigos cortos dentro de foros y editores: los atributos `proyecto` y `bloques` se aceptan con las comillas convertidas en entidades (`&quot;`) o en comillas tipográficas, como las guarda el editor de wpForo; los colores de la identidad del sitio se añaden también a la hoja de estilos del tablero (`wp_add_inline_style`), de modo que el tablero conserva su aspecto si el foro elimina el atributo `style` del HTML. Indicaciones sobre wpForo (opción "Enable WordPress Shortcodes in Post Content", caché) en la pantalla Tableros y el README.

## [0.7.2] - 2026-10-01

### Cambiado
- La pantalla Tableros y la documentación indican que, en el tema Relave Circular 1.4.0, el código corto va en el área de widgets "Portada: avance del proyecto" (bloque "Shortcode") o se usa el widget del tema "Avance por etapas".

## [0.7.1] - 2026-10-01

### Añadido
- Interfaz para temas (`src/template-functions.php`): `gdp_dashboard_data()` (datos del tablero público o del equipo), `gdp_dashboard()` (HTML igual al del código corto), `gdp_dashboard_html()`, `gdp_dashboard_blocks()`, `gdp_dashboard_project()` y `gdp_parse_dashboard_shortcode()` para interpretar un código corto pegado en el Personalizador de un tema.
- Cada etapa del tablero público incluye ahora sus hitos destacados (`highlights`, con código, texto público, fecha, estado y porcentaje), el total y los completados, de modo que un tema puede presentar el avance por etapa con su propio diseño.
- Filtro `gdp_site_identity`, aplicado antes de las correcciones manuales de los ajustes, para que un tema cuya paleta no usa los nombres habituales de theme.json declare sus colores y tipografía al plugin.

### Cambiado
- La clave de la caché de los tableros incluye la versión del plugin, para que una actualización no sirva datos con la forma anterior; la pantalla Tableros indica que el código corto puede pegarse en el Personalizador de un tema que integre el tablero (como Relave Circular 1.3.0).

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
