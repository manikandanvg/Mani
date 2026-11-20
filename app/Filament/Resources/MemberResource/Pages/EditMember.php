<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use App\Models\Member;
use App\Services\Network\PlacementCorrectionService;
use App\Services\NetworkService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * User 2026-11-20: a new Senior Distributor picked here must reach every place that holds it,
     * not just members.upline_id. The move goes through PlacementCorrectionService (the member's
     * bonds frozen on the old senior, the Sales Incentive of their own bills and of their team's
     * bills), then BV / GBV / team size / TBP stage are recomputed over the new tree.
     * The Referred Distributor lives in members.referrer_id only — nothing is frozen from it.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Member $record */
        $newId = (int) ($data['upline_id'] ?? 0);
        $moved = array_key_exists('upline_id', $data) && $newId !== (int) $record->upline_id;
        if (! $moved) {
            return parent::handleRecordUpdate($record, $data);
        }

        $svc = app(PlacementCorrectionService::class);
        $senior = $newId ? Member::find($newId) : null;
        $error = $senior
            ? $svc->move($record, $senior, false)['error']          // dry run: own senior / loop check
            : __('Pick a Senior Distributor — it cannot be left empty once a distributor has one.');
        if ($error) {
            Notification::make()->danger()->title(__('Senior Distributor not changed'))->body($error)->persistent()->send();
            $this->halt();
        }

        unset($data['upline_id']);                                   // written by the service, with everything that follows it
        $record = parent::handleRecordUpdate($record, $data);

        @set_time_limit(600);
        $r = $svc->move($record, $senior, true);
        app(NetworkService::class)->recomputeAll();
        $record->refresh();

        $lines = [
            __(':n bill(s) moved to the new senior', ['n' => $r['bonds']]),
            __('Pending Sales Incentive taken off the old seniors: :n (₹:amt)', ['n' => $r['ic_removed'], 'amt' => number_format($r['ic_removed_amount'], 2)]),
            __('Sales Incentive issued to the new seniors, pending approval: :n (₹:amt)', ['n' => $r['ic_issued'], 'amt' => number_format($r['ic_issued_amount'], 2)]),
            __('BV / GBV / team size / TBP stage recomputed.'),
        ];
        if ($r['paid_wrong']) {
            $lines[] = __(':n Sales Incentive entr(ies), ₹:amt, were ALREADY approved or paid to the old seniors — left as they are. Run members:fix-placement --member=:code --reissue-paid to also issue them to the new seniors.', [
                'n' => count($r['paid_wrong']), 'amt' => number_format(array_sum(array_column($r['paid_wrong'], 'amount')), 2), 'code' => $record->member_code,
            ]);
        }
        Notification::make()->{$r['paid_wrong'] ? 'warning' : 'success'}()
            ->title(__('Senior Distributor changed: :from → :to', ['from' => $r['from'] ?? '—', 'to' => $r['to']]))
            ->body(implode("\n", $lines))->persistent()->send();

        return $record;
    }
}
