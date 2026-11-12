<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Content\PostDocument;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Repositories\ExternalImageCopyRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Whether the images from other sites in a document may be published: each one new to the post
 * needs forum:posts.external_images, an address on a site of the forum settings, and the copy
 * the forum made of that very address (the editor gets it from the import endpoint; a document
 * naming another file is refused). Images the post already had stay as they are, so editing a
 * post or moderating it never depends on the editor's permission or on today's site list.
 */
final readonly class ExternalImageRules
{
    public const string PERMISSION = 'forum:posts.external_images';

    public function __construct(
        private AuthorizationServiceInterface $authorization,
        private ExternalImageImporter $importer,
        private ExternalImageCopyRepositoryInterface $copies,
        private DocumentRenderer $renderer,
    ) {}

    /**
     * @param  PostBody|null  $previous  what the post said before, when it is an edit
     *
     * @throws InvalidDocumentException
     */
    public function assertAllowed(PostDocument $document, Authenticatable $author, ?PostBody $previous = null): void
    {
        $kept = $previous instanceof PostBody ? $this->pairsOf($previous->document) : [];
        $new = array_values(array_filter(
            $document->externalImages(),
            static fn (array $image): bool => ! isset($kept[$image['url'].' '.$image['path']]),
        ));

        if ($new === []) {
            return;
        }

        if (! $this->authorization->can($author, self::PERMISSION)) {
            throw InvalidDocumentException::because(InvalidDocumentException::EXTERNAL_IMAGE_NOT_ALLOWED, '', 'Images from other sites need their own permission');
        }

        $copies = $this->copies->pathsFor(array_map(static fn (array $image): string => $image['url'], $new));
        foreach ($new as $image) {
            $this->importer->allowedUrl($image['url']);

            if (($copies[$image['url']] ?? null) !== $image['path']) {
                throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, '', 'An image from another site names a file that is not its copy');
            }
        }
    }

    /**
     * The address and copy of every image from another site in a stored document.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, true>
     */
    private function pairsOf(array $document): array
    {
        $image = (new ImageNode)->name();
        $pairs = [];
        foreach ($this->renderer->attributesOf($document, [$image])[$image] ?? [] as $attributes) {
            $url = ImageNode::remoteUrl($attributes);
            $path = ImageNode::storedPath($attributes);
            if ($url instanceof RemoteUrl && $path !== null) {
                $pairs[$url->value.' '.$path] = true;
            }
        }

        return $pairs;
    }
}
