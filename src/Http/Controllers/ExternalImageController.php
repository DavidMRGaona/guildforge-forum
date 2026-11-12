<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Services\ExternalImageImporter;
use Modules\Forum\Http\Requests\StoreExternalImageRequest;
use RuntimeException;

/**
 * Makes the forum's copy of an image from another site for the editor and answers with what
 * the image node keeps (the address and the copy) and where to show the copy while writing.
 * The browser never asks the other site for anything.
 */
final class ExternalImageController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly ExternalImageImporter $importer,
    ) {}

    public function store(StoreExternalImageRequest $request): JsonResponse
    {
        return $this->respond(function () use ($request): JsonResponse {
            try {
                $image = $this->importer->import($request->string('url')->toString());
            } catch (RuntimeException $exception) {
                Log::error('Forum: the copy of an image from another site could not be stored.', ['exception' => $exception->getMessage()]);

                return response()->json(['message' => __('forum::messages.errors.image_upload')], 503);
            }

            return response()->json($image, 201);
        });
    }
}
