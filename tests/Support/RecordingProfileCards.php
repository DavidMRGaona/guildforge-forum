<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use App\Application\Profiles\DTOs\ProfileCardDTO;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A core that cannot make a profile card, and remembers whose card it was asked for.
 */
final class RecordingProfileCards implements ProfileCardServiceInterface
{
    /** @var list<string> */
    public array $asked = [];

    public function card(string $userId, ?Authenticatable $viewer): ?ProfileCardDTO
    {
        $this->asked[] = $userId;

        return null;
    }
}
