# Foro

Módulo de GuildForge con el foro de la comunidad: categorías, temas y mensajes, con las mismas cuentas, roles y aspecto que la web.

> En construcción. El plan está en la épica #1. Esta versión trae el modelo de datos, todavía sin pantallas públicas.

## Qué hay

- **Categorías, temas y mensajes** en las tablas `forum_categories`, `forum_discussions` y `forum_posts`.
- **Mensajes**: se guarda el documento del editor (JSON de TipTap, nunca HTML) y una copia en texto plano para la búsqueda y los resúmenes. Ver [Editor de mensajes](#editor-de-mensajes).
- **Historial de ediciones** en `forum_post_revisions`: cada edición guarda antes la versión que sustituye. Quien modera ve todas las versiones.
- **Estados del tema**: abierto o cerrado. Además, un tema puede estar fijado y oculto, de forma independiente (un tema de normas puede estar fijado y cerrado).
- **Estados del mensaje**: visible, oculto por moderación, pendiente de aprobación y borrado por su autor.
- **Numeración sin huecos**: cada mensaje tiene un número dentro de su tema desde que se puede leer (al publicarlo o al aprobarlo), y lo conserva aunque se oculte o se borre. Quien lee ve siempre 1, 2, 3… y, en lugar de los ocultos o borrados, un aviso.
  - Un mensaje pendiente no tiene número. Al aprobarlo, recibe el siguiente libre y cuenta como actividad nueva.
  - Solo se puede eliminar del todo un mensaje pendiente (rechazado o retirado).
- **Contadores** de cada tema y cada categoría: mensajes, último mensaje y última actividad. Se recalculan en la misma transacción que cada escritura, con la categoría y el tema bloqueados.
  - Un tema cuenta sus mensajes visibles.
  - Una categoría cuenta los temas no ocultos que tienen al menos un mensaje visible, y los mensajes de esos temas.

## Categorías

- **Dos niveles**: categorías y subcategorías, sin más profundidad. Los temas pueden ir en cualquiera de los dos niveles.
- **Quién ve y quién escribe**, por separado: cualquiera (solo para ver), con sesión, socios, los roles que se elijan, o la plantilla o la dirección de la publicación a la que pertenece la categoría (los espacios de campaña).
  - Las reglas guardan el id de cada rol: renombrar un rol no las cambia, y un rol borrado ya no deja entrar a nadie. Los roles inactivos no cuentan.
  - Ver una subcategoría exige ver también su categoría madre, y al guardar se rechaza una subcategoría que vería más gente que su madre.
  - Para escribir hace falta una cuenta que pueda ver la categoría y que no tenga pendiente verificar su email.
- **Moderación**: quien tiene `forum:forum.moderate` ve, escribe y modera en todas las categorías, también en los espacios privados, que lo avisan en su cabecera. Además, cada categoría puede tener sus propios roles moderadores o, en un espacio de campaña, su dirección. Quien modera una categoría modera también sus subcategorías y puede escribir en ellas.
- **Todo falla cerrado**: una regla ilegible, una madre que no existe o una plantilla que no se puede leer significan que no hay acceso. Lo decide un único servicio, `CategoryAccessPolicy`.
- **Categorías del sistema**: «Mesas», «Campañas» y «Eventos», con claves estables. Las usan los temas automáticos. Se pueden renombrar y cambiar de aspecto, pero no borrar.
- **Etiquetas de sistema de juego** (`game-system.<uuid>`, de los temas de cada publicación): sirven para filtrar, nunca para dar acceso.
- **Instalación**: al habilitar el módulo se crean «General», «Partidas por foro» y las tres del sistema, todas legibles por cualquiera.

## Editor de mensajes

El editor es TipTap en el navegador. Lo que se envía y se guarda es su documento JSON, que el servidor comprueba contra un esquema propio antes de guardarlo. Si no encaja, responde 422 con un código (`reason`) y la ruta dentro del documento (`path`); nunca lo arregla a escondidas. Nunca se acepta HTML de quien escribe.

### Esquema

| Nodo | Contenido | Atributos |
|---|---|---|
| `doc` | bloques | ninguno |
| `paragraph` | texto e inline, con marcas | ninguno |
| `heading` | texto e inline, con marcas | `level`: 2, 3 o 4 (el 1 es el título del tema) |
| `blockquote` | bloques | ninguno |
| `postQuote` | bloques | `postId`: id del mensaje citado |
| `bulletList`, `orderedList` | `listItem` | `orderedList`: `start` opcional (1 a 100 000) |
| `listItem` | bloques | ninguno |
| `codeBlock` | texto sin marcas | `language` opcional (`[a-z0-9][a-z0-9+#.-]{0,29}`) |
| `horizontalRule`, `image` | — | `image`: `src` (ruta en `forum/posts/`) y `alt` opcional (300 caracteres) |
| `hardBreak`, `mention` (inline) | — | `mention`: `id` de la cuenta |

Marcas: `bold`, `italic`, `strike`, `code` y `link` (solo `href`, a `http`, `https` o `mailto`, sin credenciales).

Límites (`DocumentLimits`): 256 KB de JSON, 16 niveles, 10 000 nodos, 65 000 caracteres de texto, 10 imágenes, 20 menciones y 10 citas por mensaje. Un mensaje sin texto, imagen ni mención está vacío.

- **Menciones y citas** guardan solo el id. El nombre y el enlace se buscan al pintar el mensaje, así que cambiar de nombre no rompe nada y nadie puede atribuir una cita a otra persona. Una cita muestra autor y enlace solo a quien puede leer el original.
- **Imágenes**: se suben a `POST /foro/imagenes` (JPEG, PNG, WebP o GIF, 4 MB, el tipo se lee de los bytes) y se guardan en `forum/posts/<año>/<mes>/`, redimensionadas a 2000 px. La fuente de medios `forum.posts` las cuenta como usadas mientras alguna versión de un mensaje las muestre, también si el mensaje está oculto o borrado.
- **Texto plano**: sale del mismo documento (bloques por línea, listas con su marca, citas tras «> », menciones con el nombre del momento).
- **Borrar**: el autor borra su mensaje y en su sitio queda «Mensaje borrado»; el cuerpo y las versiones se conservan para quien modera.
- **Límite de envío**: *Mensajes por minuto y persona* en los ajustes (6 por defecto). Al pasarse responde 429 con `Retry-After`.

### Endpoints (JSON, con sesión)

| Método y ruta | Qué hace |
|---|---|
| `POST /foro/temas/{tema}/mensajes` | Responder (`{"document": …}`) |
| `PATCH /foro/mensajes/{mensaje}` | Editar el mensaje propio |
| `DELETE /foro/mensajes/{mensaje}` | Borrar el mensaje propio |
| `GET /foro/mensajes/{mensaje}/versiones` | Versiones, para quien modera |
| `GET /foro/temas/{tema}/menciones?q=` | Personas que pueden leer el tema |
| `POST /foro/imagenes` | Subir una imagen |

Lo que la persona no puede leer responde 404; lo que no puede hacer, 403.

### Añadir un nodo

Un nodo nuevo (tiradas, apartes fuera de personaje) se añade en los dos lados:

1. **Servidor**: una clase que implemente `Application\Content\Contracts\NodeTypeInterface` (o extienda `Nodes\AbstractNodeType`), con su contenido, sus atributos tipados, su HTML (escapando todo el texto) y su texto plano. Se registra con la etiqueta `ForumServiceProvider::NODE_TYPES_TAG` (`$this->app->tag([RollNode::class], self::NODE_TYPES_TAG)`), y el esquema la incluye.
2. **Editor**: una extensión de TipTap cuyo JSON tenga solo esos atributos, pasada en `extensions` de `PostEditor` (y, si lleva botón, un `ToolbarItem` en `toolbarItems`). Conviene añadirla al fixture `tests/fixtures/editor-document.json`, que comprueban los dos lados.

### Componente

`resources/js/components/editor/PostEditor.vue`. La página le da `label`, y si procede `mentionsUrl`, `uploadUrl`, `draftKey` (borrador en el navegador por tema), `mentionNames` (nombres de las menciones del documento inicial) y `submitLabel`. Emite `submit` con el documento y expone `clear()`, `quote({ postId, text | content })`, `focus()` y `getDocument()`. Atajos: los de Markdown al escribir, Ctrl+Intro para enviar y Ctrl+K para el enlace; el Markdown pegado como texto se convierte.

## Permisos

Ninguno se concede solo: cada asociación los da a los roles que cree en el editor de roles. La administración los tiene todos.

| Permiso | Para qué sirve |
|---|---|
| `forum:forum.moderate` | Moderar todo el foro |
| `forum:categories.manage` | Gestionar las categorías y quién ve y escribe en cada una |
| `forum:settings.manage` | Cambiar los ajustes del foro |

## Panel

- **Categorías del foro**: en *Comunicación* (hueco 30), con `forum:categories.manage`. Se ordenan arrastrando las filas. Al borrar una categoría con temas, hay que elegir antes a qué categoría pasan, y marcar una casilla para confirmarlo.
- **Ajustes**: en *Sistema → Configuración → Foro* (hueco 125 del clúster), con el permiso `forum:settings.manage`. Cuántos temas y mensajes se muestran por página (de 10 a 100; 20 por defecto) y cuántos mensajes puede publicar una persona por minuto (de 1 a 60; 6 por defecto).

## Desarrollo

Tests del módulo desde `src/` del core:

```bash
XDEBUG_MODE=off vendor/bin/phpunit -c modules/forum/phpunit.xml
```
