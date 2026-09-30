# 0004. El plugin adopta la identidad visual del sitio que lo aloja

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

Se pidió que la identidad del plugin se acople a la del sitio anfitrión: en relavecircular.com debe verse como parte de Relave Circular; en otro sitio, como parte de ese otro.

## Decisión

`GDP\Core\Identity` lee nombre y descripción del sitio, logotipo personalizado, ícono del sitio y, desde `theme.json`, la paleta (claves habituales `primary`, `secondary`, `accent`, `contrast`, `base`) y la primera familia tipográfica. Genera variables CSS `--gdp-*` que gobiernan las pantallas, los informes exportados y el tablero público. Los ajustes permiten correcciones manuales; los campos vacíos conservan el valor detectado. El plugin no trae logotipo ni paleta propios.

## Consecuencias

- Las pantallas deben usar solo las variables de identidad y los estilos del panel de WordPress; nada de colores fijos salvo los semánticos (estados, alertas).
- Si un tema no declara paleta, se usa la del panel de WordPress hasta que el administrador la corrija.
- El nombre del menú combina el nombre del sitio con "Proyectos".
