# Foro

Módulo de GuildForge con el foro de la comunidad: categorías, temas y mensajes, con las mismas cuentas, roles y aspecto que la web.

> En construcción. El plan está en la épica #1. Esta versión trae el modelo de datos y los temas automáticos de las publicaciones, todavía sin pantallas públicas.

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

## Temas automáticos

Cada partida suelta, campaña y evento publicados tiene su tema en el foro sin que nadie lo abra. Todo llega por el contrato de publicaciones del core (`PublicationSourceInterface`, eventos `PublicationChanged` y `PublicationRemoved`): el módulo no importa nada de otros módulos. Lo hace `PublicationDiscussionSync`.

- **Un tema por publicación**, con la clave estable `<tipo>:<id>` en `forum_subject_discussions`. Volver a publicar nunca abre otro. Va en la categoría del sistema de su tipo (`game-table` → «Mesas», `campaign` → «Campañas», `event` → «Eventos»), con las etiquetas de sus `topics` (el sistema de juego).
- **El primer mensaje** lo escribe la cuenta del sistema: el resumen en texto plano, la fecha, el sistema de juego y un enlace a la ficha. Si cambian, se reescribe (queda como edición) y, si cambia el título, se renombra el tema.
- **Solo lo que está por llegar**: una publicación cancelada o terminada que nunca tuvo tema no lo tiene.
- **Avisos** como respuestas de la cuenta del sistema: nueva fecha; plaza libre en una partida llena con las inscripciones abiertas; cierre de inscripciones; cancelación, que cierra el tema; reactivación; retirada o borrado, que cierran el tema; y vuelta a publicar, que lo reabre. El tema nunca se borra.
  - Se guarda lo último que se dijo de cada cosa (los hechos), en la misma transacción que el aviso. Por eso, aunque la publicación se guarde dos veces, el aviso sale una sola vez. La primera lectura solo toma nota.
  - El módulo solo reabre lo que cerró él: un tema que cerró la moderación sigue cerrado.
  - Un tema que borra la moderación no se vuelve a abrir.
- **En cola**: cada cambio encola `SyncPublicationDiscussionJob`, que relee la publicación al ejecutarse. Si la fuente falla, el job falla y se reintenta: nunca se toma por una retirada. Si el tipo se queda sin fuente (su módulo deshabilitado), no se toca nada. Publicar en la web nunca falla por el foro.
- **Cierre de inscripciones a su hora**: si la publicación dice cuándo se cierran, se encola una comprobación para ese momento (una sola vez por hora de cierre).
- **Reconciliación diaria** (`forum:sync-publications`, a las 04:40): comprueba lo que cada fuente da por publicado (`publishedIds()`) y lo que el foro sigue, para lo publicado antes de habilitar el módulo y lo que se pierda por el camino.
- **Cuenta del sistema**: una cuenta local con el nombre del sitio (`SiteBrandingServiceInterface::siteName()`, que sigue a cada cambio), creada al habilitar el módulo.
  - No tiene ningún rol, su contraseña es aleatoria y su dirección (`forum-system-<uuid>@system.invalid`) no recibe correo, así que nadie puede entrar con ella. `users:assign-member-role` del core le pondría `member`, que no le da acceso a nada.
  - Se reconoce solo por su id, guardado en el ajuste `forum_system_account_id`, nunca por su dirección: una cuenta que alguien registre no se toma por ella. Si se borra o se anonimiza desde el panel, se crea otra la siguiente vez que haga falta.
  - Nunca recibe nada ni se puede mencionar: lo que avise a personas (menciones, seguimiento, «me gusta», citas, correo, resumen diario) la salta con `SystemAccountInterface::isSystemAccount()`.
  - Desinstalar el módulo con sus datos borra el ajuste.
- **Quién cerró el tema**: cada tema sabe si lo cerró el propio foro (`closed_by_system`). Cerrarlo o abrirlo a mano lo borra, así que el foro nunca abre lo que cerró la moderación.

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
| `horizontalRule`, `image` | — | `image`: `origin` (`upload`; otros orígenes podrán añadirse sin migrar datos), `src` (ruta en `forum/posts/`) y `alt` opcional (300 caracteres) |
| `hardBreak`, `mention` (inline) | — | `mention`: `id` de la cuenta |

Marcas: `bold`, `italic`, `strike`, `code` y `link` (solo `href`, a `http`, `https` o `mailto`, sin credenciales).

Límites (`DocumentLimits`): 256 KB de JSON, 16 niveles, 10 000 nodos, 65 000 caracteres de texto, 20 menciones y 10 citas por mensaje; las imágenes por mensaje, según los ajustes. Un mensaje sin texto, imagen ni mención está vacío.

- **Menciones y citas** guardan solo el id. El nombre y el enlace se buscan al pintar el mensaje, así que cambiar de nombre no rompe nada. Una cita muestra autor y enlace solo a quien puede leer el original, y al guardarla su texto tiene que estar en el mensaje citado (se comparan letras y cifras), para que nadie atribuya a otra persona lo que no dijo.
- **Imágenes**: las sube quien tiene `forum:posts.upload_images` a `POST /foro/imagenes`, con los formatos (de JPEG, PNG, WebP y GIF; nunca SVG), el peso, el lado máximo, las imágenes por mensaje y las subidas por hora de los ajustes. El tipo se lee de los bytes. Se guardan en `forum/posts/<año>/<mes>/`. La fuente de medios `forum.posts` las cuenta como usadas mientras alguna versión de un mensaje las muestre, también si el mensaje está oculto o borrado (hasta que se vacía).
- **Texto plano**: sale del mismo documento (bloques por línea, listas con su marca, citas tras «> », menciones con el nombre del momento).
- **Borrar**: el autor borra su mensaje y en su sitio queda «Mensaje borrado». Quien modera sigue viendo el cuerpo y las versiones durante 30 días; después `forum:purge-deleted-posts` (cada día) los vacía y sus imágenes dejan de contar como uso.
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

`resources/js/components/editor/PostEditor.vue`. La página le da `label`, y si procede `mentionsUrl`, `uploadUrl` (solo a quien tiene el permiso de subir imágenes), `maxImages` e `imageTypes` (de los ajustes), `draftKey` (borrador en el navegador por tema), `mentionNames` (nombres de las menciones del documento inicial) y `submitLabel`. Emite `submit` con el documento y expone `clear()`, `quote({ postId, text | content })`, `focus()` y `getDocument()`. Atajos: los de Markdown al escribir, Ctrl+Intro para enviar y Ctrl+K para el enlace; el Markdown pegado como texto se convierte.

## Permisos

Ninguno se concede solo: cada asociación los da a los roles que cree en el editor de roles. La administración los tiene todos.

| Permiso | Para qué sirve |
|---|---|
| `forum:forum.moderate` | Moderar todo el foro |
| `forum:categories.manage` | Gestionar las categorías y quién ve y escribe en cada una |
| `forum:settings.manage` | Cambiar los ajustes del foro |
| `forum:posts.upload_images` | Subir imágenes a los mensajes |

## Panel

- **Categorías del foro**: en *Comunicación* (hueco 30), con `forum:categories.manage`. Se ordenan arrastrando las filas. Al borrar una categoría con temas, hay que elegir antes a qué categoría pasan, y marcar una casilla para confirmarlo.
- **Ajustes**: en *Sistema → Configuración → Foro* (hueco 125 del clúster), con el permiso `forum:settings.manage`. Cuántos temas y mensajes se muestran por página (de 10 a 100; 20 por defecto), cuántos mensajes puede publicar una persona por minuto (de 1 a 60; 6 por defecto) y las imágenes: formatos (los cuatro por defecto), peso máximo (4096 KB), lado máximo (2000 px), imágenes por mensaje (10) y subidas por hora y persona (30).

## Desarrollo

Tests del módulo desde `src/` del core:

```bash
XDEBUG_MODE=off vendor/bin/phpunit -c modules/forum/phpunit.xml
```
