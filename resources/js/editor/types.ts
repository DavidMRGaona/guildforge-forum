import type { Editor, JSONContent } from '@tiptap/core';
import type { Component } from 'vue';

/** A person the editor offers after «@» (MentionCandidatesController) */
export interface MentionCandidate {
    id: string;
    name: string;
    avatarUrl: string | null;
}

/**
 * A button another feature adds to the toolbar (a dice roll, an aside). `label` is already
 * translated and names the button for screen readers.
 */
export interface ToolbarItem {
    id: string;
    label: string;
    icon: Component;
    run: (editor: Editor) => void;
    isActive?: (editor: Editor) => boolean;
}

/** A post to quote: all of it (its document) or the text someone selected */
export interface QuoteSource {
    postId: string;
    content?: JSONContent[];
    text?: string;
}

/** What the endpoint for images from other sites answers (ExternalImageController) */
export interface ExternalImage {
    /** The address, as the image node keeps it */
    url: string;
    /** The forum's copy */
    path: string;
    previewUrl: string | null;
}

/** What the endpoint that reads a link to a post of another service answers (EmbedController) */
export interface EmbedAnswer {
    service: string;
    id: string;
    label: string;
    url: string;
    card: {
        available: boolean;
        title: string | null;
        authorName: string | null;
        thumbnailUrl: string | null;
    };
}

/** What the image upload endpoint answers (PostImageController) */
export interface UploadedImage {
    path: string;
    url: string | null;
}
