<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Resources\CategoryResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Forum\Filament\Resources\CategoryResource;

final class ListCategories extends ListRecords
{
    use HasKnowledgeGuideAction;

    protected static string $resource = CategoryResource::class;

    public function getSubheading(): string
    {
        return __('forum::messages.categories.list_help');
    }

    protected function getHeaderActions(): array
    {
        return array_filter([CreateAction::make(), $this->knowledgeGuideAction('forum.panel.categories')]);
    }
}
