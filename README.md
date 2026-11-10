# Foro

Módulo de GuildForge con el foro de la comunidad: categorías, temas y mensajes, con las mismas cuentas, roles y aspecto que la web.

> En construcción. El plan está en la épica #1. Esta versión trae el modelo de datos, todavía sin pantallas públicas.

## Qué hay

- **Categorías, temas y mensajes** en las tablas `forum_categories`, `forum_discussions` y `forum_posts`.
- **Mensajes**: se guarda el documento del editor (JSON de TipTap, nunca HTML) y una copia en texto plano para la búsqueda y los resúmenes.
- **Estados del tema**: abierto o cerrado. Además, un tema puede estar fijado y oculto, de forma independiente (un tema de normas puede estar fijado y cerrado).
- **Estados del mensaje**: visible, oculto por moderación, pendiente de aprobación y borrado por su autor.
- **Numeración sin huecos**: cada mensaje tiene un número dentro de su tema desde que se puede leer (al publicarlo o al aprobarlo), y lo conserva aunque se oculte o se borre. Quien lee ve siempre 1, 2, 3… y, en lugar de los ocultos o borrados, un aviso.
  - Un mensaje pendiente no tiene número. Al aprobarlo, recibe el siguiente libre y cuenta como actividad nueva.
  - Solo se puede eliminar del todo un mensaje pendiente (rechazado o retirado).
- **Contadores** de cada tema y cada categoría: mensajes, último mensaje y última actividad. Se recalculan en la misma transacción que cada escritura, con la categoría y el tema bloqueados.
  - Un tema cuenta sus mensajes visibles.
  - Una categoría cuenta los temas no ocultos que tienen al menos un mensaje visible, y los mensajes de esos temas.

## Permisos

Ninguno se concede solo: cada asociación los da a los roles que cree en el editor de roles. La administración los tiene todos.

| Permiso | Para qué sirve |
|---|---|
| `forum:forum.moderate` | Moderar todo el foro |
| `forum:categories.manage` | Gestionar las categorías y quién ve y escribe en cada una |
| `forum:settings.manage` | Cambiar los ajustes del foro |

## Panel

- **Ajustes**: en *Sistema → Configuración → Foro* (hueco 125 del clúster), con el permiso `forum:settings.manage`. De momento, cuántos temas y mensajes se muestran por página (de 10 a 100; 20 por defecto).
- **Recursos**: irán en el grupo «Comunicación», en el hueco 30.

## Desarrollo

Tests del módulo desde `src/` del core:

```bash
XDEBUG_MODE=off vendor/bin/phpunit -c modules/forum/phpunit.xml
```
