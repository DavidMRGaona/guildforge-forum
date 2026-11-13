<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use App\Application\Realtime\Services\RealtimePublisherInterface;

/**
 * Remembers what the forum published in real time, without sending anything.
 */
final class RecordingRealtimePublisher implements RealtimePublisherInterface
{
    /** @var list<array{channel: string, event: string, payload: array<string, mixed>}> */
    public array $messages = [];

    public function publish(string $channel, string $event, array $payload = []): void
    {
        $this->messages[] = ['channel' => $channel, 'event' => $event, 'payload' => $payload];
    }

    /**
     * @return list<array{event: string, payload: array<string, mixed>}>
     */
    public function on(string $channel): array
    {
        return array_values(array_map(
            static fn (array $message): array => ['event' => $message['event'], 'payload' => $message['payload']],
            array_filter($this->messages, static fn (array $message): bool => $message['channel'] === $channel),
        ));
    }

    /**
     * @return list<string> the channels that got the event
     */
    public function channelsOf(string $event): array
    {
        return array_values(array_unique(array_map(
            static fn (array $message): string => $message['channel'],
            array_filter($this->messages, static fn (array $message): bool => $message['event'] === $event),
        )));
    }

    public function forget(): void
    {
        $this->messages = [];
    }
}
