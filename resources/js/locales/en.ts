export default {
    forum: {
        title: 'Forum',
        spaces: {
            moderationNotice:
                'This space is private, but the forum moderators may enter it to handle reports.',
        },
        editor: {
            submit_hint: 'Ctrl + Enter to send',
            toolbar: {
                label: 'Message formatting',
                bold: 'Bold',
                italic: 'Italic',
                strike: 'Strikethrough',
                heading: 'Heading {level}',
                bullet_list: 'List',
                ordered_list: 'Numbered list',
                blockquote: 'Quote',
                code: 'Code',
                code_block: 'Code block',
                link: 'Link',
                horizontal_rule: 'Divider',
                image: 'Image',
                undo: 'Undo',
                redo: 'Redo',
            },
            link: {
                label: 'Link address',
                placeholder: 'https://…',
                apply: 'Apply',
                remove: 'Remove link',
                cancel: 'Cancel',
            },
            images: {
                uploading: 'Uploading the image…',
                uploaded: 'Image added',
                alt: 'Image description',
            },
            mentions: {
                label: 'People you can mention',
                none: 'Nobody with that name can see this discussion',
            },
            draft: {
                restored: 'We brought back the draft you left unfinished.',
                discard: 'Discard it',
            },
            errors: {
                empty: 'Write something before sending.',
                link: 'Links must go to a web address (http or https) or an email address (mailto).',
                upload: 'The image could not be uploaded. Try again.',
                session: 'Your session expired. Reload the page to go on.',
                too_many_images: 'A message can hold {max} images at most.',
            },
        },
    },
};
