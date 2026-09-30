# 0003. Capa única de operaciones en dos tiempos para toda escritura externa

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

El conector permitiría a un asistente escribir datos, y la importación de JSON o planillas plantea el mismo riesgo: sobrescribir en silencio cambios hechos por otra persona entre la exportación y la importación. Ambos canales necesitan las mismas cinco salvaguardas: validación contra el esquema, detección de conflictos por versión, vista previa de diferencias, aplicación parcial y reversión.

## Decisión

Una sola capa (`GDP\Operations\OperationManager` con un `HandlerInterface` por entidad) implementa el ciclo proponer, previsualizar, confirmar, aplicar y revertir. El conector y la importación son dos puertas de entrada a la misma capa. Ninguna escritura externa se aplica sin confirmación humana; la confirmación revalida y recalcula la vista previa antes de aplicar. Las propuestas caducan (24 horas por defecto). Los formularios del panel escriben directamente a través de los repositorios, que anotan la bitácora.

## Consecuencias

- Cada módulo debe entregar un manejador con `validate`, `preview`, `apply` y `revert`; el patrón está en `Handlers\ProjectHandler`.
- Las herramientas de escritura del conector se llaman `propose-*` y devuelven `operation_id`; `confirm-operation` exige `confirm=true`.
- La bitácora enlaza cada entrada con su operación, lo que permite auditar y revertir por lotes.
