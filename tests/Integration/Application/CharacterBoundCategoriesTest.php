<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CharacterRules;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The categories many characters are tied to, read at once for `CharacterRules` (forum#135), give the
 * very answers each rule gives reading its own.
 */
final class CharacterBoundCategoriesTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_the_tied_categories_are_read_at_once_and_decide_as_reading_each(): void
    {
        $owner = strtolower((string) UserModel::factory()->create()->id);
        $open = $this->aCategoryFor(CategoryAudience::everyone(), 'abierta');
        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        // Moderates the members' category only, through a role: moderates() reads that category from the map
        $categoryModerator = $this->moderatorOf($members);
        $characters = [
            $this->aCharacterOf($owner, 'Libre'),
            $this->aCharacterOf($owner, 'Abierta', boundTo: $open),
            $this->aCharacterOf($owner, 'Socia', boundTo: $members),
            $this->aSharedCharacterIn($members),
        ];
        $rules = app(CharacterRules::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $bound = $rules->boundCategoriesOf($characters);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNull($bound[$characters[0]->id()->value]);
        $this->assertTrue($bound[$characters[1]->id()->value]?->id()->equals($open->id()));
        $this->assertTrue($bound[$characters[2]->id()->value]?->id()->equals($members->id()));

        $member = UserModel::factory()->create();
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        // A map that lacks some characters: those are read one by one, with the same answers
        $partial = array_slice($bound, 0, 2, true);
        foreach ([null, $member, $moderator, $categoryModerator] as $reader) {
            foreach ($characters as $character) {
                foreach ([$bound, $partial] as $map) {
                    $this->assertSame($rules->maySee($reader, $character), $rules->maySee($reader, $character, $map));
                    $this->assertSame($rules->moderates($reader, $character), $rules->moderates($reader, $character, $map));
                }
            }
        }
        // A guest does not see the character tied to the members' category, whichever way it is read
        $this->assertFalse($rules->maySee(null, $characters[2], $bound));
        $this->assertTrue($rules->moderates($categoryModerator, $characters[2], $bound));
        $this->assertTrue($rules->moderates($categoryModerator, $characters[3], $bound));
        $this->assertFalse($rules->moderates($categoryModerator, $characters[1], $bound));
    }

    public function test_categories_that_cannot_be_read_are_reported_once_and_fail_closed(): void
    {
        $owner = strtolower((string) UserModel::factory()->create()->id);
        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios');
        $categoryModerator = $this->moderatorOf($members);
        $characters = [
            $this->aCharacterOf($owner, 'Socia', boundTo: $members),
            $this->aCharacterOf($owner, 'Otra', boundTo: $members),
            $this->aSharedCharacterIn($members),
        ];
        // Stored rules nobody can read
        DB::table('forum_categories')->where('id', $members->id()->value)->update(['moderators' => json_encode(['roster' => 5])]);
        Exceptions::fake();
        $rules = app(CharacterRules::class);

        $bound = $rules->boundCategoriesOf($characters);

        $this->assertSame(array_fill_keys(array_map(static fn ($character): string => $character->id()->value, $characters), null), $bound);
        $member = UserModel::factory()->create();
        foreach ($characters as $character) {
            $this->assertFalse($rules->moderates($categoryModerator, $character, $bound));
        }
        // A personal character is seen by nobody but its owner then; a shared one names itself anyway
        $this->assertFalse($rules->maySee($member, $characters[0], $bound));
        $this->assertTrue($rules->moderates($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM), $characters[2], $bound));
        Exceptions::assertReportedCount(1);
    }

    public function test_a_category_deleted_since_is_none(): void
    {
        $owner = strtolower((string) UserModel::factory()->create()->id);
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'efimera');
        $character = $this->aCharacterOf($owner, boundTo: $category);
        DB::table('forum_categories')->where('id', $category->id()->value)->delete();

        $rules = app(CharacterRules::class);

        $this->assertSame([$character->id()->value => null], $rules->boundCategoriesOf([$character]));
        $this->assertNull($rules->boundCategory($character, $rules->boundCategoriesOf([$character])));
    }

    private function moderatorOf(Category $category): UserModel
    {
        $moderator = $this->anAccount('forum:test.placeholder');
        $roleId = $moderator->roles()->value('roles.id');
        $this->assertIsString($roleId);
        $category->changeAccess($category->viewAudience(), $category->writeAudience(), new CategoryModerators([$roleId]));
        app(CategoryRepositoryInterface::class)->save($category);

        return $moderator;
    }
}
