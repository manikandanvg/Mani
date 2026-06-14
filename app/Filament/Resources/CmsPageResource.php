<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\CmsPageResource\Pages;
use App\Models\CmsPage;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CmsPageResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = CmsPage::class;

    protected static ?string $navigationGroup = 'Website';

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('slug')->required()->maxLength(140)->unique(ignoreRecord: true)
                    ->helperText('e.g. about, services, privacy-policy'),
                Forms\Components\Select::make('group')
                    ->options(['page' => 'Page', 'policy' => 'Policy'])->default('page')->required(),
                Forms\Components\TextInput::make('sort')->numeric()->default(0),
                Forms\Components\Toggle::make('is_published')->default(true),
            ]),
            Translatable::fieldset('title', 'Title'),
            Translatable::richFieldset('body', 'Body'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Translatable::column('title', 'Title'),
            Tables\Columns\TextColumn::make('slug')->searchable(),
            Tables\Columns\TextColumn::make('group')->badge(),
            Tables\Columns\IconColumn::make('is_published')->boolean(),
            Tables\Columns\TextColumn::make('sort')->sortable(),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCmsPages::route('/'),
            'create' => Pages\CreateCmsPage::route('/create'),
            'edit' => Pages\EditCmsPage::route('/{record}/edit'),
        ];
    }
}
