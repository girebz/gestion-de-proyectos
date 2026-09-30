# Gestión de Proyectos

Plugin de WordPress para la gestión integral de proyectos de investigación y desarrollo con financiamiento externo. Nació para el proyecto *Valorización de Relaves Abandonados en Coquimbo* (Universidad Central de Chile, Gobierno Regional de Coquimbo) y está diseñado como herramienta general: varios proyectos por instalación, vocabulario configurable, módulos activables por proyecto e identidad visual tomada del sitio que lo aloja.

## Qué hace

| Módulo | Estado | Contenido |
|---|---|---|
| Proyectos y equipo | disponible (0.1.0) | Ficha del proyecto, perfiles por proyecto (director, ingeniero, investigador, apoyo, observador), catálogos, bitácora de auditoría |
| Operaciones en dos tiempos | disponible (0.1.0) | Toda escritura externa (conector, importación) se propone con vista previa y se aplica solo al confirmarla; reversible |
| Conector para asistentes | disponible (0.1.0) | Herramientas MCP sobre la API de habilidades de WordPress, tokens por usuario, diagnóstico y asistente de integración |
| Planificación y tiempo | disponible (0.2.0) | Estructura de desglose, cronograma con dependencias y restricciones, ruta crítica, carta Gantt interactiva (dependencias con el ratón, zoom hasta trimestre, impresión), tableros por estado, frente o persona, calendario integrado con suscripción iCalendar, carga de trabajo con sobreasignación, curva S, calendarios con feriados de Chile, líneas base con alerta de aprobación del financiador, papelera, informe semanal con comparación semanal (LaTeX, CSV, XLSX, JSON, iCalendar), exportación a XML de Microsoft Project e importación desde CSV, XLSX y Project; valor ganado en etapa 2 |
| Control documental | etapa 1 | Cartas, oficios, contratos y órdenes con numeración, versiones, vínculos cruzados y plazos |
| Adquisiciones y presupuesto | etapa 1 | Ciclo de compra, proveedores, cotizaciones, partidas, unidades de fomento, rendición |
| Reuniones y acuerdos | etapa 1 | Actas, acuerdos convertidos en tareas, seguimiento |
| Exportación, importación y respaldo | etapa 1 | Respaldos, CSV/XLSX/JSON con diccionario de datos, importación con salvaguardas |
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
src/Modules                registro de módulos, hoja de ruta y módulos (Planning: repositorios, servicio, manejador, herramientas, cron, informe)
assets/                    estilos y scripts del panel (carta Gantt y tablero propios, sin dependencias)
tests/                     pruebas unitarias del motor y ejecutor mínimo
docs/                      especificación (LaTeX) y decisiones de arquitectura
```

Convenciones: espacio de nombres `GDP\`, prefijo `gdp_` en tablas, opciones, ganchos y capacidades; dominio de traducción `gestion-de-proyectos`; estándares de codificación de WordPress; ningún secreto en el repositorio.

## Licencia

GPL-2.0-or-later. Véase [LICENSE](LICENSE).
