=== Gestión de Proyectos ===
Contributors: girebz
Tags: gestión de proyectos, investigación, mcp, planificación, rendición de cuentas
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestión integral de proyectos de investigación y desarrollo con financiamiento externo, con conector para asistentes de inteligencia artificial.

== Description ==

Gestión de Proyectos organiza, dentro del propio sitio, la gestión de proyectos de investigación y desarrollo financiados por entidades externas: planificación y control del tiempo, control documental, adquisiciones y presupuesto, reuniones y acuerdos, muestras y ensayos de laboratorio, evidencias para acreditación y rendición, publicaciones y difusión, y exportación de datos en formatos abiertos.

El plugin no trae marca propia: adopta el nombre, el logotipo, la paleta y la tipografía del sitio que lo aloja.

Incluye un conector basado en la API de habilidades de WordPress y el adaptador MCP oficial, con el que un asistente como Claude puede consultar los datos del proyecto y proponer cambios. Ninguna escritura se aplica sin la confirmación de una persona: el asistente propone, el equipo revisa la vista previa y confirma.

= Versión 0.1.0 =

Primera versión: núcleo del sistema (proyectos, equipo con perfiles por proyecto, catálogos, bitácora de auditoría, capa de operaciones en dos tiempos, almacenamiento privado), conector con herramientas de consulta y propuesta, y asistente de integración paso a paso. Los módulos de planificación, documentos, adquisiciones, reuniones, datos, laboratorio, evidencias y publicaciones se incorporan en versiones siguientes.

== Installation ==

1. Suba el ZIP desde Plugins → Añadir nuevo → Subir plugin y actívelo.
2. Para el conector, instale y active el plugin oficial "MCP Adapter" (WordPress.org Contributors).
3. Abra Proyectos → Conector y siga el asistente de integración.

= Rendición de cuentas =

En Proyectos → Finanzas registre el convenio, las cuotas, los ítems con su asignado por fuente y los pagos; cada mes, la rendición agrupa los pagos y entrega la planilla y el ZIP de carga masiva para SISREC. El estado de cuentas muestra cuánto falta pagar y rendir para la cuota siguiente y el asistente abre una hoja de ejecución por tarea, con la pantalla de la plataforma y cada valor listo para copiar. El Fondo y el aporte pecuniario de la institución se muestran siempre por separado. En Proyectos → Grupos el administrador crea perfiles a medida, por ejemplo uno que ve todo salvo las finanzas.

= Tableros en el sitio =

Para publicar el avance, configure en Proyectos → Tableros qué se muestra y active la publicación; luego inserte en la portada el código corto [gdp_avance proyecto="CODIGO"]. El tablero del equipo se inserta con [gdp_tablero_equipo proyecto="CODIGO"] en un tema del foro o en una página privada y solo lo ven usuarios con sesión iniciada. Para la página privada del equipo hay además [gdp_gantt], [gdp_kanban], [gdp_calendario], [gdp_alertas], [gdp_carga] e [gdp_informe_semanal], con el mismo atributo proyecto y la misma regla de acceso, y el tablero de finanzas [gdp_finanzas], que además exige el permiso de ver las finanzas.

== Frequently Asked Questions ==

= ¿Necesita el plugin MCP Adapter? =

Solo para el conector con asistentes. El resto del plugin funciona sin él.

= ¿Qué puede hacer el asistente sin supervisión? =

Consultar. Toda escritura queda como propuesta hasta que una persona con permiso la confirma, y cada llamada queda en la bitácora.

= ¿Qué muestra el tablero público? =

Solo lo que se marque como publicable en Proyectos → Tableros: título y resumen, avance y plazo transcurrido, indicadores, etapas con nombre público, hitos destacados, instituciones y un llamado a la acción con el correo de contacto ofuscado. Nunca muestra montos, nombres de personas, compras ni documentos.

= ¿Quién ve el tablero del equipo? =

Usuarios con sesión iniciada en el sitio (si el registro está cerrado, solo los que usted registre a mano) o, si así se configura, solo los miembros del proyecto. Los montos aparecen únicamente a quien tiene permiso para verlos. A los visitantes se les pide iniciar sesión.

= ¿Puede el equipo ver la carta Gantt o el calendario sin entrar al panel? =

Sí: [gdp_gantt], [gdp_kanban], [gdp_calendario], [gdp_alertas], [gdp_carga] e [gdp_informe_semanal] muestran esas vistas en modo lectura en cualquier página, entrada o tema de foro, solo a usuarios con sesión iniciada (y, si así se configura, solo a miembros del proyecto). Quien puede entrar al panel conserva los enlaces hacia él. La pestaña Tablero del equipo de Proyectos → Tableros entrega los códigos listos para copiar.

= El código corto aparece como texto en un mensaje del foro (wpForo) =

Active "Enable WordPress Shortcodes in Post Content" en Foros → Ajustes → pestaña Features ("Características"). Hasta entonces wpForo no interpreta ningún código corto dentro de los mensajes.

= ¿El plugin se conecta con SISREC? =

No. SISREC no ofrece una interfaz para otros sistemas: el plugin prepara los datos (planilla de carga masiva, carpetas de respaldos, valores de cada pantalla listos para copiar) y usted declara cada estado de la rendición con su fecha y el documento que lo prueba. El estado de cuentas se calcula con lo declarado.

= ¿Quién ve las finanzas? =

Quien tenga el permiso "Ver finanzas y rendición de cuentas" en el proyecto: por omisión, el director y el ingeniero de proyectos. Con un grupo a medida se puede dar lectura de todo, finanzas incluidas, sin permisos de edición, o todo salvo las finanzas; el editor de grupos advierte si un grupo sin finanzas conserva los montos de las compras.

= ¿Puede el director ver las finanzas sin entrar al panel? =

Sí: [gdp_finanzas proyecto="CODIGO"] muestra en una página privada el estado financiero completo en modo lectura, en nueve pestañas: un resumen con alertas, saldo de caja, plazo frente a ejecución, cuota siguiente y gráficos; el detalle de cuotas, ítems, pagos, rendiciones, caja y convenio; el paso a paso de cada trámite de rendición en SISREC con los valores listos para copiar; y reportes (informe imprimible, texto para informes y planillas CSV). Un menú de ayuda lleva de la tarea a su hoja de ejecución. Solo lo ve quien tiene el permiso de ver las finanzas del proyecto; los demás reciben un aviso sin cifras. Con vista="paso" (u otra pestaña) la página muestra solo esa parte.

= ¿Dónde se guardan los adjuntos? =

En un directorio privado dentro de wp-content/uploads, protegido contra acceso web directo y servido solo a usuarios autorizados.

== Changelog ==

= 0.10.0 =
* Novedad: tablero de finanzas en el sitio con [gdp_finanzas proyecto="CODIGO"], en modo lectura y solo para quien puede ver las finanzas: resumen para el director con alertas y gráficos (uso de cada fuente, avance de las cuotas, ejecución por ítem, curva de caja y franja de rendiciones), detalle de cuotas, ítems, pagos, rendiciones, caja y convenio, paso a paso de la rendición en SISREC, reportes (informe imprimible, texto para informes, planillas CSV) y menú de ayuda por tareas. El atributo vista deja una sola pestaña. La pestaña Tablero del equipo de Proyectos → Tableros entrega los códigos.

= 0.9.1 =
* Corrección: la exportación por proyecto ofrece el módulo de finanzas (antes quedaba fuera de toda exportación y respaldo por proyecto). Exportar las tablas de finanzas exige el permiso de exportar finanzas, e importarlas, el de registrar pagos y rendiciones.
* Seguridad: importar el equipo exige gestionar los miembros; los grupos de permisos, administrar el plugin; y el registro del proyecto, editarlo. Sin esos permisos esas tablas se omiten con aviso, y al confirmar se respetan las omisiones de la vista previa. Exportar la bitácora exige verla.

= 0.9.0 =
* Finanzas y rendición de cuentas: convenio, cuotas, ítems por fuente, pagos, rendiciones con estados declarados, garantías y modificaciones; estado de cuentas con el Fondo y el aporte pecuniario por separado; brecha y condiciones de la cuota siguiente; asistente con hojas de ejecución para SISREC; programación de caja con seis controles; conciliación con la cartola; planilla y ZIP de carga masiva, expediente, carta sin movimiento y ficha de giro; herramientas del conector. Grupos de permisos a medida.

= 0.8.0 =
* Códigos cortos para la página privada del equipo: carta Gantt, tablero de tarjetas, calendario, alertas, carga de trabajo e informe semanal, en modo lectura y solo para usuarios con sesión iniciada; vistas compartidas entre el panel y el sitio; alineación de las barras de la carta Gantt con sus filas.

= 0.7.3 =
* Códigos cortos dentro de foros: atributos con comillas convertidas en entidades o tipográficas, colores del sitio también en la hoja de estilos, indicaciones para wpForo.

= 0.7.2 =
* Indicaciones actualizadas para el tema Relave Circular 1.4.0 (área de widgets de la portada).

= 0.7.1 =
* Interfaz para temas: funciones gdp_dashboard_data(), gdp_dashboard() y gdp_parse_dashboard_shortcode(), hitos destacados por etapa en el tablero público y filtro gdp_site_identity para declarar la paleta del tema.

= 0.7.0 =
* Tableros: tablero público de difusión con el código corto [gdp_avance] (mensaje, avance, plazo, indicadores, etapas, logros, próximos hitos, instituciones y contacto con correo ofuscado) que solo muestra lo declarado publicable; tablero del equipo con [gdp_tablero_equipo] (avance real y planificado, atrasos, vencimientos, ruta crítica, acuerdos, respuestas pendientes, compras, próxima reunión) para usuarios con sesión iniciada; pantalla Tableros con vista previa; herramienta del conector get-dashboard.

= 0.6.0 =
* Exportación, importación y respaldo: exportación por módulos en JSON, ZIP con adjuntos, XLSX y CSV con referencias resueltas y diccionario de datos (variante anonimizada); importación con vista previa por tabla, conflictos por versión, aplicación parcial y reversión; respaldos manuales y semanales con restauración reversible; archivo de ejemplo importable; herramientas del conector.

= 0.5.0 =
* Reuniones y acuerdos: actas con asistentes, temas, resumen y transcripción; acuerdos con responsable, plazo y estado, convertibles en actividades del cronograma; propuesta automática de acuerdos desde un resumen o transcripción con revisión antes de confirmar; seguimiento de reunión en reunión; actas en LaTeX y Word; aviso de acuerdos vencidos; herramientas del conector.

= 0.4.0 =
* Adquisiciones y presupuesto: ciclo de compra por etapas con historial, proveedores, cotizaciones con ítems y comparación, elección y aprobación, orden con comprobación de reajuste en unidades de fomento, presupuesto por partida, valores diarios de la unidad de fomento y rendición en Excel y CSV.

= 0.3.0 =
* Control documental: documentos por tipo con numeración correlativa configurable, versiones de archivo en el directorio privado, vínculos con actividades y otros documentos, plazos de respuesta con alerta y aviso por correo, referencias en sistemas externos, borradores de carta en LaTeX y Word, papelera y herramientas del conector.

= 0.2.0 =
* Planificación y tiempo: estructura de desglose, cronograma por ruta crítica con dependencias y restricciones, calendarios con feriados de Chile, carta Gantt interactiva, tableros por estado, frente o persona, calendario con suscripción iCalendar, carga de trabajo, curva S, líneas base, papelera, alertas e informe semanal exportable (LaTeX, CSV, XLSX, JSON, iCalendar); exportación a XML de Microsoft Project e importación desde CSV, XLSX y Project.
* Conector: herramientas de cronograma, ruta crítica, alertas, informe semanal, líneas base, calendario y propuestas de cambio sobre actividades, incluida la importación de estructuras completas.
* Control documental: cartas, oficios, contratos y órdenes con numeración correlativa, versiones, vínculos cruzados, plazos de respuesta, referencias externas y borradores de carta en LaTeX y Word.
* Adquisiciones y presupuesto: ciclo de compra por etapas, proveedores, cotizaciones comparables por ítem, aprobación, orden con comprobación del valor de la unidad de fomento, presupuesto por partida (asignado, comprometido, ejecutado, saldo) y rendición exportable.
* Seguridad: exigencia de doble factor delegada en Two Factor, WP 2FA o Wordfence Login Security para los perfiles con acceso a documentos y montos, comprobada por el diagnóstico.

= 0.1.0 =
* Núcleo: proyectos, miembros con perfiles por proyecto, catálogos, bitácora, operaciones en dos tiempos, almacenamiento privado, identidad del sitio.
* Conector: habilidades de WordPress, servidor MCP propio, tokens por usuario, diagnóstico y asistente de integración.
