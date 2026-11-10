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
];
