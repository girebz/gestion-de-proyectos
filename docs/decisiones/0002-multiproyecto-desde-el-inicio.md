# 0002. Varios proyectos por instalación desde el primer commit

Fecha: 2026-09-30. Estado: aceptada.

## Contexto

El plugin nace para un proyecto concreto, pero se decidió plantearlo como herramienta general para proyectos semejantes. Añadir después la noción de proyecto a un sistema pensado para uno solo obliga a tocar cada tabla, pantalla y herramienta.

## Decisión

Toda entidad de módulo lleva `project_id` y los permisos se resuelven por proyecto (perfil del usuario en ese proyecto). Los únicos datos globales son los catálogos base (`project_id = 0`), los ajustes del plugin y los tokens del conector (por usuario). Los módulos de nicho se activan por proyecto en `settings.modules`; los del núcleo están siempre activos. El vocabulario es configurable por catálogos con valores globales y entradas por proyecto.

## Consecuencias

- El proyecto de relaves es el primer caso de uso y la fuente de datos de prueba, no la definición del sistema.
- Las pantallas listan y filtran por proyecto; el conector exige `project_id` (o código) en casi todas las herramientas.
- Un mismo usuario puede tener perfiles distintos en proyectos distintos.
