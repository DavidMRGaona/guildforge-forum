<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature;

use App\Application\Modules\Services\ModuleSlotRegistryInterface;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The forum fills the core's `subject-discussion` slot, which record pages (events, game
 * tables, campaigns) offer with their publication's type and id.
 */
final class SubjectDiscussionSlotTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_it_fills_the_subject_discussion_slot(): void
    {
        $payload = app(ModuleSlotRegistryInterface::class)->toInertiaPayload();

        $this->assertArrayHasKey('subject-discussion', $payload);
        $registration = collect($payload['subject-discussion'])->firstWhere('module', 'forum');
        $this->assertIsArray($registration);
        $this->assertSame('components/subjects/SubjectConversation.vue', $registration['component']);
        $this->assertFileExists(__DIR__.'/../../resources/js/'.$registration['component']);
    }
}
