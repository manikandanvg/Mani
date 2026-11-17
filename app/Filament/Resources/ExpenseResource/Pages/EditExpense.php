<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Resources\Pages\EditRecord;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    /** A corrected (previously rejected) expense goes back for approval. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->status === Expense::REJECTED) {
            $data['status'] = Expense::SUBMITTED;
            $data['reject_reason'] = null;
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
