import { Extension, textblockTypeInputRule } from '@tiptap/core';
import type { InputRule } from '@tiptap/core';

/**
 * «# » at the start of a line makes a heading of the first level a post may use (2), as in
 * Markdown; «## » and deeper come from the heading extension itself.
 */
export const HeadingShortcut = Extension.create({
    name: 'forumHeadingShortcut',

    addInputRules(): InputRule[] {
        const heading = this.editor.schema.nodes['heading'];

        return heading === undefined
            ? []
            : [
                  textblockTypeInputRule({
                      find: /^#\s$/,
                      type: heading,
                      getAttributes: { level: 2 },
                  }),
              ];
    },
});
