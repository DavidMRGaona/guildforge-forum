<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Forum\Domain\Repositories\ExternalImageCopyRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ExternalImageModel;

final readonly class EloquentExternalImageCopyRepository implements ExternalImageCopyRepositoryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function pathFor(string $url): ?string
    {
        $path = ExternalImageModel::query()->where('url_hash', $this->hash($url))->value('path');

        return is_string($path) ? $path : null;
    }

    public function pathsFor(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $paths = [];
        foreach (array_chunk(array_values(array_unique($urls)), 500) as $chunk) {
            foreach (ExternalImageModel::query()->whereIn('url_hash', array_map($this->hash(...), $chunk))->get(['url', 'path']) as $copy) {
                $paths[$copy->url] = $copy->path;
            }
        }

        return $paths;
    }

    public function save(string $url, string $path, CarbonImmutable $fetchedAt): string
    {
        // Two requests for the same address at once: the first copy wins, the other is left
        // unused and the media library removes it
        ExternalImageModel::query()->insertOrIgnore([
            'id' => Str::uuid()->toString(),
            'url_hash' => $this->hash($url),
            'url' => $url,
            'path' => $path,
            // Bindings bypass the model's storage-zone cast, so the time is converted by hand
            'fetched_at' => $this->timezone->toStorage($fetchedAt)->format('Y-m-d H:i:s'),
        ]);

        return $this->pathFor($url) ?? $path;
    }

    public function urlsOf(string $path): array
    {
        return array_values(array_filter(
            ExternalImageModel::query()->where('path', $path)->pluck('url')->all(),
            is_string(...),
        ));
    }

    private function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
