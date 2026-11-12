import type { EmbedService } from '../editor/document';
import type { JSONContent } from '@tiptap/core';

/** Props the forum pages receive from the server (see ForumPresenter). */

export interface ForumTopic {
    key: string;
    label: string;
}

export interface ForumLastPost {
    discussionTitle: string;
    url: string;
    authorName: string | null;
    at: string | null;
    atLabel: string;
}

export interface ForumCategoryCard {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    url: string;
    color: string | null;
    icon: string | null;
    discussionCount: number;
    postCount: number;
    lastPost: ForumLastPost | null;
    children: ForumCategoryCard[];
}

export interface ForumCategoryHeader {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    url: string;
    color: string | null;
    icon: string | null;
    parent: { name: string; url: string } | null;
    canModerate: boolean;
    isPublicationSpace: boolean;
    readOnly: boolean;
    /** A module it depends on is missing: only the forum's moderators reach it */
    unavailable: boolean;
    /** Only on the category page, and null for guests */
    follow?: ForumCategoryFollow | null;
}

export interface ForumCategoryFollow {
    following: boolean;
    url: string;
}

export type ForumFollowLevel = 'following' | 'normal' | 'muted';

export interface ForumDiscussionFollow {
    level: ForumFollowLevel;
    url: string;
}

export interface ForumLikes {
    count: number;
    liked: boolean;
    /** Names of the latest likes, newest first */
    names: string[];
    /** Likes beyond the names shown */
    others: number;
}

export interface ForumPostLikes extends ForumLikes {
    canLike: boolean;
    /** Where to like (POST) and take it back (DELETE); null when the reader can do neither */
    url: string | null;
}

export interface ForumDiscussionRow {
    id: string;
    number: number;
    title: string;
    url: string;
    /** Where reading resumes (first unread post); null when never opened or for guests */
    resumeUrl: string | null;
    category: { name: string; url: string; color: string | null } | null;
    authorName: string | null;
    topics: ForumTopic[];
    replyCount: number;
    lastPost: {
        authorName: string | null;
        at: string | null;
        atLabel: string;
        url: string | null;
    };
    /** The number of its last post when the page was drawn; null without visible posts */
    lastPostNumber: number | null;
    isPinned: boolean;
    isClosed: boolean;
    isHidden: boolean;
    /** null for guests: without a session there is no unread state */
    isUnread: boolean | null;
    unreadCount: number | null;
}

export interface ForumDiscussionList {
    pinned: ForumDiscussionRow[];
    items: ForumDiscussionRow[];
    nextUrl: string | null;
    firstUrl: string | null;
}

export interface ForumListFilters {
    topic: string | null;
    unread: boolean;
    following: boolean;
    topics: ForumTopic[];
    canFilterUnread: boolean;
    baseUrl: string;
}

export type ForumPostStatus = 'visible' | 'hidden' | 'pending' | 'deleted';

export interface ForumPost {
    id: string;
    number: number | null;
    authorId: string | null;
    authorName: string | null;
    isStarter: boolean;
    status: ForumPostStatus;
    html: string | null;
    placeholder: string | null;
    publishedAt: string | null;
    publishedLabel: string;
    editedAt: string | null;
    editedLabel: string | null;
    url: string | null;
    canEdit: boolean;
    canDelete: boolean;
    /** The stored document, only for posts the reader may edit */
    document: JSONContent | null;
    updateUrl: string | null;
    deleteUrl: string | null;
    likes: ForumPostLikes;
    /** Why moderation hid it, only for its author and its moderators */
    hiddenReason: string | null;
    /** Where its moderation menu sends its actions; null for whoever does not moderate it */
    moderationUrl: string | null;
    /** Where to report it; null for its author, guests and whoever is suspended */
    reportUrl: string | null;
    /** Who it speaks as, when the reader may see that character; null out of character */
    character: ForumPostCharacter | null;
    /** The character its editor starts from, only for posts the reader may edit */
    characterId: string | null;
}

export interface ForumConversation {
    id: string;
    number: number;
    title: string;
    url: string;
    isClosed: boolean;
    isPinned: boolean;
    isHidden: boolean;
    /** Its first post awaits approval: only its author and its moderators see it */
    isAwaitingApproval: boolean;
    topics: ForumTopic[];
    category: ForumCategoryHeader;
    /** null for guests */
    follow: ForumDiscussionFollow | null;
}

export interface ForumPreferenceCategory {
    id: string;
    name: string;
    parentName: string | null;
    url: string;
    followUrl: string;
}

export interface ForumPreferenceDiscussion {
    id: string;
    title: string;
    url: string;
    followUrl: string;
}

export interface ForumPreferences {
    autoFollow: boolean;
    updateUrl: string;
    categories: ForumPreferenceCategory[];
    discussions: ForumPreferenceDiscussion[];
    forumUrl: string;
}

export interface ForumMoveTarget {
    id: string;
    name: string;
    parentName: string | null;
}

/** What the discussion's moderation menu offers; null for whoever does not moderate it */
export interface ForumDiscussionModeration {
    /** The forum's moderation page: reports and posts awaiting approval */
    queueUrl: string;
    /** Only for whoever moderates the whole forum */
    suspendUrl: string | null;
    actionsUrl: string;
    /** Only for whoever moderates the whole forum */
    deleteUrl: string | null;
    moveTargets: ForumMoveTarget[];
    /** Splitting posts off and merging into another discussion; null for publications' discussions */
    splitUrl: string | null;
    mergeUrl: string | null;
    /** Finds the discussion to merge into by its number or address (`?tema=`) */
    mergeTargetUrl: string | null;
    /** Where split posts may go: the discussion's own category first */
    splitCategories: ForumMoveTarget[];
    limits: {
        titleMin: number;
        titleMax: number;
        reasonMax: number;
        hiddenReasonMax: number;
        splitMax: number;
    };
}

/** Numbers whose posts moderation moved to another discussion, shown in their place */
export interface ForumMovedPosts {
    fromNumber: number;
    throughNumber: number;
    count: number;
    /** null for whoever may not read where they went (or once it is gone) */
    title: string | null;
    url: string | null;
}

/** The discussion a merge would join, as the server found it */
export interface ForumMergeTarget {
    id: string;
    number: number;
    title: string;
    url: string;
    categoryName: string | null;
}

export interface ForumPagination {
    page: number;
    pageCount: number;
    perPage: number;
    highestNumber: number;
    firstUrl: string | null;
    previousUrl: string | null;
    nextUrl: string | null;
    lastUrl: string | null;
}

export interface ForumEditorSettings {
    uploadUrl: string | null;
    maxImages: number;
    imageTypes: string[];
    /** Where to ask for the forum's copy of an image from another site; null without the permission */
    externalImageUrl: string | null;
    /** The sites images may come from (each with its subdomains) */
    externalImageHosts: string[];
    /** Where to read a link to a post of another service; null without session or services */
    embedUrl: string | null;
    /** The services whose posts the forum embeds */
    embedServices: EmbedService[];
}

/** Why and until when the reader may not write; null unless they are suspended */
export interface ForumSuspensionNotice {
    reason: string;
    /** null: until moderation lifts it */
    untilLabel: string | null;
}

export interface ForumReplySettings extends ForumEditorSettings {
    canPost: boolean;
    postUrl: string;
    mentionsUrl: string | null;
    /** Why the reader may not write: a suspension in the forum */
    suspension?: ForumSuspensionNotice | null;
    /** «Hablar como»: the characters the reader may write as here; null when characters are off for them */
    characters?: ForumCharacterPicker | null;
}

export interface ForumCategoryOption {
    id: string;
    name: string;
    parentName: string | null;
    canModerate: boolean;
}

export type ForumSubjectClosedReason = 'cancelled' | 'withdrawn' | 'moderation' | 'closed';

/** The conversation a record's page shows (SubjectConversationController) */
export interface ForumSubjectConversation {
    discussion: {
        id: string;
        number: number;
        title: string;
        url: string;
        isClosed: boolean;
        closedReason: ForumSubjectClosedReason | null;
    };
    /** Its last posts (or a run before a number), without the forum's opening post */
    posts: ForumPost[];
    mentionNames: Record<string, string>;
    /** Posts before the run, the opening post aside */
    olderCount: number;
    /** How many of those the next "earlier ones" brings */
    olderNext: number;
    olderUrl: string | null;
    highestNumber: number;
    lastReadNumber: number | null;
    readUrl: string | null;
    reply: ForumReplySettings;
}

/** A post with open reports, in the forum's moderation page */
export interface ForumReportedItem {
    postId: string;
    status: ForumPostStatus;
    excerpt: string;
    authorName: string | null;
    discussionTitle: string;
    url: string;
    reports: number;
    /** Each reason once, already in words */
    reasons: string[];
    notes: string[];
    firstReportedLabel: string;
    resolveUrl: string;
}

/** A post awaiting approval, in the forum's moderation page */
export interface ForumPendingItem {
    postId: string;
    excerpt: string;
    authorName: string | null;
    discussionTitle: string;
    url: string;
    opensDiscussion: boolean;
    writtenLabel: string;
    moderationUrl: string;
}

export interface ForumSearchCategoryOption {
    slug: string;
    name: string;
    /** 0 for a top-level category, 1 for a subcategory */
    depth: number;
}

export interface ForumSearchSnippetPart {
    text: string;
    match: boolean;
}

export interface ForumSearchItem {
    /** A discussion (title and opening message) or one of its replies */
    kind: 'discussion' | 'post';
    title: string;
    url: string;
    categoryName: string | null;
    categoryUrl: string | null;
    postNumber: number | null;
    snippet: ForumSearchSnippetPart[];
    publishedAt: string | null;
    publishedLabel: string | null;
}

export interface ForumSearchResults {
    items: ForumSearchItem[];
    total: number;
    page: number;
    /** The search matches more than what was checked */
    truncated: boolean;
    prevUrl: string | null;
    nextUrl: string | null;
}

/** What a page's search box needs: where it goes, the categories to look in and the one chosen. */
export interface ForumSearchBox {
    url: string;
    category: string | null;
    categories: ForumSearchCategoryOption[];
}

/** Where a discussion went when moderation merged it into another (what a 410 answer carries) */
export interface ForumMergedInto {
    title: string;
    url: string;
}

/** What the endpoint of new posts answers (when there are any) */
export interface ForumNewPosts {
    posts: ForumPost[];
    mentionNames: Record<string, string>;
    highestNumber: number;
    /** More posts wait: the page asks again at once */
    more: boolean;
}

/** What the forum's summary answers: unread followed discussions, and the last post of the ones asked about */
export interface ForumPulse {
    unreadFollowed: number;
    discussions: Record<string, number>;
}

/** What every character shows: its name and its portrait, or its initials without one */
export interface ForumCharacterBasics {
    id: string;
    name: string;
    initials: string;
    portraitUrl: string | null;
}

/** What may be done with a character: its owner's and its moderators' addresses */
export interface ForumCharacterActions {
    updateUrl: string;
    /** null once retired (for its owner) or retired by moderation */
    retireUrl: string | null;
    /** null unless retired and the viewer may bring it back */
    restoreUrl: string | null;
    deleteUrl: string;
}

/** Who a post speaks as */
export interface ForumPostCharacter extends ForumCharacterBasics {
    description: string | null;
    isRetired: boolean;
    /** For whoever moderates it; null for everyone else */
    actions: ForumCharacterActions | null;
}

export interface ForumCharacterCategory {
    id: string;
    name: string;
    parentId: string | null;
    parentName: string | null;
}

export interface ForumCharacterLimits {
    nameMax: number;
    descriptionMax: number;
    portraitKilobytes: number;
    /** MIME types */
    portraitTypes: string[];
    perAccount: number;
}

/** What the character form needs */
export interface ForumCharacterForm {
    /** null when the person may not create characters */
    createUrl: string | null;
    /** Where a character of the person may be tied */
    categories: ForumCharacterCategory[];
    limits: ForumCharacterLimits;
}

export interface ForumCharacterOption extends ForumCharacterBasics {
    /** Where it may be used, when the page chooses the category (starting a discussion) */
    categoryIds?: string[];
}

/** The editor's «Hablar como» */
export interface ForumCharacterPicker extends ForumCharacterForm {
    items: ForumCharacterOption[];
    /** The last character the person used in the discussion, still usable */
    suggestedId: string | null;
    /** The category the post is written in; null where the page chooses it */
    categoryId: string | null;
}

/** A character in its owner's «Personajes» tab */
export interface ForumOwnedCharacter extends ForumCharacterBasics {
    description: string | null;
    categoryId: string | null;
    categoryName: string | null;
    isRetired: boolean;
    isRetirementLocked: boolean;
    retiredLabel: string | null;
    /** Deleting it then retires it */
    hasPosts: boolean;
    actions: ForumCharacterActions;
}

export interface ForumCharacterProfile extends ForumCharacterForm {
    items: ForumOwnedCharacter[];
}
