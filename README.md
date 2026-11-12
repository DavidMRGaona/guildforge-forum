# Foro

Módulo de GuildForge con el foro de la comunidad: categorías, temas y mensajes, con las mismas cuentas, roles y aspecto que la web.

> En construcción. El plan está en la épica #1. Esta versión trae el modelo de datos, los temas automáticos de las publicaciones, los espacios de campaña, las páginas públicas, los «me gusta», el seguimiento, los avisos y la moderación.

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

## Páginas públicas

| Ruta | Qué muestra |
|---|---|
| `/foro` | Las categorías que se pueden leer, con sus subcategorías, y los temas recientes de todas |
| `/foro/c/<categoría>` | Sus subcategorías y los temas de la categoría y de ellas, con los fijados primero |
| `/foro/t/<número>-<título>[/<mensaje>]` | La conversación, por páginas de números de mensaje (1-20, 21-40…) |
| `/foro/nuevo` | Abrir un tema (con sesión) |

- **Número del tema**: cada tema recibe al empezar un número correlativo (1, 2, 3…), que es lo que identifica la dirección. Si el resto de la dirección no coincide (el tema se renombró), redirige a la buena con un 301. Si dos temas se abren a la vez y les toca el mismo número, se vuelve a intentar con el siguiente.
- **Lo que no se puede ver da 404**, nunca 403: una categoría privada, un tema oculto (salvo para quien lo modera) o un tema que no existe.
- **Listas**: por última actividad, con paginación por cursor (`?desde=`) sobre la actividad y el id, así que la actividad nueva nunca repite ni salta temas entre páginas. Filtros «Sin leer» (`?sin-leer=1`) y «Siguiendo» (`?siguiendo=1`, los temas que la persona sigue), solo con sesión, y etiqueta de sistema de juego (`?etiqueta=`). En una categoría, los fijados salen aparte en la primera página.
- **Conversación**: un enlace a un mensaje abre su página y lo resalta. Sin número, se abre en el primer mensaje sin leer (en el último si ya está todo leído) y, para quien nunca lo abrió o no tiene sesión, en el primero. La dirección del navegador sigue al mensaje que se lee, sin añadir entradas al historial. La URL canónica es la del principio de cada página.
- **Desde la conversación**: responder, citar (el texto seleccionado o el mensaje entero, con enlace al original) y editar o borrar los mensajes propios, con el editor de abajo.
- **No leídos**, solo con sesión:
  - Por persona y tema se guarda el último número leído (`forum_discussion_reads`). La página informa de los mensajes que ha tenido en pantalla, así que la marca avanza al leer, nunca por adelantado, y nunca retrocede. Al responder o abrir un tema, la marca llega hasta el mensaje propio.
  - «Marcar todo como leído», en el foro y en cada categoría (con sus subcategorías), guarda la fecha por persona y categoría (`forum_category_reads`): lo publicado antes cuenta como leído. Marcar todo el foro escribe una fila por categoría, no por tema.
- **Abrir un tema**: título de 3 a 120 caracteres, una categoría donde la persona pueda escribir (nunca «Mesas», «Campañas» ni «Eventos», que llenan las publicaciones), hasta 5 etiquetas de las que ya hay en el foro y el primer mensaje. Quien modera la categoría puede abrirlo fijado o cerrado.
- **Avisos en los canales**: un tema nuevo lanza el evento del core `ForumDiscussionStarted` (una vez, tras guardarse) solo si un invitado puede leer su categoría, la categoría está marcada en *Categorías que se anuncian* de los ajustes (ninguna por defecto) y no es una categoría del sistema ni un espacio de publicación. Lleva el título, la dirección pública, un extracto del primer mensaje y como etiquetas el nombre de la categoría y las del tema; nada del autor.
- **Menú**: al habilitar el módulo se añade «Foro» al menú principal, como enlace del módulo. Se mueve o se quita en *Sitio web → Navegación*.
- **SEO**: las páginas públicas llevan título, descripción y URL canónica; las de categorías o temas que un invitado no puede leer, y el formulario de abrir tema, llevan `noindex`. El foro no sale en el mapa del sitio.

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

## Espacios de campaña

Cada campaña tiene un espacio privado en el foro para su plantilla, donde jugar por escrito y organizarse. Lo hace `CampaignSpaceSync`, en el mismo job que el tema automático.

- **Una subcategoría privada por campaña** dentro de «Campañas», ligada a la campaña (`campaign.<id>`). Se crea cuando la campaña está publicada y en marcha, nunca para una que ya terminó o se canceló. La ven y escriben en ella la plantilla y la dirección, y la modera la dirección. El tema público de la campaña sigue en «Campañas». Su nombre sigue al título de la campaña.
- **Sin copia de la plantilla**: el acceso se pregunta al roster del core (`PublicationRosterInterface`) cada vez, así que entrar o salir de la campaña cambia el acceso al momento. Si el roster no responde, solo entra la moderación del foro.
- **Terminada o cancelada**: el espacio pasa a solo lectura. Su plantilla y su dirección lo siguen leyendo, y la dirección lo sigue moderando, pero solo escribe quien tiene `forum:forum.moderate`.
- **Retirada o borrada**: sin roster no hay plantilla, así que solo lo ve la moderación del foro (y queda en solo lectura). Al volver a publicarla, se recupera tal cual. Nunca se borran mensajes.
- **Solo lectura con origen** (`read_only_by` en `forum_categories`): la pone el foro (`system`) o la moderación (`moderation`, con el interruptor «Solo lectura» del panel).
  - El foro solo actúa cuando la campaña cambia (de en marcha a terminada, cancelada o retirada, o al revés), según lo último que vio (`space_open`). Entre medias manda la moderación.
  - Encender el interruptor hace la marca de la moderación: el foro ya no la quita al reactivar la campaña. Apagarlo la quita, aunque la pusiera el foro. Guardar sin tocarlo no cambia nada.
  - En una categoría en solo lectura (o con la madre en solo lectura) solo escribe quien tiene `forum:forum.moderate`, también en sus temas ocultos.
- **Cabecera**: la página de la categoría pinta `SpaceNotices.vue` (`resources/js/components/spaces`), que dice que la moderación puede entrar en un espacio privado y avisa cuando es de solo lectura (`isPublicationSpace` y `readOnly` de la cabecera; una madre en solo lectura cuenta).
- **Reconciliación diaria**: la de los temas automáticos comprueba también las campañas cuyo espacio aún admite mensajes.

## Me gusta, seguimiento y avisos

- **Me gusta**: uno por persona y mensaje (`forum_post_likes`), que se quita con otro clic. Lo da cualquiera con sesión que pueda leer el mensaje, también en temas cerrados o espacios de solo lectura, salvo su autor, y nunca en los mensajes de la cuenta del sistema, en temas ocultos ni en mensajes pendientes de aprobación. Cada mensaje enseña el número y, al pasar el ratón o tocarlo, los nombres de los 10 últimos.
- **Seguir un tema**: «Seguir» (cada respuesta), «Normal» (menciones y citas) o «Silenciar» (nada).
  - Se guarda en `forum_discussion_follows`. «Silenciar» es el silencio del core del tema (`MailSubjectMuteServiceInterface`, tipo `forum.discussion`), el mismo que ofrece el pie de cada correo, así que el foro, el correo y la pestaña «Avisos» del perfil siempre coinciden.
  - Abrir un tema o responder en él lo sigue, salvo que la persona lo haya quitado en la pestaña «Foro» del perfil (`forum_user_preferences`) o ya hubiera elegido algo para ese tema: una elección explícita no se pisa.
- **Seguir una categoría** (`forum_category_follows`, botón en su página): avisa de cada tema nuevo en ella y en sus subcategorías. Al entrar en la plantilla de una campaña se sigue su espacio y llega el aviso «Te han añadido a la campaña X», una sola vez; al salir se deja de seguir, salvo que la persona lo siguiera por su cuenta. Lo hace `SpaceRosterFollows`, que relee el roster al ejecutarse.
- **Avisos**, en la campana del core y por correo, con siete categorías en la sección «Foro» de la pestaña «Avisos»:

| Categoría | Qué avisa | Correo |
|---|---|---|
| `forum.replies` | Respuestas en temas que sigues (en la web, agrupadas por tema mientras no se lean) | Uno por tema hasta que se vuelve a leer; admite el resumen diario |
| `forum.discussions` | Temas nuevos en categorías que sigues y espacios de campaña en los que puedes entrar | Admite el resumen diario (el aviso del espacio va solo en la web) |
| `forum.mentions` | Menciones y citas de un mensaje tuyo | Al momento |
| `forum.likes` | «Me gusta» de tus mensajes (agrupados por mensaje) | Nunca: solo en la web |
| `forum.approvals` | La moderación ha aprobado un mensaje o un tema tuyo que esperaba revisión (`ForumPostApproved`) | Al momento |
| `forum.suspensions` | Te han suspendido en el foro, con el motivo y la fecha de fin o «hasta que se levante» (`ForumUserSuspended`). Obligatoria | Siempre, al momento |
| `forum.reports` | Para quien modera: han denunciado un mensaje (`ForumPostReported`). En la web se agrupan por mensaje mientras haya denuncias abiertas | Solo por la primera denuncia abierta de cada mensaje |

- Cada persona recibe un solo aviso por mensaje: mención antes que cita y cita antes que seguimiento. Al editar un mensaje, solo avisa a quien menciona o cita por primera vez.
- Todo pasa por la cola: `ForumWriter` entrega cada mensaje que recibe número (respuestas, temas nuevos, aprobaciones y los mensajes de la cuenta del sistema) a `DeliverPostNoticesJob`, que decide a quién avisar (`PostNoticePlanner`), y cada aviso vuelve a comprobar al entregarse (`PostNoticeContext`) que la persona puede leer el tema en ese momento (`ForumAccessInterface`), que el mensaje sigue visible y que la cuenta está activa.
- Nunca se avisa a quien escribió el mensaje, a cuentas desactivadas o anonimizadas, ni a la cuenta del sistema.
- Los avisos tratan del tema (los de temas nuevos y espacios, de la categoría), así que silenciarlo desde el correo los calla también en la web. Al borrar un tema o una categoría se llama a `forgetSubject()`, que borra sus silencios y sus avisos.
- Los nombres que van al correo pasan por `EscapesMarkdown`.
- **Avisos de moderación** (`NotifyPostAuthorOfApproval`, `NotifyUserOfSuspension` y `NotifyModeratorsOfReport`, oyentes en cola de los tres eventos de la moderación):
  - La aprobación avisa al autor desde el evento. A los seguidores, menciones y citas los avisa `ForumWriter::approvePost()` y el planificador nunca incluye al autor, así que nadie recibe dos avisos.
  - Las denuncias llegan a quien modera el mensaje: `CategoryModeratorFinder` reúne a los candidatos (permiso del foro, roles moderadores y dirección de la plantilla, de la categoría y de la de arriba) y cada aviso vuelve a preguntar a `ForumAccessInterface::canModerate()` al entregarse, así que quien esté suspendido o haya perdido el rol no lo recibe. No dice quién denunció ni lo que escribió, y quien denunció no recibe el aviso de su propia denuncia. Una denuncia ya resuelta no se avisa.
  - La suspensión solo se cuenta mientras sigue vigente y es la última de esa persona.

## Moderación

Modera un tema quien tiene `forum:forum.moderate` (todo el foro) o modera su categoría (sus roles moderadores o, en un espacio de campaña, la dirección). Todo pasa por `ForumModeration`: comprueba el permiso (lo que no se puede leer da 404 y lo que no se puede moderar, 403), escribe con los bloqueos de `ForumWriter` y anota la acción en el historial en la misma transacción.

- **En el tema** (menú «Moderar» de la cabecera): fijar y desfijar, cerrar y reabrir, renombrar, mover, ocultar y mostrar, y borrar.
  - **Mover**: solo a una categoría que quien mueve también modere, nunca a «Mesas», «Campañas» ni «Eventos». El tema de una publicación no se mueve: se queda donde lo puso el foro.
  - **Borrar** es definitivo (el tema y todos sus mensajes): solo lo hace quien modera todo el foro, y pide marcar una casilla. Quien modera una categoría lo oculta.
  - Cerrar, ocultar, mover y borrar admiten un motivo, que queda en el historial.
- **En cada mensaje** (menú «Moderar» de su pie): ocultar con un motivo (300 caracteres), que leen su autor y la moderación, y mostrar; aprobar o rechazar lo pendiente (rechazar borra el mensaje y pide marcar una casilla). La moderación edita también los mensajes ajenos con el editor de siempre; la versión anterior queda en el historial del mensaje.
- **Lo que hace la moderación es suyo** y el foro nunca lo deshace:
  - un tema que cierra la moderación no lo reabre el foro (`closed_by_system` se borra al cerrarlo a mano);
  - un título que pone la moderación ya no sigue al de la publicación (`title_by_moderation`);
  - un primer mensaje de un tema automático que edita la moderación ya no se reescribe;
  - el foro decide sus cambios sobre el tema bloqueado (`ForumWriter::changeDiscussion()`), así que lo que haga la moderación a la vez no se pisa;
  - un espacio de campaña en solo lectura por la moderación sigue así (`read_only_by`).
- **Aprobación previa**, por categoría (*Aprobación previa* en el formulario, de 0 a 50; 0 la apaga): los mensajes de quien tenga menos mensajes publicados en todo el foro que ese número esperan a que la moderación los apruebe. Cuentan los mensajes a la vista y los que borró su autor, nunca los ocultos ni los pendientes. Quien modera la categoría y la cuenta del sistema nunca esperan. Si no se puede comprobar, el mensaje espera (falla cerrada).
  - Una respuesta pendiente no tiene número; al aprobarla recibe el siguiente libre.
  - Un tema cuyo primer mensaje espera, espera con él (`awaiting_approval`): solo lo ven su autor y la moderación, no sale en las listas, no admite respuestas y su menú solo ofrece aprobar o rechazar. Al aprobarlo se abre a sus lectores y, si toca, se anuncia en los canales una sola vez y tras guardarse (`DiscussionAnnouncer`, con las mismas reglas que al abrirlo). Rechazarlo borra el tema.
- **Límites**: *Mensajes por minuto* y *Temas por hora* por persona (5 por defecto, de 1 a 60), en los ajustes. Cada tema cuenta también como mensaje. Al pasarse se responde 429 con `Retry-After` y un mensaje que dice cuánto esperar.
- **Denuncias** (`forum_reports`): cualquiera con sesión denuncia un mensaje a la vista que no sea suyo, con un motivo de una lista cerrada (spam, ofensivo, fuera de tema, datos personales de otra persona, otro) y un comentario opcional. Mientras su denuncia sigue abierta, volver a denunciar no hace nada. Quien escribió el mensaje no sabe quién lo denunció.
  - Se resuelven todas las denuncias abiertas de un mensaje a la vez: «oculto» (oculta el mensaje, con el motivo), «editado» o «sin acción». Ocultar o editar un mensaje denunciado desde el tema también las resuelve.
  - Al denunciar se lanza `ForumPostReported` (tras guardarse), para que los avisos lleguen a la moderación.
- **Suspensiones** (`forum_suspensions`): quien modera todo el foro suspende a una persona unos días o sin fecha de fin, con un motivo que esa persona ve en el foro. Mientras dura, lee, pero no escribe, no denuncia y no modera nada; «me gusta» debe preguntarlo igual (`ForumSuspensions::isSuspended()`). Se levanta sola al terminar (no hace falta ninguna tarea) o antes, a mano.
  - Nadie se suspende a sí mismo ni a la cuenta del sistema, y a quien modera todo el foro solo lo suspende el rol `admin` (dos moderadores no pueden suspenderse entre sí). Nadie levanta su propia suspensión, y solo `admin` levanta la que puso `admin` (o alguien cuya cuenta ya no existe) o la de quien modera todo el foro. Quien está suspendido no modera nada, aunque modere todo el foro. Una suspensión nueva sustituye a la que estuviera en vigor.
  - Si no se pueden leer las suspensiones, nadie escribe (falla cerrada).
  - Al suspender se lanza `ForumUserSuspended` (tras guardarse), y al aprobar un mensaje, `ForumPostApproved`, para los avisos.
- **Página «Moderación»** del foro (`/foro/moderacion`, enlace en la portada y en cada tema para quien modera algo): las denuncias pendientes y lo que espera aprobación en las categorías que la persona modera, para resolverlo sin el panel. Quien no modera nada recibe 404.
- **Historial** (`forum_moderation_log`): quién hizo qué, a quién, dónde, cuándo y por qué. Solo se añaden filas: nada lo cambia ni lo borra. Guarda el título y el número del tema, así que se lee aunque el tema ya no exista.

### Endpoints de moderación (JSON, con sesión, 60 por minuto)

| Método y ruta | Qué hace |
|---|---|
| `POST /foro/temas/{tema}/moderacion` | `{"action": "pin" \| "unpin" \| "close" \| "reopen" \| "hide" \| "show" \| "rename" \| "move", "reason"?, "title"?, "category"?}` |
| `DELETE /foro/temas/{tema}` | Borrar el tema, con `{"confirmed": true, "reason"?}` |
| `POST /foro/mensajes/{mensaje}/moderacion` | `{"action": "hide" \| "show" \| "approve" \| "reject", "reason"?}` |
| `POST /foro/mensajes/{mensaje}/denuncias` | Denunciar, con `{"reason", "note"?}` (10 por minuto) |
| `POST /foro/mensajes/{mensaje}/denuncias/resolver` | `{"resolution": "hidden" \| "edited" \| "no_action", "reason"?}` |
| `POST /foro/suspensiones` | Suspender, con `{"user", "reason", "days"?}` (sin `days`, sin fecha de fin) |
| `DELETE /foro/suspensiones/{suspensión}` | Levantar una suspensión, con `{"reason"?}` |

Algo que la moderación nunca hace (mover a una categoría del sistema, un título fuera de límites) responde 422 con `reason`; una acción sobre algo que ya está así (cerrar un tema cerrado), 409.

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
| `POST` / `DELETE /foro/mensajes/{mensaje}/me-gusta` | Dar y quitar «me gusta» |
| `PUT /foro/temas/{tema}/seguimiento` | Nivel de seguimiento (`{"level": "following" \| "normal" \| "muted"}`) |
| `PUT /foro/c/{categoría}/seguimiento` | Seguir una categoría (`{"follow": true}`) |
| `GET` / `PUT /foro/preferencias` | Pestaña «Foro» del perfil: seguimiento automático y lo que se sigue |

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
| `forum:forum.moderate` | Moderar todo el foro, también desde el panel, y borrar temas |
| `forum:categories.manage` | Gestionar las categorías y quién ve y escribe en cada una |
| `forum:settings.manage` | Cambiar los ajustes del foro |
| `forum:posts.upload_images` | Subir imágenes a los mensajes |

## Panel

- **Categorías del foro**: en *Comunicación* (hueco 30), con `forum:categories.manage`. Se ordenan arrastrando las filas. Al borrar una categoría con temas, hay que elegir antes a qué categoría pasan, y marcar una casilla para confirmarlo. En la sección *Moderación* se eligen sus roles moderadores, su aprobación previa y si es de solo lectura.
- **Moderación del foro**: en *Comunicación* (hueco 35), con `forum:forum.moderate`. El número del menú suma denuncias abiertas y mensajes pendientes. Cuatro pestañas (`?pestana=`):
  - *Denuncias*: las abiertas, lo más antiguo primero. «Ocultar el mensaje» a la vista; en el menú ⋯, «Ver detalles», «Abrir en el foro», «Resolver sin acción» y «Resolver como editado».
  - *Pendientes*: lo que espera aprobación, lo más antiguo primero. «Aprobar» a la vista; en el menú ⋯, «Ver detalles», «Abrir en el foro» y «Rechazar», que pide un motivo opcional y marcar una casilla.
  - *Suspensiones*: las que están en vigor primero, con «Levantar». La acción «Suspender» de la cabecera elige a la persona, el motivo y la duración (1, 3, 7, 30 o 90 días, o sin fecha de fin).
  - *Historial*: el historial de moderación, lo más nuevo primero, con filtro por acción. Solo se lee.
  - Las tablas caben en todos los anchos: las columnas secundarias aparecen desde el ancho en que caben y «Ver detalles» muestra las ocultas.
- **Actividad del foro**: widget del escritorio con los temas y mensajes de los últimos 30 días, las denuncias pendientes y lo pendiente de aprobación (enlaza a la moderación). Solo con `forum:forum.moderate`.
- **Ajustes**: en *Sistema → Configuración → Foro* (hueco 125 del clúster), con el permiso `forum:settings.manage`. Cuántos temas y mensajes se muestran por página (de 10 a 100; 20 por defecto), las categorías cuyos temas nuevos se anuncian en los canales del sitio (solo las que se leen sin sesión; ninguna por defecto), cuántos mensajes puede publicar una persona por minuto (de 1 a 60; 6 por defecto), cuántos temas puede abrir por hora (de 1 a 60; 5 por defecto) y las imágenes: formatos (los cuatro por defecto), peso máximo (4096 KB), lado máximo (2000 px), imágenes por mensaje (10) y subidas por hora y persona (30).

## Desarrollo

Tests del módulo desde `src/` del core:

```bash
XDEBUG_MODE=off vendor/bin/phpunit -c modules/forum/phpunit.xml
```
