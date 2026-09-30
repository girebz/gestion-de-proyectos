# 0006. Tokens por usuario con cabecera fija en lugar de OAuth

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

Los conectores personalizados de Claude admiten OAuth o cabeceras fijas configuradas al añadir el conector. Implementar un servidor de autorización OAuth en el sitio añade complejidad y superficie de ataque; las contraseñas de aplicación de WordPress funcionan, pero no permiten limitar alcance ni caducar sin tocar la cuenta.

## Decisión

Tokens generados por cada usuario desde el panel, con etiqueta, alcance (`read` o `read,write`) y caducidad; se muestran una sola vez y se guardan resumidos con SHA-256. Se envían en la cabecera `X-GDP-Token` (también se acepta `Authorization: Bearer`). La resolución del usuario ocurre en `determine_current_user` solo para peticiones al punto de entrada MCP del plugin; un token inválido produce 401 mediante `rest_authentication_errors`. El token hereda exactamente los permisos de su dueño. Las contraseñas de aplicación siguen aceptadas como alternativa. OAuth queda como posible decisión futura si el equipo crece o si Claude deja de admitir cabeceras fijas.

## Consecuencias

- El permiso de transporte del servidor exige usuario autenticado con acceso al plugin y capacidad `gdp_use_connector`; cada herramienta comprueba además su permiso fino.
- Un token de solo lectura bloquea `propose-*`, `confirm-operation` y `revert-operation` aunque el dueño pueda escribir por el panel.
- Las peticiones de Claude llegan desde la infraestructura de Anthropic; las restricciones por dirección IP deben contemplarlo.
