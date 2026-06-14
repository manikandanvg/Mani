<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\TestimonialResource\Pages;
use App\Models\Testimonial;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TestimonialResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Testimonial::class;

    protected static ?string $navigationGroup = 'Website';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(150),
                Forms\Components\TextInput::make('location')->maxLength(150),
                Forms\Components\Select::make('rating')->options(array_combine(range(1, 5), range(1, 5)))->default(5)->required(),
                Forms\Components\TextInput::make('sort')->numeric()->default(0),
                Forms\Components\FileUpload::make('image_path')->image()->directory('testimonials'),
                Forms\Components\Toggle::make('is_published')->default(true),
            ]),
            Translatable::fieldset('body', 'Testimonial', textarea: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('location'),
            Tables\Columns\TextColumn::make('rating')->badge(),
            Tables\Columns\IconColumn::make('is_published')->boolean(),
            Tables\Columns\TextColumn::make('sort')->sortable(),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTestimonials::route('/'),
            'create' => Pages\CreateTestimonial::route('/create'),
            'edit' => Pages\EditTestimonial::route('/{record}/edit'),
        ];
    }
}
