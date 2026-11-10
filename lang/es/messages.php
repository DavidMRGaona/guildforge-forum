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
];
