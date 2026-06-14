<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\PostResource\Pages;
use App\Models\Post;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PostResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Post::class;

    protected static ?string $navigationGroup = 'Website';

    protected static ?string $navigationLabel = 'Blog & News';

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('type')->options(['blog' => 'Blog', 'news' => 'News'])->default('blog')->required(),
                Forms\Components\TextInput::make('slug')->required()->maxLength(160)->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('author_name')->maxLength(120),
                Forms\Components\DateTimePicker::make('published_at')->default(now()),
                Forms\Components\FileUpload::make('image_path')->image()->directory('posts'),
                Forms\Components\Toggle::make('is_published')->default(true),
            ]),
            Translatable::fieldset('title', 'Title'),
            Translatable::fieldset('excerpt', 'Excerpt', textarea: true, requiredDefault: false),
            Translatable::richFieldset('body', 'Body'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('published_at', 'desc')->columns([
            Translatable::column('title', 'Title'),
            Tables\Columns\TextColumn::make('type')->badge(),
            Tables\Columns\TextColumn::make('published_at')->dateTime()->sortable(),
            Tables\Columns\IconColumn::make('is_published')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('type')->options(['blog' => 'Blog', 'news' => 'News']),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
