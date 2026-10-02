# Gestión de Proyectos

Plugin de WordPress para la gestión integral de proyectos de investigación y desarrollo con financiamiento externo. Nació para el proyecto *Valorización de Relaves Abandonados en Coquimbo* (Universidad Central de Chile, Gobierno Regional de Coquimbo) y está diseñado como herramienta general: varios proyectos por instalación, vocabulario configurable, módulos activables por proyecto e identidad visual tomada del sitio que lo aloja.

## Qué hace

| Módulo | Estado | Contenido |
|---|---|---|
| Proyectos y equipo | disponible (0.1.0) | Ficha del proyecto, perfiles por proyecto (director, ingeniero, investigador, apoyo, observador) y grupos de permisos a medida con advertencias de filtración (0.9.0), catálogos, bitácora de auditoría, doble factor exigible delegado en Two Factor, WP 2FA o Wordfence (0.2.0) |
| Operaciones en dos tiempos | disponible (0.1.0) | Toda escritura externa (conector, importación) se propone con vista previa y se aplica solo al confirmarla; reversible |
| Conector para asistentes | disponible (0.1.0) | Herramientas MCP sobre la API de habilidades de WordPress, tokens por usuario, diagnóstico y asistente de integración |
| Planificación y tiempo | disponible (0.2.0) | Estructura de desglose, cronograma con dependencias y restricciones, ruta crítica, carta Gantt interactiva (dependencias con el ratón, zoom hasta trimestre, impresión), tableros por estado, frente o persona, calendario integrado con suscripción iCalendar, carga de trabajo con sobreasignación, curva S, calendarios con feriados de Chile, líneas base con alerta de aprobación del financiador, papelera, informe semanal con comparación semanal (LaTeX, CSV, XLSX, JSON, iCalendar), exportación a XML de Microsoft Project e importación desde CSV, XLSX y Project; valor ganado en etapa 2 |
| Control documental | disponible (0.3.0) | Documentos por tipo con numeración correlativa configurable, versiones de archivo privadas, vínculos con actividades y otros documentos, plazos de respuesta con alerta y aviso por correo, referencias en sistemas externos, borradores de carta en LaTeX y Word, papelera y herramientas del conector |
| Adquisiciones y presupuesto | disponible (0.4.0) | Ciclo de compra por etapas con historial, proveedores, cotizaciones con ítems y comparación, elección y aprobación, orden con comprobación de reajuste y valor implícito de la unidad de fomento, presupuesto por partida (asignado, comprometido, ejecutado, saldo), valores diarios de la unidad de fomento, avisos de decisión pendiente, rendición en Excel y CSV, herramientas del conector |
| Reuniones y acuerdos | disponible (0.5.0) | Actas con asistentes y acuerdos, acuerdos con responsable, plazo y estado convertibles en actividades, propuesta automática de acuerdos desde un resumen o transcripción con revisión antes de confirmar, seguimiento de reunión en reunión, actas en LaTeX y Word, aviso de acuerdos vencidos, herramientas del conector |
| Tableros público y del equipo | disponible (0.7.0) | Tablero de difusión para la portada del sitio con el código corto `[gdp_avance]` (mensaje, avance y plazo, indicadores, etapas, logros y próximos hitos, instituciones, llamado a la acción con correo ofuscado) que solo muestra lo declarado publicable; tablero de gestión para el equipo con `[gdp_tablero_equipo]` (avance real y planificado, atrasos, vencimientos, ruta crítica, acuerdos, respuestas pendientes, compras, próxima reunión), reservado a usuarios con sesión iniciada y con montos solo para quien puede verlos; vistas de planificación para la página del equipo con `[gdp_gantt]`, `[gdp_kanban]`, `[gdp_calendario]`, `[gdp_alertas]`, `[gdp_carga]` e `[gdp_informe_semanal]` (0.8.0); configuración con vista previa; herramienta del conector |
| Exportación, importación y respaldo | disponible (0.6.0) | Exportación por módulos en JSON, ZIP con adjuntos, XLSX y CSV con referencias resueltas y diccionario de datos, variante anonimizada; importación con reconocimiento por clave natural, vista previa por tabla, conflictos por versión, aplicación parcial y reversión; respaldos manuales y semanales con restauración reversible; archivo de ejemplo del proyecto de relaves; herramientas del conector |
| Finanzas y rendición de cuentas | disponible (0.9.0) | Convenio, cuotas, ítems con asignado por fuente, pagos como unidad de rendición, rendiciones con estados declarados y plazos, garantías y modificaciones; estado de cuentas con el Fondo y el aporte pecuniario por separado; cuánto pagar y rendir, en qué ítems y antes de qué fecha para la cuota siguiente, con las siete condiciones de giro; asistente con hojas de ejecución para SISREC (valores listos para copiar); programación de caja con seis controles; conciliación con la cartola del centro de costo; planilla y ZIP de carga masiva, expediente, carta sin movimiento y ficha de giro; perfiles de fondo con reglas, fuente y vigencia; herramientas del conector |
| Muestras, ensayos, mezclas y probetas | etapa 2 | Cadena de custodia, resultados como datos, umbrales, dosificaciones, resistencia |
| Evidencias para acreditación y rendición | etapa 3 | Criterios, medios de verificación, carpetas de evidencia, informes |
| Publicaciones y difusión | etapa 3 | Artículos, ponencias, difusión, borradores para el blog |

La especificación funcional completa está en [`docs/especificacion/`](docs/especificacion/) (LaTeX) y las decisiones de arquitectura en [`docs/decisiones/`](docs/decisiones/).

## Requisitos

- WordPress 6.9 o superior (la API de habilidades forma parte del núcleo desde esa versión).
- PHP 8.1 o superior.
- Para el conector: el plugin oficial [MCP Adapter](https://github.com/WordPress/mcp-adapter) y un sitio servido por HTTPS.

## Instalación

1. Descargue el ZIP de la [última versión](../../releases) (o constrúyalo con `bash bin/build-zip.sh`).
2. En WordPress: Plugins → Añadir nuevo → Subir plugin → Activar.
3. Para el conector: instale y active MCP Adapter, luego abra **Proyectos → Conector** y siga el asistente paso a paso (diagnóstico, credencial, configuración en Claude, prueba, mantenimiento).

## Tableros en el sitio, en breve

1. **Proyectos → Tableros**: elija qué se publica (título, resumen, etapas con nombre público, hitos destacados, indicadores, instituciones, correo de contacto) y marque **Publicar el tablero en el sitio**. La vista previa muestra el resultado con la identidad del sitio.
2. En la portada o en cualquier página, inserte `[gdp_avance proyecto="CODIGO"]`; con `bloques="portada,indicadores,contacto"` se muestran solo esos bloques, en ese orden.
3. Para el equipo, inserte `[gdp_tablero_equipo proyecto="CODIGO"]` en un tema del foro o en una página privada. Solo lo ven usuarios con sesión iniciada (opcionalmente, solo miembros del proyecto); los montos aparecen únicamente a quien tiene permiso para verlos.
4. En esa misma página privada (o en el foro) pueden ir las vistas de planificación, con el mismo atributo `proyecto` y la misma regla de acceso: `[gdp_gantt]` (carta Gantt), `[gdp_kanban]` (tablero de tarjetas), `[gdp_calendario]`, `[gdp_alertas]`, `[gdp_carga]` (carga de trabajo) e `[gdp_informe_semanal]`. Son las mismas vistas de la pantalla de planificación en modo lectura: el calendario se navega por mes, semana o agenda, el informe por semana y la carga por periodo (parámetros `gdp_` en la dirección de la página); quien puede entrar al panel conserva los enlaces hacia él y quien tiene permiso para ver la planificación conserva las descargas del informe. La carta Gantt y el tablero de una misma página deben ser del mismo proyecto. La pestaña Tablero del equipo de Proyectos → Tableros entrega los seis códigos listos para copiar; si el tema del sitio tiene estilos muy marcados, la presentación se afina con reglas CSS sobre el contenedor `.gdp-front`.

### El tablero del equipo en wpForo

wpForo no interpreta códigos cortos dentro de los mensajes mientras no se active la opción **Enable WordPress Shortcodes in Post Content** (escritorio → Foros → Ajustes → pestaña *Features*, "Características" en la traducción); hasta entonces, el mensaje muestra el código tal cual. Una vez activada:

1. Cree un tema (por ejemplo, "Tablero del equipo") en un foro visible solo para los usuarios registrados y pegue en el mensaje el código corto exacto que entrega Proyectos → Tableros → Tablero del equipo. El plugin admite las comillas que el editor del foro pueda convertir en entidades o en comillas tipográficas, y el proyecto puede indicarse por código o por identificador.
2. El tablero comprueba la sesión por su cuenta: un visitante sin sesión ve la invitación a iniciar sesión aunque llegue al tema.
3. Los colores del sitio se aplican también desde la hoja de estilos del plugin, de modo que el tablero conserva su aspecto aunque el foro elimine atributos del HTML; el tablero del equipo no usa elementos que los filtros de HTML habituales descarten.
4. Si wpForo tiene activada su caché de HTML, el tablero se renueva cuando esa caché expira; sin ella, en cuanto cambia el proyecto (la caché del plugin se invalida con cada cambio registrado).

Alternativa sin esa opción: una página privada de WordPress con el código corto, enlazada desde el menú del foro (Foros → Menú).

Los datos se actualizan solos con cada cambio registrado en el proyecto; el tablero público no muestra nunca montos, nombres de personas, compras ni documentos.

### Integración en un tema

Un tema puede presentar el tablero con su propio diseño en lugar de insertar el código corto. El plugin expone funciones globales (comprobables con `function_exists`):

- `gdp_dashboard_data( $proyecto, 'public' )`: datos del tablero público (título, resumen, avance, plazo, indicadores, etapas con sus hitos destacados, logros, próximos hitos, instituciones, contacto) y la clave `enabled`, que el tema debe respetar; con `'team'`, el resumen de gestión para el usuario actual si tiene acceso.
- `gdp_dashboard( $proyecto, $bloques )`: el HTML del tablero público, igual al del código corto; `gdp_dashboard_html( $datos, $bloques )` para datos ya obtenidos.
- `gdp_parse_dashboard_shortcode( $texto )`: interpreta un código corto pegado por el usuario (por ejemplo, en el Personalizador) y devuelve el proyecto y los bloques.
- Filtro `gdp_site_identity` para declarar colores y tipografía cuando la paleta del tema no usa los nombres habituales de theme.json (primary, secondary, accent, base, contrast); las variables CSS `--gdp-dash-*` permiten además ajustar el tablero desde la hoja de estilos del tema.

El tema Relave Circular (1.4.0) usa esta interfaz: su portada tiene el área de widgets "Portada: avance del proyecto", donde el código corto va en un bloque "Shortcode" (tablero completo con los colores del tema) o se usa el widget del tema "Avance por etapas", que presenta las etapas del tablero como frentes de trabajo con el diseño de la portada.

## Rendición de cuentas, en breve

1. **Proyectos → Finanzas → Convenio**: registre otorgante, actos, fechas de ejecución, montos por fuente (Fondo, aporte pecuniario, aporte no pecuniario) y el código del proyecto en la plataforma; en **Ítems y reglas**, cree los ítems del perfil y fije el asignado vigente por fuente. Las reglas del perfil traen su fuente; una regla distinta para el proyecto (un criterio escrito de la contraparte, por ejemplo) se fija con su fuente y vigencia.
2. **Cuotas**: monto, ventana del programa de desembolso, informe que la habilita y aporte pecuniario; los hechos (informe aprobado, carta de solicitud, transferencia aceptada, comprobante enviado, aporte acreditado) se declaran con fecha y documento.
3. **Pagos**: cada documento pagado con su fuente, ítem, egreso y respaldos; el validador marca lo que bloquea la rendición y lo que falta. **Rendiciones**: una por mes (o sin movimiento), con los pagos del mes, la planilla y el ZIP de carga masiva, el expediente y la declaración de cada estado de SISREC.
4. **Estado de cuentas** responde cuánto falta pagar, rendir y aprobar para la cuota siguiente, el último mes de pago útil y qué compromisos cierran la brecha; **Asistente** ordena lo que hay que hacer y abre la hoja de ejecución de cada tarea, con la pantalla de la plataforma y cada valor listo para copiar.
5. **Caja**: la programación en el formato de la Dirección de Investigación con sus seis controles, y la cartola del centro de costo pegada para conciliar.

**Proyectos → Grupos** (solo administradores) crea perfiles a medida: por ejemplo, "Dirección" con todo en lectura, finanzas incluidas, y "Equipo" con todo en lectura salvo finanzas y montos. El Fondo y el aporte pecuniario de la universidad se muestran siempre por separado.

## Conector con Claude, en breve

1. **Proyectos → Conector → paso 2**: genere un token (alcance de solo lectura o de lectura y propuestas).
2. En Claude, **Conectores → Añadir conector personalizado**: pegue la URL `https://SU-SITIO/wp-json/gestion-de-proyectos/mcp` y añada la cabecera `X-GDP-Token` con el token. No requiere OAuth.
3. Pida a Claude: *"Consulta el estado del sistema con gestion-de-proyectos-system-status"* o *"Dame la ruta crítica del proyecto relaves-coquimbo y sus alertas de plazo"*.

Las herramientas de escritura solo **proponen**; los cambios se aplican con `confirm-operation` o desde **Proyectos → Operaciones**, y todo queda en la bitácora.

## Desarrollo

```bash
composer install            # dependencias de desarrollo (estándares, análisis, pruebas)
composer run lint           # sintaxis PHP
composer run phpcs          # estándares de codificación de WordPress
composer run test           # pruebas unitarias con PHPUnit
php tests/bin/run.php       # las mismas pruebas sin Composer (ejecutor mínimo)
composer run zip            # paquete instalable en build/
composer run make-pot       # plantilla de traducción languages/gestion-de-proyectos.pot (requiere WP-CLI)
```

Estructura:

```
gestion-de-proyectos.php   arranque, constantes, requisitos, ganchos de activación
src/Core                   esquema, instalador, roles y permisos, identidad, almacenamiento, cron, bitácora, catálogos
src/Domain                 repositorios por entidad (proyectos, miembros)
src/Planning               motor de programación puro: calendario laboral, feriados de Chile, ruta crítica
src/Operations             capa única de operaciones: proponer, previsualizar, confirmar, revertir
src/Connector              habilidades, herramientas, tokens, autenticación, diagnóstico, servidor MCP
src/Admin                  menú y pantallas del panel (incluidas las del módulo de planificación)
src/Modules                registro de módulos, hoja de ruta y módulos (Projects, Planning, Documents, Procurement, Meetings, Data, Dashboards y Finance: repositorios, servicios, manejadores, herramientas, cron; Finance/Logic reúne las identidades puras de la rendición y Finance/Profiles los perfiles de fondo)
assets/                    estilos y scripts del panel (carta Gantt y tablero propios, sin dependencias)
languages/                 plantilla de traducción (.pot); las traducciones .po/.mo van en esta misma carpeta
tests/                     pruebas unitarias del motor y ejecutor mínimo
docs/                      especificación (LaTeX) y decisiones de arquitectura
```

Convenciones: espacio de nombres `GDP\`, prefijo `gdp_` en tablas, opciones, ganchos y capacidades; dominio de traducción `gestion-de-proyectos` (los textos se escriben en español y la plantilla `.pot` permite traducir a otros idiomas; toda cadena con marcadores lleva su comentario `translators:`); estándares de codificación de WordPress; ningún secreto en el repositorio.

## Licencia

GPL-2.0-or-later. Véase [LICENSE](LICENSE).
