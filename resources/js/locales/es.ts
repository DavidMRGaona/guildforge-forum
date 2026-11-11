export default {
    forum: {
        title: 'Foro',
        spaces: {
            moderationNotice:
                'Este espacio es privado, pero la moderación del foro puede entrar en él para atender reportes.',
        },
        editor: {
            submit_hint: 'Ctrl + Intro para enviar',
            toolbar: {
                label: 'Formato del mensaje',
                bold: 'Negrita',
                italic: 'Cursiva',
                strike: 'Tachado',
                heading: 'Encabezado {level}',
                bullet_list: 'Lista',
                ordered_list: 'Lista numerada',
                blockquote: 'Cita',
                code: 'Código',
                code_block: 'Bloque de código',
                link: 'Enlace',
                horizontal_rule: 'Separador',
                image: 'Imagen',
                undo: 'Deshacer',
                redo: 'Rehacer',
            },
            link: {
                label: 'Dirección del enlace',
                placeholder: 'https://…',
                apply: 'Aplicar',
                remove: 'Quitar enlace',
                cancel: 'Cancelar',
            },
            images: {
                uploading: 'Subiendo la imagen…',
                uploaded: 'Imagen añadida',
                alt: 'Descripción de la imagen',
            },
            mentions: {
                label: 'Personas que puedes mencionar',
                none: 'Nadie con ese nombre puede ver este tema',
            },
            draft: {
                restored: 'Hemos recuperado el borrador que dejaste a medias.',
                discard: 'Descartarlo',
            },
            errors: {
                empty: 'Escribe algo antes de enviar.',
                link: 'Los enlaces deben ir a una dirección web (http o https) o de correo (mailto).',
                upload: 'No se ha podido subir la imagen. Inténtalo de nuevo.',
                session: 'Tu sesión ha caducado. Recarga la página para seguir.',
                too_many_images: 'Un mensaje puede llevar {max} imágenes como mucho.',
            },
        },
    },
};
