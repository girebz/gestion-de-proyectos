# 0011. Convenciones del motor de programación

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

El módulo de planificación necesita un motor de programación por el método de la ruta crítica que sea determinista, comprobable sin WordPress y coherente con las herramientas que el equipo conoce (Microsoft Project, Primavera), sin depender de bibliotecas externas de JavaScript ni de PHP. Las convenciones sobre fechas de hitos, días hábiles, restricciones y fechas reales admiten varias lecturas; fijarlas aquí evita reinterpretaciones entre sesiones de desarrollo.

## Decisión

- **Unidad de tiempo.** El motor trabaja con fronteras de días hábiles: la frontera `k` es el inicio del día hábil `k` (y el final del día `k-1`). El día hábil 0 es el primer día laborable igual o posterior al inicio del proyecto; los índices negativos son admisibles (fechas reales anteriores al inicio).
- **Actividades.** Una actividad de duración `d` que comienza en la frontera `S` ocupa los días `S` a `S+d-1` y termina en la frontera `F = S + d`; su fecha de inicio es el día `S` y la de término el día `F-1`. La duración mínima es un día hábil.
- **Hitos.** Un hito es un evento de duración cero en la frontera `F`, fechado el día `F-1` (se alcanza al terminar ese día). Por tanto, un hito fin a inicio tras una actividad que termina el día `X` se fecha el mismo día `X`, y una actividad fin a inicio tras un hito fechado el día `X` empieza el día `X+1`. Un hito sin predecesoras se fecha el primer día del proyecto.
- **Dependencias.** Cuatro tipos (FS, SS, FF, SF) con retraso en días hábiles, negativo para adelantos. Los resúmenes no participan en dependencias: se vinculan sus actividades. Un ciclo se detecta con orden topológico (Kahn) y se rechaza antes de guardar.
- **Restricciones.** `asap` (lo antes posible), `snet` y `fnet` (no antes de: elevan el inicio temprano), `snlt` y `fnlt` (no después de: acotan las fechas tardías y pueden producir holgura negativa), `mso` y `mfo` (obligatorias: fijan ambos extremos). Cuando una fecha fijada es anterior a lo que exigen las predecesoras, prevalece la fecha fijada y la discrepancia se informa como conflicto (equivale a la opción de Microsoft Project de respetar siempre las restricciones).
- **Fechas reales.** Una fecha real de inicio fija el inicio; una fecha real de término fija el término (y el inicio, si falta, por la duración). Una actividad terminada no tiene holgura ni se marca crítica; una en curso conserva la holgura que le dejan sus sucesoras. Para los hitos, cualquiera de las dos fechas reales lo fija.
- **Holguras y ruta crítica.** Holgura total `LF - F`; holgura libre respecto de la sucesora más próxima; crítica cuando la holgura total es cero o negativa. Las fechas tardías se calculan desde el término temprano del proyecto; la holgura respecto del término contractual se informa aparte (`deadline_slack`).
- **Resúmenes.** Fechas por envolvente de sus hijas, holgura mínima, crítico si alguna hija lo es, avance ponderado por duración (los hitos no pesan; si nada pesa, promedio simple).
- **Calendario.** Días de la semana laborables más excepciones por fecha (feriado o laborable por excepción). Los feriados de Chile se generan con las reglas legales: Pascua por el algoritmo de Meeus, Jones y Butcher; traslado al lunes del 29 de junio y del 12 de octubre (ley 19.973); 31 de octubre (ley 20.299); puentes del 17 y 20 de septiembre (ley 20.215); 2 de enero cuando el 1 cae en domingo (ley 20.983); solsticio de invierno calculado astronómicamente en hora de Chile continental (ley 21.357), con el 21 de junio de 2021 como excepción transitoria. Los feriados regionales y los decretados cada año se añaden a mano.
- **Caché y recálculo.** Las fechas programadas se guardan en las actividades como caché, se recalculan en cada lectura (el cálculo es barato) y solo se escriben las filas cuyo resultado cambió. Ninguna pantalla ni herramienta edita esas columnas a mano.
- **Palabras reservadas.** Las columnas evitan palabras reservadas de MySQL 8 (`lag` se llama `lag_days`), porque el analizador de la integración SQLite y MySQL las rechazan sin comillas.

## Consecuencias

- El motor (`src/Planning`) no depende de WordPress y se prueba con PHPUnit o con el ejecutor mínimo `tests/bin/run.php`; cualquier cambio de convención exige actualizar esas pruebas y esta decisión.
- Los usuarios de Microsoft Project encuentran el mismo comportamiento de restricciones, holguras y hitos; la exportación futura a XML de Project no necesita reinterpretar fechas.
- La carta Gantt del panel y el informe en LaTeX dibujan exactamente lo que el motor calcula, sin lógica de programación propia en el cliente.
