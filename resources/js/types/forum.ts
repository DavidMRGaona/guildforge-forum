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
}

export interface ForumConversation {
    id: string;
    number: number;
    title: string;
    url: string;
    isClosed: boolean;
    isPinned: boolean;
    isHidden: boolean;
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
}

export interface ForumReplySettings extends ForumEditorSettings {
    canPost: boolean;
    postUrl: string;
    mentionsUrl: string | null;
}

export interface ForumCategoryOption {
    id: string;
    name: string;
    parentName: string | null;
    canModerate: boolean;
}
