<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * What moderation did, as the moderation log keeps it. The values are stored: never rename one.
 */
enum ModerationAction: string
{
    case DiscussionPinned = 'discussion_pinned';
    case DiscussionUnpinned = 'discussion_unpinned';
    case DiscussionClosed = 'discussion_closed';
    case DiscussionReopened = 'discussion_reopened';
    case DiscussionHidden = 'discussion_hidden';
    case DiscussionShown = 'discussion_shown';
    case DiscussionRenamed = 'discussion_renamed';
    case DiscussionMoved = 'discussion_moved';
    case DiscussionDeleted = 'discussion_deleted';
    case DiscussionSplit = 'discussion_split';
    case DiscussionMerged = 'discussion_merged';
    case PostHidden = 'post_hidden';
    case PostShown = 'post_shown';
    case PostEdited = 'post_edited';
    case PostApproved = 'post_approved';
    case PostRejected = 'post_rejected';
    case ReportResolved = 'report_resolved';
    case UserSuspended = 'user_suspended';
    case SuspensionLifted = 'suspension_lifted';
    case CharacterEdited = 'character_edited';
    case CharacterRetired = 'character_retired';
    case CharacterRestored = 'character_restored';
    case CharacterDeleted = 'character_deleted';
    case PrivateMessageHidden = 'private_message_hidden';
    case PrivateReportsResolved = 'private_reports_resolved';
}
