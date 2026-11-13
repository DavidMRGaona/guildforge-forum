---
id: forum.panel.moderation
title: Moderar el foro desde el panel
summary: Denuncias, mensajes pendientes de aprobación, suspensiones e historial de moderación, en cuatro pestañas.
category: content
audience: panel
order: 170
keywords: [moderación del foro, denuncias, denunciar, aprobar, rechazar, pendientes, cuenta nueva, suspender, suspensión, levantar suspensión, historial, registro de moderación, ocultar mensaje, resolver, sin acción, editado, spam, actividad del foro]
related: [forum.site.moderating, forum.panel.categories, forum.panel.permissions]
conditions:
  capabilities: [forum.moderates_forum]
---
La página **Moderación del foro** (en **Comunicación**) junta lo que espera a la moderación. La ve quien tiene el permiso **Moderar todo el foro**. Quien modera solo una categoría trabaja desde el propio foro, con el menú **Moderar**.

[[action:filament.admin.pages.moderacion-del-foro|Abrir la moderación del foro]]

El número junto al nombre de la página en el menú suma las denuncias abiertas y los mensajes pendientes, y el widget **Actividad del foro** del escritorio resume los últimos 30 días.

Tiene cinco pestañas, que quedan en la dirección de la página: un enlace puede abrir la que toca.

## Denuncias

Cada fila es un mensaje denunciado, con su autor, el tema, los **Motivos** y cuántas denuncias lleva. Quien denuncia elige entre **Spam o publicidad**, **Ofensivo o irrespetuoso**, **Fuera de tema**, **Datos personales de otra persona** y **Otro motivo**, y puede dejar un comentario.

En el menú de la fila:

- **Abrir en el foro** lleva al mensaje.
- **Ocultar el mensaje** lo oculta (pide un motivo) y resuelve sus denuncias.
- **Resolver sin acción** cierra las denuncias sin tocar el mensaje.
- **Resolver como editado**, cuando ya has editado el mensaje.

Ocultar o editar un mensaje denunciado también resuelve sus denuncias. La persona denunciada no sabe quién lo hizo.

## Mensajes privados

La moderación **no lee las conversaciones privadas**: ni desde aquí ni desde el foro se puede abrir una. Solo llega lo que alguien de la conversación denuncia. Con cada denuncia se guarda una copia del mensaje y de los tres anteriores y posteriores, tal como estaban al denunciar, y eso es lo único que se ve.

Cada fila es un mensaje privado denunciado, con su autor, los **Motivos** y el comentario de quien denunció. En el menú de la fila:

- **Ver la copia denunciada** abre la copia, con el mensaje denunciado marcado.
- **Ocultar el mensaje** lo oculta para todas las personas de la conversación (pide un motivo, que solo ve su autor) y resuelve sus denuncias.
- **Resolver sin acción** cierra las denuncias sin tocar el mensaje.

Si quien escribió el mensaje lo borra, o la conversación desaparece porque todos han salido, la copia sigue aquí. Para suspender a su autor, usa **Suspender**, arriba. Lo mismo se hace desde la página **Moderación** del foro. Solo quien modera todo el foro ve estas denuncias: los moderadores de una categoría no, porque una conversación no es de ninguna categoría.

## Pendientes

Los mensajes que esperan aprobación: los de quien tiene pocos mensajes en el foro, según la **Aprobación previa** de cada categoría. Si el mensaje abre un tema, se marca como **tema nuevo**.

- **Aprobar** lo publica. Si abre un tema, el tema se anuncia como cualquier otro, y su autor recibe un aviso.
- **Rechazar** lo borra para siempre, y con él el tema, si es el primer mensaje. Pide marcar «Entiendo que el mensaje se borra y que no se puede deshacer» y admite un **Motivo (opcional)**, que queda en el historial.

Un tema con el primer mensaje pendiente no admite respuestas hasta que se resuelva.

## Suspensiones

La lista de quién ha estado suspendido, con su **Estado**: **En vigor**, **Terminada** o **Levantada**.

- **Suspender** (arriba, o desde el menú de un mensaje en el foro) pide la **Persona**, un **Motivo** (que ve la persona) y la **Duración**: 1, 3, 7, 30 o 90 días, o **Sin fecha de fin**. Durante la suspensión la persona puede leer, pero no escribir, dar «me gusta», denunciar ni moderar. Se levanta sola al terminar, sin avisar.
- **Levantar** la termina antes de tiempo, y la persona recibe un aviso.

No se puede suspender a la cuenta del foro ni a uno mismo. A quien modera todo el foro solo lo suspende la administración, y solo la administración levanta esa suspensión.

## Historial

Todo lo que hace la moderación queda registrado: fijar, cerrar, ocultar, mover, renombrar, borrar, **Mensajes separados**, **Tema unido a otro**, aprobar, rechazar, editar, resolver denuncias, suspender, levantar, y lo que se hace con los personajes de otras personas. Cada fila dice **Cuándo**, **Acción**, **Quién**, **A quién**, el **Tema** y los **Detalles**. Los filtros **Acción** y **Quién** acotan la lista. El historial no se edita ni se borra.

## Separar y unir temas

Se hace desde el foro, no desde esta página: mira la guía de moderar desde el foro. Quedan en el historial como **Mensajes separados** y **Tema unido a otro**.

## Avisos de moderación

- Quien modera recibe un aviso cuando alguien denuncia un mensaje que le toca revisar: por correo solo la primera denuncia de cada mensaje, y en la campana agrupadas por mensaje. Se elige en el tipo **Denuncias de mensajes** de **Avisos**, en su perfil. Las denuncias de mensajes privados avisan a quien modera todo el foro, sin decir quién lo escribió ni qué dice.
- El autor recibe aviso cuando se aprueba su mensaje o tema, y cuando se le suspende o se levanta la suspensión.

## Si algo no sale bien

- **No veo la página**: hace falta el permiso **Moderar todo el foro**, y no estar suspendido en el foro.
- **«No se ha podido hacer: puede que otra persona ya lo haya resuelto»**: recarga la lista.
- **«No se puede suspender a esta persona»**: es la cuenta del foro, eres tú, o es otra persona que modera todo el foro.
