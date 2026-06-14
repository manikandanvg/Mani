<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\DigiQueueResource\Pages;
use App\Filament\Resources\DigiQueueResource\RelationManagers;
use App\Models\DigiQueue;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DigiQueueResource extends BaseResource
{
    use BranchScoped;

    protected static function branchColumn(): string
    {
        return 'redeem_branch_id';
    }
    protected static ?string $model = DigiQueue::class;

    protected static ?string $navigationGroup = 'Digi-Gold';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    // Legacy digi-gold — superseded by the new Redeemable Stock QR redemption. Hidden from nav.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('member_id')
                    ->numeric(),
                Forms\Components\TextInput::make('qr_code')
                    ->required()
                    ->maxLength(40),
                Forms\Components\TextInput::make('qr_mode')
                    ->required(),
                Forms\Components\TextInput::make('gram_worth')
                    ->numeric(),
                Forms\Components\TextInput::make('cash_worth')
                    ->numeric(),
                Forms\Components\TextInput::make('reference')
                    ->maxLength(40),
                Forms\Components\TextInput::make('route')
                    ->maxLength(40),
                Forms\Components\TextInput::make('delivery_status')
                    ->required(),
                Forms\Components\Toggle::make('qr_sent')
                    ->required(),
                Forms\Components\TextInput::make('redeem_branch_id')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('qr_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('qr_mode'),
                Tables\Columns\TextColumn::make('gram_worth')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cash_worth')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('route')
                    ->searchable(),
                Tables\Columns\TextColumn::make('delivery_status'),
                Tables\Columns\IconColumn::make('qr_sent')
                    ->boolean(),
                Tables\Columns\TextColumn::make('redeem_branch_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDigiQueues::route('/'),
            'create' => Pages\CreateDigiQueue::route('/create'),
            'edit' => Pages\EditDigiQueue::route('/{record}/edit'),
        ];
    }
}
