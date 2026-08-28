<?php

namespace App\Filament\Resources\DriveFolderResource\RelationManagers;

use App\Filament\Resources\DriveFolderResource;
use App\Models\DriveFile;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Training Library → files in a folder (user 2026-08-29). Upload a PDF / image / video /
 * document; name, type and size are recorded from the file. Public files in a public
 * folder are what the app's Library lists (LibraryController streams them by signed URL).
 */
class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Files';

    public const DISK = 'local';

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\FileUpload::make('path')
                ->label('File')
                ->disk(self::DISK)
                ->directory('library')
                ->visibility('private')
                ->maxSize(51200)
                ->acceptedFileTypes(['application/pdf', 'image/*', 'video/mp4', 'audio/*',
                    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'])
                ->required(fn (?DriveFile $record) => $record === null)
                ->downloadable()
                ->columnSpanFull()
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                    // Pre-fill the display name from the uploaded file's own name.
                    if ($state instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && blank($get('name'))) {
                        $set('name', pathinfo($state->getClientOriginalName(), PATHINFO_FILENAME));
                    }
                }),
            Forms\Components\TextInput::make('name')->label('Title shown in the app')->required()->maxLength(255),
            Forms\Components\Select::make('visibility')->options(DriveFolderResource::VISIBILITY)->default('public')->required()->native(false),
            Forms\Components\Hidden::make('owner_id')->default(fn () => auth()->id())->dehydrated(),
            Forms\Components\Hidden::make('disk')->default(self::DISK)->dehydrated(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->icon(fn (DriveFile $r) => str_starts_with((string) $r->mime, 'video/') ? 'heroicon-o-film'
                    : (str_starts_with((string) $r->mime, 'image/') ? 'heroicon-o-photo' : 'heroicon-o-document-text')),
                Tables\Columns\TextColumn::make('mime')->label('Type')->formatStateUsing(fn ($state) => $state ? strtoupper((string) (explode('/', $state)[1] ?? $state)) : '—'),
                Tables\Columns\TextColumn::make('size')->formatStateUsing(fn ($state) => $state >= 1048576 ? round($state / 1048576, 1) . ' MB' : round($state / 1024) . ' KB')->alignRight(),
                Tables\Columns\TextColumn::make('visibility')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'public' ? 'In the app' : ucfirst((string) $state))
                    ->color(fn ($state) => $state === 'public' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')->since()->label('Added'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Upload file')
                    ->mutateFormDataUsing(fn (array $data) => $this->stamp($data))
                    ->after(fn (DriveFile $record) => $this->stampRecord($record)),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('Open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DriveFile $r) => route('library.file.admin', $r), shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make()->mutateFormDataUsing(fn (array $data) => $this->stamp($data))
                    ->after(fn (DriveFile $record) => $this->stampRecord($record)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    /** After save (the upload is on disk by then): record mime + size for the app. */
    protected function stampRecord(DriveFile $record): void
    {
        $disk = Storage::disk($record->disk ?: self::DISK);
        if ($record->path && $disk->exists($record->path)) {
            $record->update(['mime' => $disk->mimeType($record->path) ?: $record->mime, 'size' => $disk->size($record->path)]);
        }
    }

    /** Record mime + size from the stored file so the app can show them. */
    protected function stamp(array $data): array
    {
        $data['disk'] = $data['disk'] ?? self::DISK;
        $data['owner_id'] = $data['owner_id'] ?? auth()->id();
        if (! empty($data['path'])) {
            $disk = Storage::disk($data['disk']);
            if ($disk->exists($data['path'])) {
                $data['mime'] = $disk->mimeType($data['path']) ?: null;
                $data['size'] = $disk->size($data['path']);
            }
        }

        return $data;
    }
}
