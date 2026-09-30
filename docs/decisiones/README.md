# Bitácora de decisiones de arquitectura

Cada decisión estructural se registra en un archivo numerado con el formato: contexto, decisión, consecuencias y estado. Una decisión no se edita una vez aceptada; si cambia, se escribe una nueva que la sustituye y se enlazan ambas. Así, cualquier sesión de desarrollo (con cualquier modelo o persona) recupera las razones sin depender de la memoria de una conversación.

| Número | Decisión | Estado |
|---|---|---|
| [0001](0001-plataforma-wordpress-y-conector-mcp.md) | Plugin de WordPress con conector sobre la API de habilidades y el adaptador MCP oficial | aceptada |
| [0002](0002-multiproyecto-desde-el-inicio.md) | Varios proyectos por instalación desde el primer commit | aceptada |
| [0003](0003-capa-unica-de-operaciones.md) | Capa única de operaciones en dos tiempos para toda escritura externa | aceptada |
| [0004](0004-identidad-del-sitio-anfitrion.md) | El plugin adopta la identidad visual del sitio que lo aloja | aceptada |
| [0005](0005-nomenclatura-y-prefijos.md) | Nombre, prefijos, espacio de nombres y dominio de traducción | aceptada |
| [0006](0006-autenticacion-del-conector-por-token.md) | Tokens por usuario con cabecera fija en lugar de OAuth | aceptada |
| [0007](0007-adjuntos-en-directorio-privado.md) | Adjuntos fuera de la carpeta pública con entrega controlada | aceptada |
| [0008](0008-autocarga-y-empaquetado.md) | Autocarga PSR-4 propia y paquete instalable sin Composer en el sitio | aceptada |
| [0009](0009-control-optimista-de-version.md) | Control optimista de versión en todos los registros | aceptada |
| [0010](0010-flujo-de-desarrollo-y-repositorio.md) | Repositorio, ramas, integración continua y versiones | aceptada |
| [0011](0011-convenciones-del-motor-de-programacion.md) | Convenciones del motor de programación: días hábiles, hitos, restricciones, fechas reales, feriados | aceptada |
