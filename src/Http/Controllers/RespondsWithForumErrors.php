<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\PostingTooFastException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * How the editor's JSON endpoints answer what went wrong: what the person may not read is not
 * found (404), what they may not do is forbidden (403), a document the schema refuses is 422
 * with its reason, posting too fast is 429 with Retry-After, and a post in the wrong state
 * (already deleted) or a discussion in the wrong state (already closed) is 409.
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
        } catch (InvalidPostException) {
            return response()->json(['message' => __('forum::messages.errors.post_state')], 409);
        } catch (InvalidDiscussionException) {
            return response()->json(['message' => __('forum::messages.errors.discussion_state')], 409);
        }
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
}
