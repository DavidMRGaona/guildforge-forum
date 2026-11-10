<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Resources\CategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Forum\Filament\Resources\CategoryResource;
use Modules\Forum\Filament\Resources\CategoryResource\CategoryForm;

final class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CategoryForm::class)->save($data, null);
    }
}
