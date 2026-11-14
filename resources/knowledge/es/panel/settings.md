---
id: forum.panel.settings
title: Ajustes del foro
summary: Tamaños de página, límites de publicación, conversación en las fichas, imágenes, incrustaciones con cookies, avisos de temas nuevos y personajes.
category: content
audience: panel
order: 180
keywords: [ajustes del foro, configuración del foro, temas por página, mensajes por página, mensajes por minuto, temas por hora, conversación en las fichas, comentarios en eventos, solo lectura, imágenes, formatos, peso máximo, sitios permitidos, imgur, incrustaciones, youtube, vimeo, cookies, categoría de cookies, consentimiento, avisos de temas nuevos, telegram, personajes, quién puede tener personajes]
related: [forum.panel.categories, forum.panel.permissions, forum.site.images, forum.site.external-images, forum.site.embeds, forum.site.characters]
conditions:
  permissions:
    all: ['forum:settings.manage']
---
Los ajustes del foro están en **Sistema** → **Configuración** → **Foro**. Cada campo muestra debajo su rango. Se guardan con **Guardar** y valen para todo el foro.

[[action:filament.admin.configuracion.pages.forum-settings|Abrir los ajustes del foro]]

## Listas y publicación

- **Temas por página** y **Mensajes por página**: cuántos se muestran de una vez en las listas y en los temas.
- **Mensajes por minuto y persona** y **Temas por hora y persona**: para frenar el exceso de mensajes. Quien se pasa espera antes de volver a publicar y se le dice cuánto. Cada tema cuenta también como mensaje.

## Conversación en las fichas

Las fichas de eventos, mesas sueltas y campañas pueden mostrar la conversación de su tema en el foro. Cada tipo de ficha tiene su selector, y solo salen los tipos cuyo módulo está activo:

- **No mostrar**: la ficha no enseña la conversación. Es el valor de partida, también para las fichas de un módulo que se active más adelante.
- **Solo lectura**: se ven los últimos mensajes y **Ver conversación completa**, sin cuadro para escribir. Si todavía no hay mensajes, sale una línea con **Comentar en el foro**.
- **Leer y responder**: los últimos mensajes y un cuadro para comentar desde la propia ficha.

Si desactivas un módulo, su valor se guarda y vuelve a aplicarse al activarlo.

## Imágenes

Solo suben imágenes quienes tengan el permiso **Subir imágenes al foro**. Aquí se fijan:

- **Formatos admitidos**: JPEG, PNG, WebP y GIF. SVG nunca se admite, porque puede llevar código.
- **Peso máximo de cada imagen (KB)** y **Lado máximo (píxeles)**: las imágenes más grandes se reducen a ese tamaño.
- **Imágenes por mensaje**.
- **Subidas por hora y persona**: cuenta también las imágenes de otros sitios.
- **Sitios de los que se pueden poner imágenes**: un dominio por entrada, sin `https://` (`imgur.com` vale también para `i.imgur.com`). Solo valen direcciones https. Sin ningún sitio, nadie puede poner imágenes de otros sitios, aunque tenga el permiso **Poner imágenes de otros sitios**. El foro guarda siempre una copia y quien lee no contacta con el otro sitio.

> [!IMPORTANT]
> Al quitar un sitio de la lista, los mensajes que ya llevan imágenes suyas las conservan, porque son copias del foro.

## Incrustaciones

- **Servicios que se incrustan**: de los que el foro conoce (YouTube, Vimeo, Twitch, Reddit, X, Bluesky, Instagram y TikTok), los que se ofrecen en el editor. Al quitar uno, los mensajes que ya lo incrustan siguen mostrando su tarjeta, sin cargar su contenido. Si no activas ninguno, no hay incrustaciones.
- **Categoría de cookies de (servicio)**: el contenido de un servicio solo se carga cuando quien lee lo pide o ya aceptó su categoría de cookies. Escribe aquí el identificador de la categoría del banner de cookies, por ejemplo `marketing`: minúsculas, cifras, guiones y guiones bajos. La persona que ya aceptó esa categoría ve el contenido sin pulsar nada; las demás ven una tarjeta con **Cargar contenido de (servicio)**.

Al activar un servicio, el sitio autoriza a sus reproductores en la política de seguridad del navegador; al quitarlo, la autorización desaparece.

## Avisos de temas nuevos

**Categorías que se anuncian**: los temas que se abran en ellas se anuncian en los canales del sitio (Telegram y los demás que estén configurados). Solo aparecen las categorías que se leen sin sesión. Los temas de mesas, campañas y eventos se anuncian con su publicación. Sin ninguna marcada, no se anuncia nada.

## Personajes

**¿Quién puede tener personajes?**: **Nadie**, **Cualquier cuenta con sesión** o **Solo con el permiso «Tener personajes en el foro»**. Al apagarlos, los mensajes ya escritos siguen mostrando su personaje. Quien modera el foro, o la categoría de un personaje, puede editarlo, retirarlo o borrarlo.

## Si algo no sale bien

- **Un campo no deja guardar**: cada uno muestra su rango; corrige el que esté marcado en rojo.
- **Un servicio aparece pero no carga su contenido**: revisa su **Categoría de cookies** y que el banner de cookies tenga esa categoría.
- **Una ficha no muestra la conversación**: revisa el valor de su tipo en **Conversación en las fichas**. Tampoco sale si la ficha aún no tiene tema en el foro o si quien la mira no puede leer su categoría.
- **No veo estos ajustes**: hace falta el permiso **Configurar el foro**.
