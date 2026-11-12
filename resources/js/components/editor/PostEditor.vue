<script setup lang="ts">
import type { AnyExtension, JSONContent } from '@tiptap/core';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import BaseButton from '@/components/ui/BaseButton.vue';
import { buildImageUrl } from '@/utils/cloudinary';
import { csrfHeaders, isCsrfFailure } from '@/utils/csrf';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    embedServiceOf,
    type EmbedService,
    IMAGE_ORIGIN_EXTERNAL,
    IMAGE_ORIGIN_UPLOAD,
    IMAGE_TYPES,
    isAllowedImageAddress,
    isDocumentEmpty,
    looksLikeImageFile,
    MAX_IMAGES,
    normalizeHref,
    storedImageUrl,
} from '../../editor/document';
import { postDraft } from '../../editor/draft';
import EditorToolbar from '../../editor/EditorToolbar.vue';
import { createForumExtensions } from '../../editor/extensions';
import { paragraphsFromText } from '../../editor/extensions/PostQuote';
import { mentionSuggestion } from '../../editor/mentionSuggestion';
import type {
    EmbedAnswer,
    ExternalImage,
    MentionCandidate,
    QuoteSource,
    ToolbarItem,
    UploadedImage,
} from '../../editor/types';

/**
 * The forum's post editor. It edits the document the server stores (TipTap JSON checked
 * against the server's schema, never HTML) and leaves sending it to the page: `submit` gives
 * the document, and the page calls `clear()` once it is published.
 *
 * Other features add nodes through `extensions` (with their node type registered on the
 * server) and buttons through `toolbarItems`.
 */
const props = withDefaults(
    defineProps<{
        /** Accessible name of the writing area («Tu respuesta») */
        label: string;
        /** The document to start from (editing a post); a saved draft wins over it */
        modelValue?: JSONContent | null;
        placeholder?: string;
        /** Where the draft is kept in this browser (one per discussion, or per post being edited); null keeps none */
        draftKey?: string | null;
        /** The discussion's mention candidates endpoint; null offers no mentions */
        mentionsUrl?: string | null;
        /** The image upload endpoint; null offers no images (no permission to upload) */
        uploadUrl?: string | null;
        /** Images one post may hold, from the forum settings */
        maxImages?: number;
        /** Image formats the forum settings allow, as MIME types */
        imageTypes?: string[];
        /** Where to ask for the forum's copy of an image from another site; null offers none (no permission) */
        externalImageUrl?: string | null;
        /** The sites images may come from, each with its subdomains */
        externalImageHosts?: string[];
        /** Where to read a link to a post of another service; null offers no embeds */
        embedUrl?: string | null;
        /** The services whose posts the forum embeds */
        embedServices?: EmbedService[];
        /** Names of the accounts the starting document mentions, by id */
        mentionNames?: Record<string, string>;
        extensions?: AnyExtension[];
        toolbarItems?: ToolbarItem[];
        /** Text of the send button; without it the page brings its own */
        submitLabel?: string | null;
        submitting?: boolean;
        disabled?: boolean;
        /** An error from the page (the server refused the post) */
        error?: string | null;
    }>(),
    {
        modelValue: null,
        placeholder: '',
        draftKey: null,
        mentionsUrl: null,
        uploadUrl: null,
        maxImages: MAX_IMAGES,
        imageTypes: () => [...IMAGE_TYPES],
        externalImageUrl: null,
        externalImageHosts: () => [],
        embedUrl: null,
        embedServices: () => [],
        mentionNames: () => ({}),
        extensions: () => [],
        toolbarItems: () => [],
        submitLabel: null,
        submitting: false,
        disabled: false,
        error: null,
    }
);

const emit = defineEmits<{
    'update:modelValue': [document: JSONContent];
    submit: [document: JSONContent];
}>();

const { t } = useI18n();

const DRAFT_DELAY_MS = 600;

const names = reactive(new Map<string, string>(Object.entries(props.mentionNames)));
watch(
    () => props.mentionNames,
    (value) => Object.entries(value).forEach(([id, name]) => names.set(id, name))
);

const draft = postDraft(props.draftKey);
const restored = draft.load();
const draftRestored = ref(restored !== null);
const localError = ref<string | null>(null);
const status = ref('');
const uploading = ref(0);
const fileInput = ref<HTMLInputElement | null>(null);
const linkOpen = ref(false);
const linkInput = ref('');
const linkField = ref<HTMLInputElement | null>(null);
const externalOpen = ref(false);
const externalInput = ref('');
const externalField = ref<HTMLInputElement | null>(null);
const embedOpen = ref(false);
const embedInput = ref('');
const embedField = ref<HTMLInputElement | null>(null);
/** A link just pasted that could be embedded, offered until it is taken or dismissed */
const embedOffer = ref<{ address: string; label: string } | null>(null);
/** What the server said of the posts embedded while writing, by «service id» */
const embedCards = reactive(new Map<string, EmbedAnswer>());
const canEmbed = computed(() => props.embedUrl !== null && props.embedServices.length > 0);
const canTakeExternalImages = computed(
    () => props.externalImageUrl !== null && props.externalImageHosts.length > 0
);
let draftTimer: ReturnType<typeof setTimeout> | null = null;

const editor = useEditor({
    content: restored ?? props.modelValue ?? '',
    editable: !props.disabled,
    extensions: createForumExtensions({
        placeholder: props.placeholder,
        oocLabel: t('forum.editor.oocLabel'),
        describeEmbed: (service, id) => {
            const card = embedCards.get(`${service} ${id}`);

            return {
                label:
                    card?.label ??
                    props.embedServices.find((known) => known.key === service)?.label ??
                    service,
                title: card?.card.title ?? card?.url ?? null,
            };
        },
        imageUrl: (path) => storedImageUrl(path, (publicId) => buildImageUrl(publicId)),
        mentionNames: { nameOf: (id) => names.get(id) ?? null },
        mentionSuggestion:
            props.mentionsUrl === null
                ? null
                : mentionSuggestion({
                      url: props.mentionsUrl,
                      onPick: (candidate: MentionCandidate) =>
                          names.set(candidate.id, candidate.name),
                  }),
        extra: props.extensions,
    }),
    editorProps: {
        attributes: {
            class: 'forum-editor__content prose dark:prose-invert max-w-none min-h-32 px-3 py-2 focus:outline-none',
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': props.label,
        },
        handleKeyDown: (_view, event) => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                submit();

                return true;
            }
            if (event.key.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey)) {
                openLink();

                return true;
            }

            return false;
        },
        handlePaste: (_view, event) =>
            uploadFiles(event.clipboardData?.files) ||
            pasteExternalImage(event.clipboardData?.getData('text/plain') ?? '') ||
            offerEmbed(event.clipboardData?.getData('text/plain') ?? ''),
        handleDrop: (_view, event) => uploadFiles((event as DragEvent).dataTransfer?.files),
    },
    onUpdate: ({ editor: current }) => {
        const document = current.getJSON();
        localError.value = null;
        emit('update:modelValue', document);
        scheduleDraft(document);
    },
});

watch(
    () => props.disabled,
    (disabled) => editor.value?.setEditable(!disabled)
);

function scheduleDraft(document: JSONContent): void {
    if (draftTimer !== null) {
        clearTimeout(draftTimer);
    }
    draftTimer = setTimeout(() => {
        draftTimer = null;
        if (isDocumentEmpty(document)) {
            draft.clear();
        } else {
            draft.save(document);
        }
    }, DRAFT_DELAY_MS);
}

onBeforeUnmount(() => {
    if (draftTimer !== null) {
        clearTimeout(draftTimer);
    }
});

const shownError = computed(() => localError.value ?? props.error);
const imageSelected = computed(() => editor.value?.isActive('image') ?? false);
const imageAlt = computed({
    get: () => String(editor.value?.getAttributes('image')['alt'] ?? ''),
    set: (alt: string) =>
        editor.value
            ?.chain()
            .updateAttributes('image', { alt: alt === '' ? null : alt })
            .run(),
});

function getDocument(): JSONContent | null {
    return editor.value?.getJSON() ?? null;
}

function submit(): void {
    const document = getDocument();
    if (document === null || props.submitting || props.disabled || uploading.value > 0) {
        return;
    }
    if (isDocumentEmpty(document)) {
        localError.value = t('forum.editor.errors.empty');

        return;
    }

    emit('submit', document);
}

/** Empties the editor and forgets the draft: call it once the post is published */
function clear(): void {
    editor.value?.commands.clearContent(true);
    draft.clear();
    draftRestored.value = false;
    localError.value = null;
}

function clearDraft(): void {
    draft.clear();
    draftRestored.value = false;
}

function discardDraft(): void {
    clearDraft();
    editor.value?.commands.setContent(props.modelValue ?? '', { emitUpdate: true });
}

function focus(): void {
    editor.value?.commands.focus('end');
}

/** Quotes a post (all of it, or the text selected in it) at the end, with room to answer below */
function quote(source: QuoteSource): void {
    const content = source.content ?? paragraphsFromText(source.text ?? '');
    editor.value?.commands.insertPostQuote(source.postId, content);
}

function openLink(): void {
    linkInput.value = String(editor.value?.getAttributes('link')['href'] ?? '');
    linkOpen.value = true;
    localError.value = null;
    requestAnimationFrame(() => linkField.value?.focus());
}

function applyLink(): void {
    const href = normalizeHref(linkInput.value);
    if (href === null) {
        localError.value = t('forum.editor.errors.link');

        return;
    }

    const chain = editor.value?.chain().focus().extendMarkRange('link');
    if (editor.value?.state.selection.empty && !editor.value.isActive('link')) {
        chain
            ?.insertContent({
                type: 'text',
                text: href,
                marks: [{ type: 'link', attrs: { href } }],
            })
            .run();
    } else {
        chain?.setLink({ href }).run();
    }
    linkOpen.value = false;
}

function removeLink(): void {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
    linkOpen.value = false;
}

function closeLink(): void {
    linkOpen.value = false;
    editor.value?.commands.focus();
}

function chooseImage(): void {
    fileInput.value?.click();
}

function onFilesChosen(event: Event): void {
    const input = event.target as HTMLInputElement;
    uploadFiles(input.files);
    input.value = '';
}

function imageCount(): number {
    let count = 0;
    editor.value?.state.doc.descendants((node) => {
        count += node.type.name === 'image' ? 1 : 0;
    });

    return count;
}

function openEmbed(): void {
    embedInput.value = '';
    embedOpen.value = true;
    localError.value = null;
    requestAnimationFrame(() => embedField.value?.focus());
}

function closeEmbed(): void {
    embedOpen.value = false;
    editor.value?.commands.focus();
}

function applyEmbed(): void {
    const address = embedInput.value.trim();
    if (address === '') {
        return;
    }
    embedOpen.value = false;
    void fetchEmbed(address, props.embedUrl as string);
}

/**
 * A pasted link to a post of a service the forum embeds is pasted as a link, and the editor
 * offers to embed it. Always false: the paste goes on as usual.
 */
function offerEmbed(text: string): boolean {
    const address = text.trim();
    const service =
        canEmbed.value && !/\s/u.test(address)
            ? embedServiceOf(address, props.embedServices)
            : null;
    embedOffer.value = service === null ? null : { address, label: service.label };

    return false;
}

function acceptEmbedOffer(): void {
    const offer = embedOffer.value;
    embedOffer.value = null;
    if (offer !== null) {
        void fetchEmbed(offer.address, props.embedUrl as string);
    }
}

/** Asks the server what the link is and inserts the post; the card comes from the server too */
async function fetchEmbed(address: string, url: string): Promise<void> {
    uploading.value++;
    status.value = t('forum.editor.embed.fetching');
    localError.value = null;

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                ...csrfHeaders(),
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ url: address }),
        });

        if (!response.ok) {
            const answer = (await response.json().catch(() => ({}))) as { message?: string };
            localError.value = isCsrfFailure(response)
                ? t('forum.editor.errors.session')
                : (answer.message ?? t('forum.editor.errors.embed'));

            return;
        }

        const embed = (await response.json()) as EmbedAnswer;
        embedCards.set(`${embed.service} ${embed.id}`, embed);
        editor.value?.chain().focus().insertEmbed(embed.service, embed.id).run();
        status.value = t('forum.editor.embed.fetched', { service: embed.label });
    } catch {
        localError.value = t('forum.editor.errors.embed');
    } finally {
        uploading.value--;
    }
}

function openExternalImage(): void {
    externalInput.value = '';
    externalOpen.value = true;
    localError.value = null;
    requestAnimationFrame(() => externalField.value?.focus());
}

function closeExternalImage(): void {
    externalOpen.value = false;
    editor.value?.commands.focus();
}

function applyExternalImage(): void {
    if (takeExternalImage(externalInput.value.trim())) {
        externalOpen.value = false;
    }
}

/**
 * A pasted address of an image file on one of the sites becomes that image; any other text is
 * pasted as usual (an address becomes a link).
 */
function pasteExternalImage(text: string): boolean {
    const address = text.trim();
    if (
        !canTakeExternalImages.value ||
        /\s/u.test(address) ||
        !looksLikeImageFile(address) ||
        !isAllowedImageAddress(address, props.externalImageHosts)
    ) {
        return false;
    }

    takeExternalImage(address);

    return true;
}

/** Asks the server for its copy of the image; false when the address cannot be taken */
function takeExternalImage(address: string): boolean {
    if (!isAllowedImageAddress(address, props.externalImageHosts)) {
        localError.value = t('forum.editor.errors.external_host', {
            sites: props.externalImageHosts.join(', '),
        });

        return false;
    }
    if (imageCount() + uploading.value + 1 > props.maxImages) {
        localError.value = t('forum.editor.errors.too_many_images', { max: props.maxImages });

        return false;
    }

    void fetchExternalImage(address, props.externalImageUrl as string);

    return true;
}

async function fetchExternalImage(address: string, url: string): Promise<void> {
    uploading.value++;
    status.value = t('forum.editor.externalImage.fetching');
    localError.value = null;

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                ...csrfHeaders(),
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ url: address }),
        });

        if (!response.ok) {
            const answer = (await response.json().catch(() => ({}))) as { message?: string };
            localError.value = isCsrfFailure(response)
                ? t('forum.editor.errors.session')
                : (answer.message ?? t('forum.editor.errors.external_fetch'));

            return;
        }

        const image = (await response.json()) as ExternalImage;
        editor.value
            ?.chain()
            .focus()
            .insertContent({
                type: 'image',
                attrs: {
                    origin: IMAGE_ORIGIN_EXTERNAL,
                    src: image.url,
                    path: image.path,
                    alt: null,
                },
            })
            .run();
        status.value = t('forum.editor.externalImage.fetched');
    } catch {
        localError.value = t('forum.editor.errors.external_fetch');
    } finally {
        uploading.value--;
    }
}

/** Uploads the images among the files; true when it took them (so a paste or drop stops there) */
function uploadFiles(files: FileList | null | undefined): boolean {
    const images = Array.from(files ?? []).filter((file) => props.imageTypes.includes(file.type));
    if (props.uploadUrl === null || images.length === 0) {
        return false;
    }

    if (imageCount() + uploading.value + images.length > props.maxImages) {
        localError.value = t('forum.editor.errors.too_many_images', { max: props.maxImages });

        return true;
    }

    images.forEach((file) => void upload(file, props.uploadUrl as string));

    return true;
}

async function upload(file: File, url: string): Promise<void> {
    uploading.value++;
    status.value = t('forum.editor.images.uploading');
    localError.value = null;

    try {
        const body = new FormData();
        body.append('image', file);
        const response = await fetch(url, {
            method: 'POST',
            headers: { ...csrfHeaders(), Accept: 'application/json' },
            credentials: 'same-origin',
            body,
        });

        if (!response.ok) {
            const answer = (await response.json().catch(() => ({}))) as { message?: string };
            localError.value = isCsrfFailure(response)
                ? t('forum.editor.errors.session')
                : (answer.message ?? t('forum.editor.errors.upload'));

            return;
        }

        const image = (await response.json()) as UploadedImage;
        editor.value
            ?.chain()
            .focus()
            .insertContent({
                type: 'image',
                attrs: { origin: IMAGE_ORIGIN_UPLOAD, src: image.path, path: null, alt: null },
            })
            .run();
        status.value = t('forum.editor.images.uploaded');
    } catch {
        localError.value = t('forum.editor.errors.upload');
    } finally {
        uploading.value--;
    }
}

defineExpose({ focus, clear, clearDraft, quote, getDocument, submit });
</script>

<template>
    <div
        class="forum-editor bg-surface border-default overflow-hidden rounded-lg border"
        :aria-busy="uploading > 0 || submitting"
    >
        <EditorToolbar
            v-if="editor"
            :editor="editor"
            :items="toolbarItems"
            :can-upload-images="uploadUrl !== null"
            :can-take-external-images="canTakeExternalImages"
            :can-embed="canEmbed"
            :disabled="disabled"
            @link="openLink"
            @image="chooseImage"
            @external-image="openExternalImage"
            @embed="openEmbed"
        />

        <form
            v-if="embedOpen"
            class="border-default flex flex-wrap items-center gap-2 border-b px-3 py-2"
            @submit.prevent="applyEmbed"
            @keydown.escape.prevent="closeEmbed"
        >
            <label for="forum-editor-embed" class="text-base-secondary w-full text-sm sm:w-auto">{{
                t('forum.editor.embed.label')
            }}</label>
            <input
                id="forum-editor-embed"
                ref="embedField"
                v-model="embedInput"
                type="url"
                inputmode="url"
                autocomplete="off"
                :placeholder="t('forum.editor.embed.placeholder')"
                aria-describedby="forum-editor-embed-hint"
                class="bg-page border-default text-base-primary w-full min-w-0 rounded-md border px-2 py-1 text-sm sm:w-auto sm:flex-1"
            />
            <BaseButton type="submit" size="sm">{{ t('forum.editor.embed.apply') }}</BaseButton>
            <BaseButton variant="ghost" size="sm" @click="closeEmbed">{{
                t('forum.editor.embed.cancel')
            }}</BaseButton>
            <p id="forum-editor-embed-hint" class="text-base-muted w-full text-xs">
                {{
                    t('forum.editor.embed.hint', {
                        services: embedServices.map((service) => service.label).join(', '),
                    })
                }}
            </p>
        </form>

        <div
            v-if="embedOffer"
            class="border-default bg-info-light text-info flex flex-wrap items-center gap-2 border-b px-3 py-1.5 text-sm"
            role="status"
        >
            <span class="min-w-0 flex-1">{{
                t('forum.editor.embed.offer', { service: embedOffer.label })
            }}</span>
            <BaseButton size="sm" @click="acceptEmbedOffer">{{
                t('forum.editor.embed.offer_accept')
            }}</BaseButton>
            <BaseButton variant="ghost" size="sm" @click="embedOffer = null">{{
                t('forum.editor.embed.offer_dismiss')
            }}</BaseButton>
        </div>

        <form
            v-if="externalOpen"
            class="border-default flex flex-wrap items-center gap-2 border-b px-3 py-2"
            @submit.prevent="applyExternalImage"
            @keydown.escape.prevent="closeExternalImage"
        >
            <label
                for="forum-editor-external-image"
                class="text-base-secondary w-full text-sm sm:w-auto"
                >{{ t('forum.editor.externalImage.label') }}</label
            >
            <input
                id="forum-editor-external-image"
                ref="externalField"
                v-model="externalInput"
                type="url"
                inputmode="url"
                autocomplete="off"
                :placeholder="t('forum.editor.externalImage.placeholder')"
                aria-describedby="forum-editor-external-image-hint"
                class="bg-page border-default text-base-primary w-full min-w-0 rounded-md border px-2 py-1 text-sm sm:w-auto sm:flex-1"
            />
            <BaseButton type="submit" size="sm">{{
                t('forum.editor.externalImage.apply')
            }}</BaseButton>
            <BaseButton variant="ghost" size="sm" @click="closeExternalImage">{{
                t('forum.editor.externalImage.cancel')
            }}</BaseButton>
            <p id="forum-editor-external-image-hint" class="text-base-muted w-full text-xs">
                {{ t('forum.editor.externalImage.hint', { sites: externalImageHosts.join(', ') }) }}
            </p>
        </form>

        <form
            v-if="linkOpen"
            class="border-default flex flex-wrap items-center gap-2 border-b px-3 py-2"
            @submit.prevent="applyLink"
            @keydown.escape.prevent="closeLink"
        >
            <label for="forum-editor-link" class="text-base-secondary text-sm">{{
                t('forum.editor.link.label')
            }}</label>
            <input
                id="forum-editor-link"
                ref="linkField"
                v-model="linkInput"
                type="url"
                inputmode="url"
                autocomplete="url"
                :placeholder="t('forum.editor.link.placeholder')"
                class="bg-page border-default text-base-primary min-w-0 flex-1 rounded-md border px-2 py-1 text-sm"
            />
            <BaseButton type="submit" size="sm">{{ t('forum.editor.link.apply') }}</BaseButton>
            <BaseButton
                v-if="editor?.isActive('link')"
                variant="ghost"
                size="sm"
                @click="removeLink"
            >
                {{ t('forum.editor.link.remove') }}
            </BaseButton>
            <BaseButton variant="ghost" size="sm" @click="closeLink">{{
                t('forum.editor.link.cancel')
            }}</BaseButton>
        </form>

        <div
            v-if="imageSelected"
            class="border-default flex flex-wrap items-center gap-2 border-b px-3 py-2"
        >
            <label for="forum-editor-alt" class="text-base-secondary text-sm">{{
                t('forum.editor.images.alt')
            }}</label>
            <input
                id="forum-editor-alt"
                v-model.lazy="imageAlt"
                type="text"
                maxlength="300"
                class="bg-page border-default text-base-primary min-w-0 flex-1 rounded-md border px-2 py-1 text-sm"
            />
        </div>

        <p
            v-if="draftRestored"
            class="bg-info-light text-info flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm"
        >
            {{ t('forum.editor.draft.restored') }}
            <button type="button" class="underline underline-offset-2" @click="discardDraft">
                {{ t('forum.editor.draft.discard') }}
            </button>
        </p>

        <EditorContent :editor="editor" />

        <input
            ref="fileInput"
            type="file"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            :accept="imageTypes.join(',')"
            multiple
            @change="onFilesChosen"
        />

        <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
        <p v-if="shownError" class="text-error px-3 pb-2 text-sm" role="alert">{{ shownError }}</p>

        <div
            v-if="submitLabel !== null || $slots['actions']"
            class="border-default flex flex-wrap items-center justify-end gap-2 border-t px-3 py-2"
        >
            <span class="text-base-muted mr-auto hidden text-xs sm:inline">{{
                t('forum.editor.submit_hint')
            }}</span>
            <slot name="actions" />
            <BaseButton
                v-if="submitLabel !== null"
                :loading="submitting"
                :disabled="disabled || uploading > 0"
                @click="submit"
            >
                {{ submitLabel }}
            </BaseButton>
        </div>
    </div>
</template>
