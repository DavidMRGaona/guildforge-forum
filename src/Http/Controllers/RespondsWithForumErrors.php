<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Domain\Exceptions\CharacterUnavailableException;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\PostingTooFastException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Http\Presenters\MergedDiscussionAnswer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * How the editor's JSON endpoints answer what went wrong: what the person may not read is not
 * found (404), what they may not do is forbidden (403), a document the schema refuses is 422
 * with its reason, posting too fast is 429 with Retry-After, and a post in the wrong state
 * (already deleted) or a discussion in the wrong state (already closed) is 409. An action on a
 * discussion that was merged into another, from a page left open on it, is 410 with where it went.
 */
trait RespondsWithForumErrors
{
    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function respond(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (DiscussionNotFoundException|PostNotFoundException $exception) {
            throw new NotFoundHttpException(message: $exception->getMessage(), previous: $exception);
        } catch (ForumActionDeniedException) {
            return response()->json(['message' => __('forum::messages.errors.forbidden')], 403);
        } catch (InvalidDocumentException $exception) {
            return response()->json([
                'message' => __("forum::messages.errors.document.{$exception->reason}"),
                'reason' => $exception->reason,
                'path' => $exception->path,
                'errors' => ['document' => [__("forum::messages.errors.document.{$exception->reason}")]],
            ], 422);
        } catch (PostingTooFastException $exception) {
            $message = $exception->discussions
                ? __('forum::messages.errors.too_many_discussions', ['minutes' => (int) ceil($exception->retryAfterSeconds / 60)])
                : __('forum::messages.errors.too_fast', ['seconds' => $exception->retryAfterSeconds]);

            return response()->json(
                ['message' => $message],
                429,
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            );
        } catch (CharacterUnavailableException) {
            $message = __('forum::messages.characters.errors.unavailable');

            return response()->json(['message' => $message, 'reason' => 'character_unavailable', 'errors' => ['character' => [$message]]], 422);
        } catch (InvalidPostException) {
            return response()->json(['message' => __('forum::messages.errors.post_state')], 409);
        } catch (InvalidDiscussionException) {
            return response()->json(['message' => __('forum::messages.errors.discussion_state')], 409);
        }
    }

    /**
     * The action on the discussion with this id, told (410) where the discussion went when it was
     * merged into another and the person may read that. Anything else that is not found stays as
     * it was: a discussion that never existed, one that may not be read, or another one the action
     * names (the target of a merge).
     *
     * @param  Closure(): JsonResponse  $action
     * @return Closure(): JsonResponse
     */
    private function orMergedAnswer(Request $request, string $discussion, Closure $action): Closure
    {
        return static function () use ($request, $discussion, $action): JsonResponse {
            try {
                return $action();
            } catch (DiscussionNotFoundException $exception) {
                $user = $request->user();

                return app(MergedDiscussionAnswer::class)->for($user instanceof Authenticatable ? $user : null, $discussion) ?? throw $exception;
            }
        };
    }

    /**
     * The editor document as it was sent. Read from the raw body, because the site's middleware
     * trims and nulls strings in the parsed input, which would change the spaces of a text.
     *
     * @throws InvalidDocumentException
     */
    private function documentFrom(Request $request): mixed
    {
        $content = $request->getContent();
        // Room for the JSON wrapper and escapes around a document at its limit
        if (strlen($content) > 2 * DocumentLimits::MAX_BYTES) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_LARGE, '', 'The document is too large');
        }

        $decoded = json_decode($content, true, 2 * DocumentLimits::MAX_DEPTH + 8);

        return is_array($decoded) && array_key_exists('document', $decoded) ? $decoded['document'] : null;
    }

    /**
     * Who the post speaks as: [whether the request says, the character id or null out of
     * character]. Anything that is not a string or null counts as an id nobody has.
     *
     * @return array{bool, string|null}
     */
    private function speakerFrom(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true, 2 * DocumentLimits::MAX_DEPTH + 8);
        if (! is_array($decoded) || ! array_key_exists('character', $decoded)) {
            return [false, null];
        }

        $character = $decoded['character'];

        return [true, $character === null || is_string($character) ? $character : 'invalid'];
    }
}
