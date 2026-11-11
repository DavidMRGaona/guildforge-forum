<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * Where a page of discussions ends: the last one's activity, as the database stores it, and its
 * id. The next page starts after it in (activity, id) descending order, so new activity never
 * repeats or skips a discussion between pages the way an offset would.
 */
final readonly class DiscussionCursor
{
    public function __construct(
        public string $lastActivity,
        public string $id,
    ) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([$this->lastActivity, $this->id])), '+/', '-_'), '=');
    }

    /**
     * Null for anything that is not a cursor this class wrote: the list then starts over.
     */
    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '' || strlen($value) > 200) {
            return null;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || count($data) !== 2 || ! is_string($data[0] ?? null) || ! is_string($data[1] ?? null)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $data[0]) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $data[1]) !== 1) {
            return null;
        }

        return new self($data[0], $data[1]);
    }
}
