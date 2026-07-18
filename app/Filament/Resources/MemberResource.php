<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MemberResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Member::class;

    protected static ?string $navigationGroup = 'Network';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    // Auditor terminology (2026-07): members/customers are displayed as "Distributors".
    protected static ?string $modelLabel = 'Distributor';

    protected static ?string $pluralModelLabel = 'Distributors';

    protected static ?string $navigationLabel = 'Distributors';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('user_id')
                    ->numeric(),
                Forms\Components\TextInput::make('member_code')
                    ->label('Distributor Code')
                    ->required()
                    ->maxLength(30),
                Forms\Components\DatePicker::make('joined_on')
                    ->required(),
                Forms\Components\TextInput::make('upline_id')
                    ->label('Senior Distributor ID')
                    ->numeric(),
                Forms\Components\TextInput::make('referrer_id')
                    ->label('Referred Distributor ID')
                    ->numeric(),
                Forms\Components\TextInput::make('placement')
                    ->required(),
                Forms\Components\TextInput::make('left_member_id')
                    ->numeric(),
                Forms\Components\TextInput::make('right_member_id')
                    ->numeric(),
                Forms\Components\TextInput::make('rank_id')
                    ->label('TBP Stage ID')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('branch_id')
                    ->numeric(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(200),
                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(20),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(150),
                Forms\Components\DatePicker::make('dob'),
                Forms\Components\TextInput::make('father_name')
                    ->maxLength(200),
                Forms\Components\TextInput::make('address')
                    ->maxLength(255),
                Forms\Components\TextInput::make('city')
                    ->maxLength(120),
                Forms\Components\TextInput::make('pincode')
                    ->maxLength(12),
                Forms\Components\TextInput::make('country')
                    ->required()
                    ->maxLength(2)
                    ->default('IN'),
                Forms\Components\TextInput::make('pan')
                    ->maxLength(15),
                Forms\Components\TextInput::make('aadhaar')
                    ->maxLength(16),
                Forms\Components\TextInput::make('bank_name')
                    ->maxLength(150),
                Forms\Components\TextInput::make('bank_acno')
                    ->maxLength(30),
                Forms\Components\TextInput::make('ifsc')
                    ->maxLength(15),
                Forms\Components\TextInput::make('upi')
                    ->maxLength(80),
                Forms\Components\TextInput::make('nominee_name')
                    ->maxLength(200),
                Forms\Components\TextInput::make('nominee_relation')
                    ->maxLength(60),
                Forms\Components\TextInput::make('nominee_phone')
                    ->tel()
                    ->maxLength(20),
                Forms\Components\TextInput::make('photo_path')
                    ->maxLength(255),
                Forms\Components\TextInput::make('bv')
                    ->label('Business Volume (BV)')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('gbv')
                    ->label('Gross Business Volume (GBV)')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('unpure_bv')
                    ->label('Unpure Business Volume')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('unpure_gbv')
                    ->label('Unpure Gross Business Volume')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('downline_count')
                    ->label('Team size')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('status')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('member_code')
                    ->label('Distributor Code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('joined_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('upline_id')
                    ->label('Senior Distributor ID')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('referrer_id')
                    ->label('Referred Distributor ID')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('placement'),
                Tables\Columns\TextColumn::make('left_member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('right_member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rank_id')
                    ->label('TBP Stage ID')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dob')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('father_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pincode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pan')
                    ->searchable(),
                Tables\Columns\TextColumn::make('aadhaar')
                    ->searchable(),
                Tables\Columns\TextColumn::make('bank_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('bank_acno')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ifsc')
                    ->searchable(),
                Tables\Columns\TextColumn::make('upi')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nominee_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nominee_relation')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nominee_phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('photo_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('bv')
                    ->label('Business Volume (BV)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gbv')
                    ->label('Gross Business Volume (GBV)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unpure_bv')
                    ->label('Unpure Business Volume')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unpure_gbv')
                    ->label('Unpure Gross Business Volume')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('downline_count')
                    ->label('Team size')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
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
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }
}
