<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CharacterRules;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
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
        foreach ([null, $member, $moderator] as $reader) {
            foreach ($characters as $character) {
                $this->assertSame($rules->maySee($reader, $character), $rules->maySee($reader, $character, $bound));
                $this->assertSame($rules->moderates($reader, $character), $rules->moderates($reader, $character, $bound));
            }
        }
        // A guest does not see the character tied to the members' category, whichever way it is read
        $this->assertFalse($rules->maySee(null, $characters[2], $bound));
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
}
