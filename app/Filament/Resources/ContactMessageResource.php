<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\ContactMessageResource\Pages;
use App\Models\ContactMessage;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Storefront "Contact us" inquiries. Read-only (visitors create them from the
 * website); admins can review, mark read/unread and delete.
 */
class ContactMessageResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = ContactMessage::class;

    protected static ?string $navigationGroup = 'Website';

    protected static ?string $navigationLabel = 'Contact Inquiries';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    public static function getNavigationBadge(): ?string
    {
        $unread = static::getModel()::where('is_read', false)->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(2)->schema([
                Infolists\Components\TextEntry::make('name'),
                Infolists\Components\TextEntry::make('created_at')->label('Received')->dateTime('d M Y, h:i A'),
                Infolists\Components\TextEntry::make('email')->copyable(),
                Infolists\Components\TextEntry::make('phone')->copyable(),
            ]),
            Infolists\Components\Section::make('Message')->schema([
                Infolists\Components\TextEntry::make('message')->hiddenLabel(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\IconColumn::make('is_read')->label('Read')->boolean()
                ->trueIcon('heroicon-o-envelope-open')->falseIcon('heroicon-s-envelope')
                ->trueColor('gray')->falseColor('danger'),
            Tables\Columns\TextColumn::make('name')->searchable()
                ->weight(fn (ContactMessage $r) => $r->is_read ? null : 'bold'),
            Tables\Columns\TextColumn::make('email')->searchable(),
            Tables\Columns\TextColumn::make('phone')->searchable(),
            Tables\Columns\TextColumn::make('message')->limit(60)->wrap(),
            Tables\Columns\TextColumn::make('created_at')->label('Received')->dateTime('d M Y, h:i A')->sortable(),
        ])->filters([
            Tables\Filters\TernaryFilter::make('is_read')->label('Read'),
        ])->actions([
            Tables\Actions\ViewAction::make()
                ->after(fn (ContactMessage $record) => $record->update(['is_read' => true])),
            Tables\Actions\Action::make('toggleRead')
                ->label(fn (ContactMessage $r) => $r->is_read ? 'Mark unread' : 'Mark read')
                ->icon('heroicon-o-envelope-open')
                ->action(fn (ContactMessage $r) => $r->update(['is_read' => ! $r->is_read])),
            Tables\Actions\DeleteAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContactMessages::route('/'),
        ];
    }
}
