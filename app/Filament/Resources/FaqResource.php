<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\FaqResource\Pages;
use App\Models\Faq;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FaqResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Faq::class;

    protected static ?string $navigationGroup = 'Website';

    protected static ?string $navigationLabel = 'FAQ';

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Translatable::fieldset('question', 'Question'),
            Translatable::fieldset('answer', 'Answer', textarea: true),
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('sort')->numeric()->default(0),
                Forms\Components\Toggle::make('is_published')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            Translatable::column('question', 'Question'),
            Tables\Columns\IconColumn::make('is_published')->boolean(),
            Tables\Columns\TextColumn::make('sort')->sortable(),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFaqs::route('/'),
            'create' => Pages\CreateFaq::route('/create'),
            'edit' => Pages\EditFaq::route('/{record}/edit'),
        ];
    }
}
