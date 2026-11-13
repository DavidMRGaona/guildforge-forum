---
id: forum.site.dice
title: Tiradas de dados en un mensaje
summary: Cómo tirar dados en el foro, con la tabla de expresiones (2d6+3, 4d6kh3, 5d10>=7, 3d6!), las tiradas ocultas y sus límites.
category: community
audience: site
order: 80
keywords: [dados, tirada, tirar, d20, d6, 2d6, d%, dF, fate, reserva, éxitos, mantener, descartar, explotar, explosión, ventaja, desventaja, kh, kl, dh, dl, resultado, trampas, aleatorio, expresión, oculta, ocultas, secreta, pantalla, revelar, dirección]
related: [forum.site.play-by-post, forum.site.characters, forum.site.writing]
---
Puedes tirar dados dentro de un mensaje. El foro tira los dados al publicar, en el servidor, y guarda el resultado: nadie, tampoco quien escribe, puede elegirlo ni repetir la tirada.

## Tirar

De dos maneras:

- Pulsa **Tirada de dados** en el editor, escribe la expresión (por ejemplo `2d6+3`) y, si quieres, un motivo.
- En una línea propia, escribe `/tirada 2d6+3 Ataque` y pulsa Intro: `2d6+3` es la expresión y «Ataque» el motivo.

Al publicar, el mensaje muestra la expresión, cada dado, el total y el motivo. En un mensaje en personaje se lee «(nombre) tira».

## Tiradas ocultas

Una tirada oculta es como la que se hace detrás de la pantalla: se tira igual, en el servidor, pero no todo el mundo ve lo que sale.

- **Para hacerla**, marca **Oculta** en la tirada antes de publicar, o escribe `/tirada oculta 1d20+2 Sigilo` y pulsa Intro.
- **Ven el resultado** quien tira, la moderación y, en el espacio de una campaña, su dirección. El resto lee «Tirada oculta: 1d20+2 Sigilo», con la expresión y el motivo, pero sin los dados ni el total.
- **El resultado tampoco sale** en los avisos, los correos, la búsqueda, las citas ni la conversación de una ficha.
- **Para revelarla**, pulsa **Revelar** en la tirada. Pueden hacerlo quien tiró y, en el espacio de una campaña, su dirección. Desde ese momento la ve todo el mundo y la tirada muestra quién la reveló y cuándo. **No se puede volver a ocultar.**
- Ocultar o no una tirada se elige antes de tirarla: al editar el mensaje, una tirada ya hecha sigue como estaba.

## Expresiones que entiende

Las mayúsculas y los espacios alrededor de `+` y `-` no importan.

| Escribes | Qué hace |
|----------|----------|
| `2d6+3`, `1d20-1` | Dados y números que se suman o restan. `d%` es `d100`. |
| `3d6+1d4` | Varios grupos de dados en una misma tirada. |
| `4dF` | Dados Fate: cada uno da -1, 0 o +1. |
| `5d10>=7` | Reserva: cuenta cuántos dados llegan a 7. Admite `>=`, `>`, `<=`, `<` y `=`. |
| `5d10>=8f=1` | Reserva que además resta los dados que sacan 1. |
| `4d6kh3` | Se queda con los 3 más altos (`kh`). `k3` es lo mismo. |
| `2d20kl1` | Se queda con el más bajo (`kl`). Sirve para la desventaja. |
| `4d6dl1`, `4d6dh1` | Descarta el más bajo (`dl`) o el más alto (`dh`). |
| `3d6!` | Explosión: el dado que saca su máximo se vuelve a tirar y se suma. |
| `3d10!>=9` | Explosión con condición: se vuelve a tirar el dado que cumple `>=9`. |

Los dados descartados se ven tachados y marcados como «(descartado)», y los de una explosión, como «(explosión)».

## Límites

- Hasta 10 tiradas y 300 dados por mensaje.
- Hasta 100 dados y 1000 caras por grupo, 10 grupos y 200 caracteres por expresión.
- Hasta 100 dados añadidos por las explosiones de un grupo.

## Al editar un mensaje

Editar nunca repite una tirada: las que siguen en el mensaje conservan lo que salió, y la expresión de una ya hecha queda bloqueada, igual que la casilla **Oculta**. Si quieres otra tirada, quita la anterior y añade una nueva: se tira una vez y se marca como **Añadida al editar**. Las tiradas quitadas siguen en el historial del mensaje.

## Si algo no sale bien

- **«No se entiende la expresión»**: revisa que sea del estilo `2d6+3`, sin palabras dentro. El motivo va aparte, al final.
- **«Demasiados dados» o «Demasiadas caras»**: baja a los límites de arriba.
- **«Los dados no son válidos»**: hace falta al menos un dado, y cada dado, dos caras.
- **«Lo que se conserva o se descarta no es válido»**: tiene que quedar al menos un dado.
- Una tirada con error se publica igual, con su aviso, y el resto del mensaje no se pierde.
