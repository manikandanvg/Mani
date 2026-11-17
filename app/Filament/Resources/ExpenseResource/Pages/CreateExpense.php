<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Submit expense';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $data['submitted_by'] = $user?->id;
        // the owner's own entries need no second approval
        if ($user?->isSuperAdmin()) {
            $data['status'] = Expense::APPROVED;
            $data['approved_by'] = $user->id;
            $data['approved_at'] = now();
        } else {
            $data['status'] = Expense::SUBMITTED;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
