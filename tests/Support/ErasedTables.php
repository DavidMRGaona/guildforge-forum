<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

/**
 * What the forum keeps about each person, table by table: the columns whose rows go when the
 * account is anonymized, and the tables that hold a person's id on purpose (or none). The
 * coverage test fails for any table or person column missing here, so a new table is never
 * left out of the anonymization by accident.
 */
final class ErasedTables
{
    /**
     * Rows that only mattered to the person: deleted when the account is anonymized.
     *
     * @return list<array{string, string}> table and column
     */
    public static function columns(): array
    {
        return [
            ['forum_post_likes', 'user_id'],
            ['forum_discussion_follows', 'user_id'],
            ['forum_category_follows', 'user_id'],
            ['forum_user_preferences', 'user_id'],
            ['forum_discussion_reads', 'user_id'],
            ['forum_category_reads', 'user_id'],
            ['forum_reports', 'reporter_id'],
        ];
    }

    /**
     * Tables that keep person columns on purpose, and why. Their rows are conversation or
     * moderation history: the account shows its anonymous name wherever they are read.
     *
     * @return array<string, array{columns: list<string>, why: string}>
     */
    public static function kept(): array
    {
        return [
            'forum_discussions' => ['columns' => ['author_id'], 'why' => 'the conversation stays'],
            'forum_posts' => ['columns' => ['author_id'], 'why' => 'the conversation stays'],
            'forum_post_revisions' => ['columns' => ['editor_id'], 'why' => 'history of a post that stays'],
            'forum_moderation_log' => ['columns' => ['moderator_id', 'subject_user_id'], 'why' => 'moderation history'],
            'forum_reports' => ['columns' => ['resolved_by'], 'why' => 'reports about posts that stay (the reporter column is erased)'],
            'forum_suspensions' => ['columns' => ['user_id', 'suspended_by', 'lifted_by'], 'why' => 'moderation history'],
            'forum_characters' => ['columns' => ['owner_id'], 'why' => 'details stripped and retired for good; the name stays on the posts (a shared character of a campaign space has no owner: nothing to erase)'],
        ];
    }

    /**
     * Tables without any column that points at a person.
     *
     * @return list<string>
     */
    public static function withoutPeople(): array
    {
        return [
            'forum_categories',
            'forum_discussion_topics',
            'forum_subject_discussions',
            'forum_dice_rolls',
            'forum_post_redirects',
            'forum_discussion_redirects',
            // Copies of images from other sites: an address and a stored file, nobody's
            'forum_external_images',
            // Cards of embedded posts: the author named is the post's on the other service, not an account
            'forum_embeds',
        ];
    }
}
