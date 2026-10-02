=== Gestión de Proyectos ===
Contributors: girebz
Tags: gestión de proyectos, investigación, mcp, planificación, control documental
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.7.3
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

= Tableros en el sitio =

Para publicar el avance, configure en Proyectos → Tableros qué se muestra y active la publicación; luego inserte en la portada el código corto [gdp_avance proyecto="CODIGO"]. El tablero del equipo se inserta con [gdp_tablero_equipo proyecto="CODIGO"] en un tema del foro o en una página privada y solo lo ven usuarios con sesión iniciada.

== Frequently Asked Questions ==

= ¿Necesita el plugin MCP Adapter? =

Solo para el conector con asistentes. El resto del plugin funciona sin él.

= ¿Qué puede hacer el asistente sin supervisión? =

Consultar. Toda escritura queda como propuesta hasta que una persona con permiso la confirma, y cada llamada queda en la bitácora.

= ¿Qué muestra el tablero público? =

Solo lo que se marque como publicable en Proyectos → Tableros: título y resumen, avance y plazo transcurrido, indicadores, etapas con nombre público, hitos destacados, instituciones y un llamado a la acción con el correo de contacto ofuscado. Nunca muestra montos, nombres de personas, compras ni documentos.

= ¿Quién ve el tablero del equipo? =

Usuarios con sesión iniciada en el sitio (si el registro está cerrado, solo los que usted registre a mano) o, si así se configura, solo los miembros del proyecto. Los montos aparecen únicamente a quien tiene permiso para verlos. A los visitantes se les pide iniciar sesión.

= El código corto aparece como texto en un mensaje del foro (wpForo) =

Active "Enable WordPress Shortcodes in Post Content" en Foros → Ajustes → pestaña Features ("Características"). Hasta entonces wpForo no interpreta ningún código corto dentro de los mensajes.

= ¿Dónde se guardan los adjuntos? =

En un directorio privado dentro de wp-content/uploads, protegido contra acceso web directo y servido solo a usuarios autorizados.

== Changelog ==

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
