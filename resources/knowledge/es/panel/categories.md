---
id: forum.panel.categories
title: Gestionar las categorías del foro
summary: Crear, ordenar y borrar categorías, decidir quién las ve, quién escribe y quién las modera, y entender la insignia «Sin el módulo que la alimenta».
category: content
audience: panel
order: 150
keywords: [categorías del foro, crear categoría, subcategoría, categoría madre, orden, arrastrar, quién ve, quién escribe, solo lectura, aprobación previa, cuenta nueva, moderadores de la categoría, del sistema, mesas, campañas, eventos, sin el módulo, disponibilidad, borrar categoría, mover temas, roles, socios]
related: [forum.panel.moderation, forum.panel.settings, forum.panel.permissions]
conditions:
  permissions:
    all: ['forum:categories.manage']
---
Las categorías son la estructura del foro. Esta guía explica cómo se gestionan en **Contenido** → **Categorías del foro** y qué decide cada ajuste.

[[action:filament.admin.resources.forum-categories.index|Abrir las categorías del foro|if=forum:categories.manage]]

## La lista

Arrastra las filas para cambiar el orden: es el orden en que se muestran en el foro. Las subcategorías aparecen bajo su categoría. La lista muestra de cada una su nombre, cuántos temas tiene, si es **Del sistema** y su **Disponibilidad**. En pantallas estrechas, **Ver detalles** del menú de la fila muestra las columnas que no caben.

## Crear o editar una categoría

El formulario tiene cuatro secciones.

**General**
- **Nombre** y **Dirección** (la parte de la web que identifica la categoría, en minúsculas, números y guiones: «partidas-por-foro»; se rellena sola desde el nombre).
- **Descripción corta**.
- **Categoría madre**: solo hay un nivel de subcategorías. Una categoría con subcategorías no puede ser a su vez subcategoría.

**Aspecto**: **Color** e **Icono**.

**Quién ve y quién escribe**
- **Quién la ve**: **Cualquiera** (también quien no ha iniciado sesión), **Con sesión iniciada**, **Socios** (los de {{association_role}}) o **Algunos roles**.
- **Quién escribe**: las mismas opciones, salvo cualquiera: para escribir hace falta una cuenta. Quien escribe tiene que poder verla.
- Una subcategoría solo la ve quien también ve su categoría madre, así que nunca puede ser más abierta que ella.
- **Solo lectura**: nadie escribe salvo quien modera todo el foro. Quien la modera sigue moderándola.

**Moderación**
- **Roles que la moderan**: además de quien modera todo el foro. Quien modera una categoría también modera sus subcategorías.
- **Aprobación previa**: un número de 0 a 50. Los mensajes de quien tenga menos mensajes publicados en el foro entero que ese número esperan a que la moderación los apruebe, y mientras tanto solo los ven su autor y la moderación. Con 0 está apagada. La moderación de la categoría nunca espera.

## Borrar una categoría

**Borrar** pide elegir **Mover sus temas a** otra categoría (si tiene temas) y marcar «Entiendo que no se puede deshacer». No se puede borrar una categoría con subcategorías: muévelas o bórralas antes. Las categorías **Del sistema** no se pueden borrar.

## Categorías del sistema y de publicaciones

El foro crea solo algunas categorías y subcategorías:

- **Mesas**, **Campañas** y **Eventos**: un tema por cada mesa, campaña o evento publicado. Se pueden renombrar y restilar, nunca borrar.
- El **espacio de cada campaña**: una subcategoría privada para su plantilla y su dirección, que no se edita a mano. En su formulario solo cambia la moderación y el interruptor **Solo lectura**; si la puso el foro porque la campaña terminó, se quita sola si vuelve a estar en marcha.

### La insignia «Sin el módulo que la alimenta»

Estas categorías dependen de que el sitio tenga el módulo que publica mesas, campañas o eventos. Si ese módulo se deshabilita o se desinstala, su categoría pasa a mostrar en **Disponibilidad** la insignia **Sin el módulo que la alimenta**:

- Solo la ve la moderación del foro, con un aviso en su cabecera. Nadie más la ve, ni siquiera quien la modera solo a ella.
- No se ofrece como categoría madre ni como destino al mover temas, y nadie abre temas nuevos en ella.
- **No se borra nada**: ni los temas ni los mensajes. Su contenido tampoco aparece en las búsquedas ni en los avisos.
- Vuelve a verse en cuanto el módulo se habilita de nuevo. El foro lo detecta solo y rehace lo que falte.

Se puede editar mientras tanto. Si no vas a volver a usar el módulo, cuenta con que sus temas se quedan ahí, ocultos.

## Si algo no sale bien

- **«Una subcategoría no puede verla más gente que su categoría madre»**: abre la madre a más gente o cierra la subcategoría.
- **«Para escribir hace falta una cuenta»**: la categoría no puede ser de escritura abierta a cualquiera.
- **No veo mi categoría nueva en el foro**: puede que la vean solo algunos roles o que dependa de un módulo que falta. Mira su **Disponibilidad**.
