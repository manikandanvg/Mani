<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\TaskSubmissionResource\Pages;
use App\Models\TaskSubmission;
use App\Services\Tasks\TaskEngine;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Monthly Tasks → Proof Queue: verify / reject what people submitted for manual tasks. */
class TaskSubmissionResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = TaskSubmission::class;

    protected static ?string $navigationGroup = 'Monthly Tasks';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Proof Queue';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    public static function getNavigationBadge(): ?string
    {
        $n = TaskSubmission::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['assignment.taskType', 'member']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Submitted')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Employee')->searchable()
                    ->description(fn (TaskSubmission $r) => $r->member?->member_code),
                Tables\Columns\TextColumn::make('task')->state(fn (TaskSubmission $r) => $r->assignment?->title())->wrap()
                    ->description(fn (TaskSubmission $r) => $r->assignment?->month?->format('M Y')),
                Tables\Columns\TextColumn::make('body')->label('Note')->limit(60)->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('photo_path')->label('Photo')
                    ->formatStateUsing(fn ($state) => $state ? 'Open' : null)->placeholder('—')
                    ->url(fn (TaskSubmission $r) => $r->photoUrl(), shouldOpenInNewTab: true)->color('primary'),
                Tables\Columns\TextColumn::make('gps')->label('GPS')
                    ->state(fn (TaskSubmission $r) => $r->lat ? round((float) $r->lat, 4) . ', ' . round((float) $r->lng, 4) : null)
                    ->url(fn (TaskSubmission $r) => $r->lat ? "https://maps.google.com/?q={$r->lat},{$r->lng}" : null, shouldOpenInNewTab: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('value')->numeric(0)->label('Counts as'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) { 'verified' => 'success', 'rejected' => 'danger', default => 'warning' }),
                Tables\Columns\TextColumn::make('review_note')->label('Review')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'])->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Verify')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (TaskSubmission $r) => $r->status === 'pending')
                    ->form([
                        Forms\Components\TextInput::make('value')->label('Counts as')->numeric()->default(1)->minValue(0.01)->required(),
                        Forms\Components\TextInput::make('review_note')->label('Note')->maxLength(255),
                    ])
                    ->action(fn (TaskSubmission $r, array $data) => static::review($r, 'verified', (float) $data['value'], $data['review_note'] ?? null)),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (TaskSubmission $r) => $r->status === 'pending')
                    ->form([Forms\Components\TextInput::make('review_note')->label('Reason')->maxLength(255)->required()])
                    ->action(fn (TaskSubmission $r, array $data) => static::review($r, 'rejected', (float) $r->value, $data['review_note'])),
            ])
            ->bulkActions([]);
    }

    protected static function review(TaskSubmission $r, string $status, float $value, ?string $note): void
    {
        $r->update(['status' => $status, 'value' => $value, 'review_note' => $note, 'verified_by' => auth()->id(), 'verified_at' => now()]);
        if ($a = $r->assignment?->fresh('taskType')) {
            app(TaskEngine::class)->measureAssignment($a);
        }
        if ($r->member) {
            \App\Services\Push\Notifier::to($r->member, 'system',
                $status === 'verified' ? 'Task proof verified' : 'Task proof rejected',
                ($r->assignment?->title() ?? 'Task') . ($note ? ' — ' . $note : ''),
                route: '/business/tasks');
        }
        Notification::make()->success()->title($status === 'verified' ? 'Verified' : 'Rejected')->send();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTaskSubmissions::route('/')];
    }
}
