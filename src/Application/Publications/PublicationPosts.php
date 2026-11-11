<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationTopicDTO;
use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Services\PostRenderer;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Domain\ValueObjects\PostBody;

/**
 * What the system account writes about a publication: the title and first post of its
 * discussion (summary as plain text, date, game system and a link to its page) and the notices.
 * Every document goes through the editor's schema, as anyone's posts do.
 */
final readonly class PublicationPosts
{
    /** Longest summary quoted in the first post; the page has the rest */
    public const int MAX_SUMMARY_LENGTH = 1500;

    public const string DATE_FORMAT = 'd/m/Y H:i';

    public function __construct(
        private DocumentSchema $schema,
        private PostRenderer $renderer,
        private TimezoneServiceInterface $timezone,
    ) {}

    public function title(PublicationDTO $publication): DiscussionTitle
    {
        return new DiscussionTitle(self::cut(trim($publication->title), DiscussionTitle::MAX_LENGTH));
    }

    public function opening(PublicationDTO $publication): PostBody
    {
        $paragraphs = [];

        // Plain text: each line of the summary is a paragraph
        foreach (preg_split('/\R+/u', self::cut(trim($publication->summary), self::MAX_SUMMARY_LENGTH)) ?: [] as $line) {
            $line = trim(str_replace("\0", '', $line));
            if ($line !== '') {
                $paragraphs[] = $this->paragraph($line);
            }
        }

        if ($publication->startsAt instanceof DateTimeImmutable) {
            $paragraphs[] = $this->paragraph(__('forum::messages.publications.opening.date', ['date' => $this->date($publication->startsAt)]));
        }

        $systems = array_map(
            static fn (PublicationTopicDTO $topic): string => $topic->label,
            array_values(array_filter($publication->topics, static fn (PublicationTopicDTO $topic): bool => str_starts_with($topic->key, DiscussionTopic::GAME_SYSTEM_PREFIX))),
        );
        if ($systems !== []) {
            $paragraphs[] = $this->paragraph(__('forum::messages.publications.opening.game_system', ['systems' => implode(', ', $systems)]));
        }

        $paragraphs[] = $this->link(__('forum::messages.publications.page_link'), $publication->url);

        return $this->body($paragraphs);
    }

    /**
     * @param  PublicationDTO|null  $publication  as it is now; null once it is gone
     */
    public function notice(PublicationNotice $notice, ?PublicationDTO $publication): PostBody
    {
        $replace = [];
        if ($notice === PublicationNotice::Rescheduled && $publication?->startsAt instanceof DateTimeImmutable) {
            $replace['date'] = $this->date($publication->startsAt);
        }

        $paragraphs = [$this->paragraph(__("forum::messages.publications.notices.{$notice->value}", $replace))];

        if ($publication instanceof PublicationDTO && $notice->linksToPage()) {
            $paragraphs[] = $this->link(__('forum::messages.publications.page_link'), $publication->url);
        }

        return $this->body($paragraphs);
    }

    /**
     * The tags of the discussion: the publication's topics, with labels cut to fit.
     *
     * @return list<DiscussionTopic>
     */
    public function topics(PublicationDTO $publication): array
    {
        return array_map(
            static fn (PublicationTopicDTO $topic): DiscussionTopic => new DiscussionTopic($topic->key, self::cut(trim($topic->label), DiscussionTopic::MAX_LABEL_LENGTH)),
            $publication->topics,
        );
    }

    /**
     * A fingerprint of a body, to tell whether the first post needs rewriting.
     */
    public static function fingerprint(PostBody $body): string
    {
        return hash('sha256', (string) json_encode($body->document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function date(DateTimeImmutable $at): string
    {
        return $this->timezone->format($at, self::DATE_FORMAT);
    }

    /**
     * @param  list<array<string, mixed>>  $paragraphs
     */
    private function body(array $paragraphs): PostBody
    {
        $document = $this->schema->validate(['type' => 'doc', 'content' => $paragraphs]);

        return new PostBody($document->toArray(), $this->renderer->toText($document));
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function link(string $text, string $url): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $url]]]]]];
    }

    private static function cut(string $text, int $length): string
    {
        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)).'…';
    }
}
