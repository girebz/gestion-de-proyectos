# Ejemplos de importación

## `relaves-coquimbo.json`

Datos del proyecto "Valorización de Relaves Abandonados en Coquimbo" (Universidad Central de Chile, Fondo Regional para la Productividad y el Desarrollo del Gobierno Regional de Coquimbo, código BIP 40075890-0) en el formato de exportación del plugin (`gestion-de-proyectos/export`, versión 1), con la información conocida al 30 de septiembre de 2026:

- ficha del proyecto con fechas de ejecución inferidas (1 de febrero de 2026 a 31 de enero de 2028; ver más abajo), equipo con perfiles, catálogos del proyecto (frentes de trabajo y partidas del fondo) y partidas presupuestarias con los montos del convenio;
- calendario laboral con los feriados de Chile de 2026 a 2028;
- estructura de desglose de 33 actividades en cinco frentes (gestión, caracterización, mezclas y prototipado, pilotaje e infraestructura, difusión y capacitación), con dependencias, restricciones de fecha, avances registrados y asignaciones, construida a partir de la carta Gantt de la propuesta (meses contados desde junio de 2026) y del estado de avance;
- proveedores, seis compras con sus cotizaciones, ítems, etapas y órdenes (laboratorios geoquímico, mineralógico y físico, insumos del muestreo, capacitación y equipamiento de planta piloto), y el valor de referencia de la unidad de fomento;
- once documentos (oficios recibidos, cartas enviadas, cotizaciones, orden de compra y contrato) con sus vínculos;
- tres reuniones con asistentes y siete acuerdos;
- referencias en sistemas externos (código BIP, centro de costo, órdenes en el sistema institucional, contrato en firma).

### Cómo importarlo

1. Si quiere que el equipo y las asignaciones queden vinculados, cree antes en WordPress los usuarios con los nombres de usuario o correos que figuran en la sección `users` del archivo (`cristian.sanchez`, `giorgio.reveco`, `jorge.concha`, `jorge.romero`, `hector.calderon`, `daniel.romero`, `constanza.molina`, `alejandra.mura`). Los que no existan se omiten con un aviso; el resto de los datos se carga igual.
2. En el panel, vaya a Proyectos → Datos → Importar, suba el archivo y elija "Crear un proyecto nuevo". Si ya existe un proyecto con el código `RELAVES-COQUIMBO`, indique otro código.
3. Revise la vista previa (qué se crea por tabla) y confirme. La importación queda registrada como operación y se puede revertir completa desde Proyectos → Operaciones.

Por el conector, la misma carga se hace con `propose-data-change` (`action: import`, `mode: new`, `document: <contenido del archivo>`) y `confirm-operation`.

### Fechas de ejecución

Según las bases (punto 8), el plazo de 24 meses corre desde la primera transferencia de recursos (cuota 1). Esa fecha no consta en los documentos disponibles. Se infiere de la reunión de preguntas y respuestas con el Gobierno Regional del 10 de marzo de 2026, de la recepción de los centros de costo el 24 de marzo y de la entrega del primer informe técnico el 28 de mayo (los informes vencen 20 días hábiles después de cada trimestre), que sitúan la transferencia hacia fines de enero o comienzos de febrero de 2026. El archivo usa el 1 de febrero de 2026 como inicio y el 31 de enero de 2028 como término. Cuando se tenga el comprobante de ingreso a caja de la cuota 1, o el oficio que fije los plazos, basta con corregir las fechas en la ficha del proyecto; el cronograma no depende de ellas.

### Qué no incluye

Adjuntos (los archivos de las versiones de documentos no viajan en un JSON; use la exportación en ZIP), bitácora, instantáneas semanales y datos personales más allá de los nombres y correos institucionales de contacto. Los montos están en pesos salvo la compra en unidades de fomento, que conserva su moneda de origen y la conversión a pesos de la fecha de referencia.

El archivo se generó con los datos conocidos del proyecto; donde una fecha no constaba (por ejemplo, la emisión de una cotización), se usó la fecha más próxima documentada y se dejó constancia en las notas del registro. Es un ejemplo de arranque, no un sustituto de los registros oficiales del proyecto.
