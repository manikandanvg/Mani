<?php

namespace App\Filament\Resources\BranchSourceChangeRequestResource\Pages;

use App\Filament\Resources\BranchSourceChangeRequestResource;
use App\Models\Branch;
use App\Models\BranchSourceChangeRequest;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateBranchSourceChangeRequest extends CreateRecord
{
    protected static string $resource = BranchSourceChangeRequestResource::class;

    /**
     * A distributor can only request for their OWN branch; snapshot the current source +
     * requester; and allow only ONE pending request per branch (a one-time switch request).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if ($user?->isDistributor()) {
            $data['branch_id'] = $user->branch_id;   // ignore any tampered branch
        }

        $pending = BranchSourceChangeRequest::where('branch_id', $data['branch_id'])
            ->where('status', 'pending')->exists();
        if ($pending) {
            Notification::make()
                ->warning()
                ->title('A request is already pending')
                ->body('This branch already has a source-change request awaiting Head Office approval.')
                ->send();
            throw new Halt;
        }

        $data['current_source_branch_id'] = Branch::find($data['branch_id'])?->source_branch_id;
        $data['requested_by'] = $user?->id;
        $data['status'] = 'pending';

        return $data;
    }
}
