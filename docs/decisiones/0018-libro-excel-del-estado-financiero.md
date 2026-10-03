# 0018. Libro Excel del estado financiero, con números reales y fórmulas que traen su valor

Fecha: 2026-10-02. Estado: aceptada.

## Contexto

Casi todo pedido de información financiera llega como planilla: la Dirección de Investigación, la contraparte del Gobierno Regional y las auditorías trabajan en Excel. El módulo de finanzas (decisión 0016) y su tablero en el sitio (decisión 0017) ya entregaban archivos sueltos, como la planilla de carga masiva, la programación de caja y los CSV de pagos, ítems, cuotas y rendiciones, pero ninguno con todo el estado financiero. El escritor que existía, `Core/Spreadsheet`, sirve para exportar e importar datos: guarda los montos como números sin formato y las fechas como texto, sin estilos ni fórmulas. Un archivo así obliga a dar formato y a rehacer los totales antes de enviarlo, y un total escrito como número deja de cuadrar en cuanto alguien corrige una cifra.

## Decisión

- **Un libro con hojas fijas, una por materia,** en el orden de lectura del director: Resumen (fuentes de financiamiento, plazo y ejecución, cuota siguiente, rendiciones y alertas, índice con vínculos a cada hoja y notas), Alertas, Acciones, Cuota siguiente, Cuotas, Ítems, Pagos, Pagos por estado, Proveedores, Rendiciones mes a mes, Rendiciones registradas, Estados y pasos, Caja mes a mes, Programación de caja, Controles de caja, Cartola, Convenio, Modificaciones, Garantías y Reglas. Una hoja sin datos se entrega igual, con un aviso, para que quien recibe el libro sepa que la materia se revisó y no falta.
- **Números reales con formato, no texto.** Los montos llevan formato de pesos con los ceros como raya, las fechas son fechas (día, mes y año), los porcentajes son fracciones con formato de porcentaje y los números de documento y egreso son números cuando solo tienen cifras. Así el libro se ordena, se filtra y se suma sin conversiones.
- **Fórmulas donde hay aritmética, con el valor que calcula el módulo.** Los totales de tabla usan `SUBTOTAL(109, …)`, que sigue a los filtros; el disponible y el uso por ítem, los acumulados de caja, la caja real al cierre de cada mes, la diferencia con la cartola y los resúmenes por estado, proveedor y rendición (`SUMIFS` y `COUNTIFS` sobre la hoja de pagos, con rangos absolutos y criterios de texto sin comodines) son fórmulas. Cada fórmula se escribe junto con su valor ya calculado, de modo que el libro se lee bien en un visor que no recalcula, y el libro pide recalcular al abrirse. Solo se usan funciones que Excel y LibreOffice evalúan igual.
- **La caja real no cuenta lo proyectado.** La hoja de caja repite la curva del tablero, que proyecta la transferencia programada y no recibida en el mes en curso, y agrega la caja real al cierre: lo transferido de verdad menos lo pagado, que coincide con el saldo de caja del estado de cuentas.
- **Un escritor propio con estilos, `Core/Workbook`,** sin dependencias (basta la extensión zip de PHP): encabezados de color, fuente Arial, filas de encabezado y primera columna fijas, filtros, anchos de columna, vínculos internos, títulos de impresión y página apaisada ajustada al ancho. Toda celda de texto lleva un formato explícito, porque algunos programas no aplican la alineación del formato por omisión. `Core/Spreadsheet` sigue a cargo de las exportaciones e importaciones de datos.
- **El contenido es una función pura.** `Finance/Logic/WorkbookSheets` arma las hojas a partir de los datos del tablero (`BoardData`) más los estados y pasos con su autor, la cartola, los hallazgos de cada pago y el registro de los proveedores en SISREC; se prueba sin WordPress. La prueba en el sitio recalcula el libro con LibreOffice y compara cada fórmula con el valor que escribió el módulo.
- **El mismo permiso que las demás planillas: exportar finanzas (`finance.export`).** La descarga está en la cabecera de la pantalla Finanzas del panel y, en el tablero del sitio, en su cabecera y como primer botón de la pestaña Reportes. La cuenta bancaria del convenio queda fuera del libro: no hace falta para leer el estado financiero y el archivo suele circular por correo.

## Consecuencias

- El libro dice lo mismo que el tablero, porque sale de los mismos datos: una rendición no declarada en el módulo figura como vencida sin estado declarado, y el resumen lo explica.
- Quien corrige una cifra en el libro ve recalcularse los totales, disponibles y acumulados, pero el libro no vuelve al módulo: los cambios se registran en el panel.
- Las hojas anchas, como pagos e ítems, quedan muy reducidas al imprimirlas ajustadas al ancho; el libro es para trabajar en pantalla y el informe imprimible del tablero sigue siendo la versión para papel.
- Un fondo con otro perfil obtiene el mismo libro: estados, fuentes, tipos de gasto y reglas salen del perfil.
