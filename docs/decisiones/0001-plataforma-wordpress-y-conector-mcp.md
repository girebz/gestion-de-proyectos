# 0001. Plugin de WordPress con conector sobre la API de habilidades y el adaptador MCP oficial

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

El sitio del proyecto (relavecircular.com) ya existe en WordPress, con blog público y foro interno, y el equipo necesita un sistema de gestión interno que además pueda ser consultado y operado por asistentes de inteligencia artificial. Se evaluaron tres vías de integración con Claude: exportar e importar archivos, que el plugin llame a la interfaz de programación de Anthropic, y que Claude consulte el plugin directamente mediante un servidor MCP. Se eligió la tercera como objetivo, conservando la primera como respaldo.

## Decisión

Construir un plugin de WordPress (no una aplicación aparte) y exponer sus funciones como habilidades de WordPress (Abilities API, en el núcleo desde 6.9), publicadas como herramientas MCP por el adaptador oficial (WordPress/mcp-adapter) en un servidor propio del plugin: `/wp-json/gestion-de-proyectos/mcp`. El adaptador no es dependencia dura: sin él, el plugin funciona y el conector queda inactivo con aviso en el diagnóstico.

## Consecuencias

- Requisitos mínimos: WordPress 6.9 y PHP 8.1. El diagnóstico los comprueba.
- Cualquier cliente MCP puede conectarse, no solo Claude.
- Las herramientas se definen una sola vez (esquemas de entrada y salida, permisos) y sirven para MCP y, en el futuro, para la API REST.
- Se depende de la evolución del adaptador (versión 0.6/0.7 al escribir esto); se fija en el diagnóstico la versión detectada y se prueba cada actualización en el entorno de pruebas.
