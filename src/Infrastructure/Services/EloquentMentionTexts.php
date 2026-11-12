<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Modules\Forum\Application\Contracts\MentionTextsInterface;
use Modules\Forum\Application\Services\PostRenderer;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostRevisionModel;
use Throwable;

final readonly class EloquentMentionTexts implements MentionTextsInterface
{
    public function __construct(
        private PostRenderer $renderer,
        private DiceRollRepositoryInterface $rolls,
    ) {}

    public function refreshFor(string $accountId): void
    {
        $id = AccountId::normalize($accountId);
        if ($id === null) {
            return;
        }

        // A mention keeps the account id in the document, whatever the database does to its JSON
        $like = ['%'.$id.'%'];

        foreach (PostModel::query()->whereRaw('CAST(body AS TEXT) LIKE ?', $like)->lazyById(100) as $post) {
            $this->rewrite($post, $post->id, $post->body);
        }

        foreach (PostRevisionModel::query()->whereRaw('CAST(body AS TEXT) LIKE ?', $like)->lazyById(100) as $revision) {
            $this->rewrite($revision, $revision->post_id, $revision->body);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function rewrite(PostModel|PostRevisionModel $row, string $postId, array $document): void
    {
        try {
            $text = $this->renderer->plainText($document, $this->rolls->ofPost(PostId::fromString($postId)));
        } catch (Throwable $exception) {
            // One document that cannot be read must not keep the rest from being written again
            report($exception);

            return;
        }

        if ($text === $row->plain_text) {
            return;
        }

        $row->plain_text = $text;
        $row->timestamps = false;
        $row->save();
    }
}
