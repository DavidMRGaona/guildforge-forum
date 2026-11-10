<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Resources\CategoryResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Forum\Filament\Resources\CategoryResource;
use Modules\Forum\Filament\Resources\CategoryResource\CategoryForm;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;

final class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CategoryResource::deleteAction()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return $record instanceof CategoryModel ? app(CategoryForm::class)->fill($record) : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(CategoryForm::class)->save($data, (string) $record->getKey());
    }
}
