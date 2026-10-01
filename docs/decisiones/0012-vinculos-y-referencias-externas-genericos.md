# 0012. Vínculos y referencias externas genéricos

Fecha: 2026-10-01. Estado: aceptada.

## Contexto

Varios módulos necesitan relacionar sus registros entre sí (una carta responde a un oficio, respalda una compra, se refiere a una actividad; un acta genera acuerdos) y anotar cómo se identifica cada registro en los sistemas de la institución o del financiador (número de ingreso en el gestor documental, estado en el portal de compras). Modelar cada relación con una columna o una tabla propia multiplica el esquema y obliga a tocar varios módulos cada vez que aparece una relación nueva.

## Decisión

- **Una tabla de vínculos para todo el plugin.** `gdp_links` relaciona dos entidades cualesquiera identificadas por tipo e identificador (`from_type`, `from_id`, `to_type`, `to_id`) con una relación con nombre (`responds_to`, `refers_to`, `supports`, `related`) y una nota. Los vínculos se leen en ambos sentidos con etiquetas inversas ("responde a" / "es respondido por").
- **Una tabla de referencias externas para todo el plugin.** `gdp_external_refs` guarda, por entidad y por sistema, el número, el estado y el enlace en ese sistema; una entidad puede tener tantas referencias como sistemas, y la repetición del mismo sistema actualiza la referencia existente.
- **Registro de entidades por filtro.** Cada módulo declara sus entidades enlazables con `gdp_link_entities`: etiqueta, una función que resuelve identificador a título, código, enlace y proyecto, y una función de búsqueda para los formularios. Así, el módulo documental muestra con nombre un vínculo a una actividad sin conocer el módulo de planificación, y los módulos futuros (compras, reuniones) se incorporan sin cambiar el esquema.
- **Pertenencia al proyecto.** Un vínculo solo puede unir entidades del mismo proyecto; la comprobación usa el `project_id` que devuelve la resolución de la entidad.
- **Eliminación y restauración.** Al eliminar una entidad, sus vínculos y referencias se guardan en la instantánea de la operación y se reinsertan al restaurarla; al eliminar un proyecto se borran por `project_id`.

## Consecuencias

- Las pantallas y el conector muestran vínculos homogéneos para cualquier par de entidades, y el módulo de evidencias podrá apoyarse en la misma tabla.
- La integridad referencial no la impone la base de datos (no hay claves foráneas entre tipos distintos): la resolución de entidades devuelve `null` para un destino desaparecido y las pantallas lo muestran como referencia rota en lugar de fallar.
- Las búsquedas de los formularios de vínculo cargan listas acotadas (200 documentos, 300 actividades); con proyectos mayores conviene pasar a búsqueda incremental.
