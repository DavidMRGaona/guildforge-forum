<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Application\Contracts\RemoteFetcherInterface;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\Repositories\ExternalImageCopyRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use RuntimeException;

/**
 * Makes the forum's copy of an image from another site, once per address: the server fetches
 * it (never the reader's browser), checks it as it checks uploads (a real image of an allowed
 * format, read from its bytes; no heavier and no larger than uploads may be) and stores it
 * resized and without metadata under forum/external/. The address must be https on a site of
 * the forum settings. Whether the person may add such images is the caller's decision.
 */
final readonly class ExternalImageImporter
{
    private const string ACCEPT = 'image/webp,image/png,image/jpeg,image/gif;q=0.9';

    public function __construct(
        private RemoteFetcherInterface $fetcher,
        private PostImageUploader $uploader,
        private ExternalImageCopyRepositoryInterface $copies,
        private PostImageStorageInterface $storage,
        private ForumSettingsInterface $settings,
    ) {}

    /**
     * @return array{url: string, path: string, previewUrl: string|null} the address as the image node keeps it, its copy, and where to show the copy
     *
     * @throws InvalidDocumentException
     * @throws RuntimeException when the copy could not be stored
     */
    public function import(string $address): array
    {
        $url = $this->allowedUrl($address);

        $path = $this->copies->pathFor($url->value) ?? $this->copy($url);

        return ['url' => $url->value, 'path' => $path, 'previewUrl' => $this->storage->url($path)];
    }

    /**
     * The address, when it is one images may come from.
     *
     * @throws InvalidDocumentException
     */
    public function allowedUrl(string $address): RemoteUrl
    {
        $url = RemoteUrl::tryFrom(trim($address));

        if (! $url instanceof RemoteUrl || ! $url->hostIsIn($this->settings->externalImageHosts())) {
            throw InvalidDocumentException::because(InvalidDocumentException::EXTERNAL_IMAGE_HOST, '', 'Images come from the sites of the forum settings, over https');
        }

        return $url;
    }

    /**
     * @throws InvalidDocumentException
     * @throws RuntimeException
     */
    private function copy(RemoteUrl $url): string
    {
        try {
            $bytes = $this->fetcher->fetch($url, $this->settings->imageMaxKilobytes() * 1024, self::ACCEPT)->bytes;
        } catch (RemoteFetchException $exception) {
            Log::info('Forum: an image from another site was not fetched.', ['host' => $url->host, 'reason' => $exception->reason]);

            throw InvalidDocumentException::because(
                $exception->reason === RemoteFetchException::TOO_LARGE ? InvalidDocumentException::EXTERNAL_IMAGE_TOO_LARGE : InvalidDocumentException::EXTERNAL_IMAGE_UNAVAILABLE,
                '',
                'The image could not be fetched',
            );
        }

        // A real image, no larger before resizing than an upload may be
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || max($size[0], $size[1]) > PostImageUploader::MAX_SOURCE_DIMENSION) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, '', 'This is not an image the forum takes');
        }

        $stored = $this->uploader->upload($bytes, ImageNode::EXTERNAL_FOLDER);

        return $this->copies->save($url->value, $stored['path'], CarbonImmutable::now());
    }
}
