# 0009. Control optimista de versión en todos los registros

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

Dos personas (o una persona y un asistente) pueden modificar el mismo registro en paralelo; una importación puede basarse en datos exportados días antes.

## Decisión

Toda entidad lleva una columna `version` que se incrementa en cada escritura. Los repositorios aceptan `expected_version`: si no coincide con la vigente, la escritura se rechaza con `version_conflict`. Los formularios del panel envían la versión vista; las herramientas del conector reciben la versión con `get-*` y la devuelven en `propose-*`; la importación compara la versión de cada registro del archivo con la vigente y marca conflictos en la vista previa.

## Consecuencias

- Las vistas previas de operaciones muestran conflictos explícitos y no son confirmables mientras existan.
- El diccionario de datos documenta `version` como campo obligatorio en las importaciones.
