<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use Illuminate\Support\Facades\Schema;
use Modules\Forum\Tests\Support\ErasedTables;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Every table of the module, and every column that points at a person, has a decision about
 * what happens to it when the account is anonymized (core#461). Add a table or a person column
 * and this fails until it is listed in ErasedTables and handled by AccountErasure.
 */
final class AccountDataCoverageTest extends ModuleTestCase
{
    private const string PERSON_COLUMN = '/(user|author|owner|reporter|moderator|editor|account)_id$|^(suspended|lifted|resolved)_by$/';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_every_table_of_the_module_is_decided(): void
    {
        $tables = array_values(array_filter(
            array_column(Schema::getTables(), 'name'),
            static fn (string $name): bool => str_starts_with($name, 'forum_'),
        ));
        $decided = [
            ...array_map(static fn (array $pair): string => $pair[0], ErasedTables::columns()),
            ...array_keys(ErasedTables::kept()),
            ...ErasedTables::withoutPeople(),
        ];

        sort($tables);
        $decided = array_values(array_unique($decided));
        sort($decided);

        $this->assertSame($tables, $decided, 'A forum table has no decision about the people it holds: add it to ErasedTables');
    }

    public function test_every_column_that_points_at_a_person_is_decided(): void
    {
        $decided = [];
        foreach (ErasedTables::columns() as [$table, $column]) {
            $decided[] = "{$table}.{$column}";
        }
        foreach (ErasedTables::kept() as $table => $entry) {
            foreach ($entry['columns'] as $column) {
                $decided[] = "{$table}.{$column}";
            }
        }

        $found = [];
        foreach (ErasedTables::withoutPeople() as $table) {
            $this->assertSame([], $this->personColumns($table), "{$table} has a person column but is listed as holding none");
        }
        foreach (array_unique([...array_map(static fn (array $pair): string => $pair[0], ErasedTables::columns()), ...array_keys(ErasedTables::kept())]) as $table) {
            foreach ($this->personColumns($table) as $column) {
                $found[] = "{$table}.{$column}";
            }
        }

        sort($found);
        $decided = array_values(array_unique($decided));
        sort($decided);

        $this->assertSame($found, $decided);
    }

    /**
     * @return list<string>
     */
    private function personColumns(string $table): array
    {
        return array_values(array_filter(
            Schema::getColumnListing($table),
            static fn (string $column): bool => preg_match(self::PERSON_COLUMN, $column) === 1,
        ));
    }
}
