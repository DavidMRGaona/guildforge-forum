<?php

declare(strict_types=1);

return [
    'title' => 'Foro',

    'permissions' => [
        'forum' => [
            'moderate' => 'Moderar todo el foro',
            'moderate_description' => 'Ocultar, aprobar, mover, cerrar y fijar temas y mensajes en cualquier categoría.',
        ],
        'categories' => [
            'manage' => 'Gestionar las categorías del foro',
            'manage_description' => 'Crear, editar, ordenar y borrar categorías, y decidir quién ve y escribe en cada una.',
        ],
        'settings' => [
            'manage' => 'Configurar el foro',
            'manage_description' => 'Cambiar los ajustes del foro.',
        ],
    ],

    'settings' => [
        'navigation' => 'Foro',
        'title' => 'Ajustes del foro',
        'lists' => 'Listas',
        'discussions_per_page' => 'Temas por página',
        'posts_per_page' => 'Mensajes por página',
        'page_size_help' => 'De :min a :max.',
        'posting' => 'Publicación',
        'posts_per_minute' => 'Mensajes por minuto y persona',
        'posts_per_minute_help' => 'Cuántos mensajes puede publicar una persona en un minuto, de :min a :max. Al pasarse, espera antes de volver a publicar.',
        'saved' => 'Ajustes guardados',
    ],

    'defaults' => [
        'general' => ['name' => 'General', 'description' => 'Para hablar de todo un poco.'],
        'play_by_post' => ['name' => 'Partidas por foro', 'description' => 'Partidas que se juegan por turnos, mensaje a mensaje.'],
        'game-tables' => ['name' => 'Mesas', 'description' => 'Un tema por cada mesa publicada.'],
        'campaigns' => ['name' => 'Campañas', 'description' => 'Un tema por cada campaña publicada.'],
        'events' => ['name' => 'Eventos', 'description' => 'Un tema por cada evento publicado.'],
    ],

    'spaces' => [
        'moderation_notice' => 'Este espacio es privado, pero la moderación del foro puede entrar en él para atender reportes.',
    ],

    'categories' => [
        'navigation' => 'Categorías del foro',
        'model' => 'categoría',
        'plural' => 'categorías',
        'list_help' => 'Arrastra las filas para cambiar el orden. Las subcategorías aparecen bajo su categoría.',
        'system_badge' => 'Del sistema',
        'sections' => [
            'general' => 'General',
            'appearance' => 'Aspecto',
            'access' => 'Quién ve y quién escribe',
            'access_help' => 'Una subcategoría solo la ve quien también puede ver su categoría. Para escribir hace falta una cuenta que pueda verla.',
            'moderation' => 'Moderación',
            'moderation_help' => 'Además de quien modera todo el foro. Quien modera una categoría también modera sus subcategorías y puede escribir en ellas.',
        ],
        'fields' => [
            'name' => 'Nombre',
            'slug' => 'Dirección',
            'slug_help' => 'Minúsculas, números y guiones, como en «partidas-por-foro».',
            'description' => 'Descripción corta',
            'parent' => 'Categoría madre',
            'parent_help' => 'Solo hay un nivel de subcategorías.',
            'parent_none' => 'Ninguna (categoría principal)',
            'color' => 'Color',
            'icon' => 'Icono',
            'space_notice' => 'Esta categoría es el espacio de una publicación. La moderación del foro también puede entrar en ella.',
            'view' => 'Quién la ve',
            'write' => 'Quién escribe',
            'write_help' => 'Solo cuentas: quien no ha iniciado sesión nunca escribe.',
            'roles' => 'Roles',
            'moderator_roles' => 'Roles que la moderan',
            'moderator_roster' => 'También la moderan',
            'discussions' => 'Temas',
            'system' => 'Sistema',
        ],
        'actions' => [
            'delete' => 'Borrar',
            'delete_heading' => 'Borrar la categoría',
            'delete_description' => 'La categoría se borrará para siempre. Sus temas pasarán a la categoría que elijas.',
            'move_to' => 'Mover sus temas a',
            'move_to_help' => 'La categoría tiene temas: elige dónde quedarán.',
            'delete_confirmation' => 'Entiendo que no se puede deshacer',
            'deleted' => 'Categoría borrada',
        ],
        'errors' => [
            'invalid' => 'La categoría no es válida.',
            'missing_parent' => 'La categoría madre ya no existe.',
            'guests_cannot_write' => 'Para escribir hace falta una cuenta.',
            'roster_without_subject' => 'Esta regla solo vale en el espacio de una publicación.',
            'own_parent' => 'Una categoría no puede ser su propia madre.',
            'parent_too_deep' => 'Esa categoría ya es una subcategoría: no admite subcategorías.',
            'has_subcategories' => 'Esta categoría tiene subcategorías: no puede ser una de ellas.',
            'wider_than_parent' => 'Una subcategoría no puede verla más gente que su categoría madre.',
            'narrower_than_children' => 'Alguna de sus subcategorías la vería más gente que esta categoría.',
            'system_category' => 'El foro necesita esta categoría: no se puede borrar.',
            'still_holds_subcategories' => 'Mueve o borra antes sus subcategorías.',
            'still_holds_discussions' => 'Elige a qué categoría pasan sus temas.',
            'cannot_move_into_itself' => 'Sus temas tienen que pasar a otra categoría.',
        ],
    ],

    'audiences' => [
        'everyone' => 'Cualquiera',
        'signed_in' => 'Con sesión iniciada',
        'association_members' => 'Socios',
        'roles' => 'Algunos roles',
        'roster' => 'La plantilla y la dirección',
        'roster_leaders' => 'La dirección',
        'unreadable' => 'Nadie (regla ilegible)',
    ],

    'icons' => [
        'Chat' => 'Conversación',
        'Megaphone' => 'Megáfono',
        'Dice' => 'Dado',
        'Puzzle' => 'Pieza de puzle',
        'Users' => 'Grupo',
        'Calendar' => 'Calendario',
        'Map' => 'Mapa',
        'Book' => 'Libro',
        'Sparkles' => 'Destellos',
        'Shield' => 'Escudo',
        'Trophy' => 'Trofeo',
        'Flag' => 'Bandera',
        'Lock' => 'Candado',
        'Question' => 'Interrogación',
    ],

    'posts' => [
        'deleted' => 'Mensaje borrado',
        'hidden' => 'Mensaje oculto',
        'pending' => 'Pendiente de aprobación',
    ],

    'media' => [
        'source' => 'Mensajes del foro',
        'role' => 'Imagen del mensaje',
        'post_title' => ':discussion, mensaje :number',
        'pending_post_title' => ':discussion, mensaje pendiente',
    ],

    'content' => [
        'quote' => 'Cita',
        'quote_by' => ':name escribió:',
        'unknown_account' => 'cuenta eliminada',
    ],

    'errors' => [
        'forbidden' => 'No puedes hacer eso en este tema.',
        'too_fast' => 'Has publicado muchos mensajes seguidos. Espera :seconds segundos y vuelve a intentarlo.',
        'post_state' => 'Este mensaje ya no se puede cambiar.',
        'image_upload' => 'No se ha podido subir la imagen. Inténtalo de nuevo más tarde.',
        'document' => [
            'not_a_document' => 'El mensaje no tiene un formato válido.',
            'too_large' => 'El mensaje es demasiado grande.',
            'too_deep' => 'El mensaje tiene demasiados niveles anidados (citas o listas dentro de otras).',
            'too_many_nodes' => 'El mensaje tiene demasiados elementos.',
            'too_long' => 'El mensaje es demasiado largo.',
            'empty' => 'El mensaje está vacío.',
            'unknown_node' => 'El mensaje tiene un elemento que el foro no admite.',
            'unknown_mark' => 'El mensaje tiene un formato de texto que el foro no admite.',
            'unexpected_key' => 'El mensaje no tiene un formato válido.',
            'not_allowed_here' => 'El mensaje tiene un elemento en un sitio donde no puede ir.',
            'missing_content' => 'El mensaje tiene una lista o una cita vacía.',
            'invalid_text' => 'El mensaje tiene un texto no válido.',
            'invalid_attribute' => 'El mensaje tiene un elemento con un valor no válido.',
            'invalid_link' => 'Los enlaces solo pueden ir a direcciones http, https o mailto.',
            'invalid_image' => 'Las imágenes deben ser JPEG, PNG, WebP o GIF subidas al foro.',
            'too_many_images' => 'Un mensaje puede llevar 10 imágenes como mucho.',
            'too_many_mentions' => 'Un mensaje puede mencionar a 20 personas como mucho.',
            'too_many_quotes' => 'Un mensaje puede citar 10 mensajes como mucho.',
            'unknown_account' => 'Mencionas a una cuenta que ya no existe.',
            'unknown_post' => 'Citas un mensaje que no existe o que no puedes ver.',
        ],
    ],

    'editor' => [
        'image_attribute' => 'imagen',
    ],
];
