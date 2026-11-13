<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Domain\Exceptions\ConversationNotFoundException;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidConversationException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\PostingTooFastException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * How the endpoints of private conversations answer what went wrong: a conversation the person
 * is not in is not found (404), like one that does not exist; what they may not do is 422 with
 * its reason (never naming who blocked whom); a document the schema refuses is 422; writing too
 * fast is 429 with Retry-After; settling without moderating the whole forum is 403.
 */
trait RespondsWithConversationErrors
{
    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function respond(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ConversationNotFoundException $exception) {
            throw new NotFoundHttpException(message: $exception->getMessage(), previous: $exception);
        } catch (ConversationRefusedException $exception) {
            $message = __("forum::messages.conversations.refusals.{$exception->reason}");

            return response()->json(['message' => $message, 'reason' => $exception->reason, 'errors' => ['conversation' => [$message]]], 422);
        } catch (InvalidConversationException) {
            $message = __('forum::messages.conversations.errors.title');

            return response()->json(['message' => $message, 'errors' => ['title' => [$message]]], 422);
        } catch (InvalidModerationRecordException) {
            $message = __('forum::messages.conversations.errors.note');

            return response()->json(['message' => $message, 'errors' => ['note' => [$message]]], 422);
        } catch (InvalidDocumentException $exception) {
            return response()->json([
                'message' => __("forum::messages.errors.document.{$exception->reason}"),
                'reason' => $exception->reason,
                'path' => $exception->path,
                'errors' => ['document' => [__("forum::messages.errors.document.{$exception->reason}")]],
            ], 422);
        } catch (PostingTooFastException $exception) {
            return response()->json(
                ['message' => __('forum::messages.errors.too_fast', ['seconds' => $exception->retryAfterSeconds])],
                429,
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            );
        } catch (ForumActionDeniedException) {
            return response()->json(['message' => __('forum::messages.errors.forbidden')], 403);
        }
    }

    /**
     * The editor document as it was sent, from the raw body (the site's middleware trims strings).
     */
    private function documentFrom(Request $request): mixed
    {
        $content = $request->getContent();
        if (strlen($content) > 2 * DocumentLimits::MAX_BYTES) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_LARGE, '', 'The document is too large');
        }

        $decoded = json_decode($content, true, 2 * DocumentLimits::MAX_DEPTH + 8);

        return is_array($decoded) && array_key_exists('document', $decoded) ? $decoded['document'] : null;
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
