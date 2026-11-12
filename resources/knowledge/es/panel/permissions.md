---
id: forum.panel.permissions
title: Permisos del foro
summary: Los seis permisos del foro, qué permite cada uno y cómo repartirlos entre roles.
category: content
audience: panel
order: 160
keywords: [permisos del foro, moderar todo el foro, gestionar categorías, configurar el foro, subir imágenes, imágenes de otros sitios, personajes, roles, equipo de moderación, rol de moderador, quién modera, permiso]
related: [forum.panel.categories, forum.panel.moderation, forum.panel.settings, core.panel.roles-and-permissions]
conditions:
  permissions:
    any: ['roles.create', 'roles.update']
---
El foro trae seis permisos. Ninguno se da a un rol por defecto: cada asociación los reparte entre los roles que crea (un equipo de moderación, quien lleva el foro…). La administración los tiene todos. Se marcan en el editor de roles, en la pestaña **Foro**.

| Permiso | Qué permite |
|---------|-------------|
| **Moderar todo el foro** | Ocultar, aprobar, mover, cerrar y fijar temas y mensajes en cualquier categoría; suspender; usar la página de moderación del panel. |
| **Gestionar las categorías del foro** | Crear, editar, ordenar y borrar categorías, y decidir quién ve, escribe y modera en cada una. |
| **Configurar el foro** | Cambiar los ajustes del foro. |
| **Subir imágenes al foro** | Añadir imágenes a los mensajes, con los formatos y límites de los ajustes. |
| **Poner imágenes de otros sitios** | Añadir imágenes de los sitios permitidos en los ajustes. El foro guarda una copia. |
| **Tener personajes en el foro** | Crear personajes y escribir como ellos, solo cuando los ajustes piden este permiso. |

## Dos formas de moderar

- **Todo el foro**: el permiso **Moderar todo el foro**. Quien lo tiene modera cualquier categoría y ve la página **Moderación del foro** del panel.
- **Solo una categoría**: en el formulario de la categoría, en **Roles que la moderan**, se elige uno o más roles. Es útil para dejar una categoría en manos de quien la conoce. En un espacio de campaña, además, modera su dirección. Quien modera solo una categoría usa el menú **Moderar** del foro, pero no ve la página del panel.

## Cosas que conviene saber

- Quien está **suspendido** en el foro no modera nada, tenga el permiso que tenga.
- Solo la administración suspende a quien modera todo el foro, y nadie se suspende a sí mismo.
- Las imágenes de otros sitios y los personajes dependen además de los ajustes del foro: **Sitios de los que se pueden poner imágenes** y **¿Quién puede tener personajes?**.
- Al dar un permiso a un rol, solo puedes dar los que tú mismo tienes.

## Si algo no sale bien

- **Una persona debería moderar y no ve el menú «Moderar»**: comprueba que su rol tiene el permiso (o modera esa categoría), que no esté suspendida y que la categoría esté disponible.
- **No veo la pestaña «Foro» en el editor de roles**: el módulo debe estar habilitado.
