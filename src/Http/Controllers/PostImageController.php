<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Services\PostImageUploader;
use Modules\Forum\Http\Requests\StorePostImageRequest;
use RuntimeException;

/**
 * Uploads an image for the editor and answers with the stored path the image node keeps and
 * the address to show it while writing.
 */
final class PostImageController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly PostImageUploader $uploader,
    ) {}

    public function store(StorePostImageRequest $request): JsonResponse
    {
        return $this->respond(function () use ($request): JsonResponse {
            $file = $request->file('image');
            abort_unless($file instanceof UploadedFile, 422);

            try {
                $image = $this->uploader->upload($file->getContent());
            } catch (RuntimeException $exception) {
                Log::error('Forum: a post image could not be stored.', ['exception' => $exception->getMessage()]);

                return response()->json(['message' => __('forum::messages.errors.image_upload')], 503);
            }

            return response()->json($image, 201);
        });
    }
}
