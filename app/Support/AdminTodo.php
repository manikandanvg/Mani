<?php

namespace App\Support;

use App\Filament\Resources\BranchOrderResource;
use App\Filament\Resources\BranchPayoutRequestResource;
use App\Filament\Resources\BranchSourceChangeRequestResource;
use App\Filament\Resources\SalesReturnResource;
use App\Filament\Resources\StockReturnResource;
use App\Filament\Resources\SupportThreadResource;
use App\Filament\Resources\SupportTicketResource;
use App\Models\BranchOrderRequest;
use App\Models\BranchPayoutRequest;
use App\Models\BranchSourceChangeRequest;
use App\Models\SalesReturn;
use App\Models\StockReturn;
use App\Models\SupportThread;
use App\Models\SupportTicket;

/**
 * Admin To-do (board 2026-11-27): everything that waits for Head Office, in one list. Shown as a
 * floating button on the dashboard — the button carries the total, each line its own count, and a
 * line opens its page already filtered to what is pending.
 *
 * A cancel request (ticket category "Cancel Invoice/Contract") has its own line because only
 * Head Office can cancel; it is left out of "Distributor Tickets" so the total counts it once.
 */
class AdminTodo
{
    /** Head Office only — never a dealer login, never the support desk. */
    public static function visible(): bool
    {
        $u = auth()->user();

        return (bool) ($u && ! $u->isDistributor() && ! $u->isSupport());
    }

    /**
     * @return array<int, array{label: string, hint: string, count: int, url: string, icon: string}>
     */
    public static function items(): array
    {
        $filter = fn (array $filters) => ['tableFilters' => array_map(fn ($v) => is_array($v) ? ['values' => $v] : ['value' => $v], $filters)];

        return [
            [
                'label' => 'Stock Returns', 'hint' => 'Branch → Head Office, waiting for approval', 'icon' => 'heroicon-o-arrow-uturn-left',
                'count' => StockReturn::where('status', 'pending')->count(),
                'url' => StockReturnResource::getUrl('index', $filter(['status' => 'pending'])),
            ],
            [
                'label' => 'Sales Returns', 'hint' => 'Coins to be collected', 'icon' => 'heroicon-o-receipt-refund',
                'count' => SalesReturn::where('status', SalesReturn::STATUS_PENDING)->count(),
                'url' => SalesReturnResource::getUrl('index', $filter(['status' => SalesReturn::STATUS_PENDING])),
            ],
            [
                'label' => 'Order Requests', 'hint' => 'Pending — old-system (LEG-) orders left out', 'icon' => 'heroicon-o-inbox-arrow-down',
                'count' => BranchOrderRequest::where('status', 'pending')->where('source', '<>', 'legacy')->count(),
                'url' => BranchOrderResource::getUrl('index', $filter(['status' => 'pending', 'source' => 'not_legacy'])),
            ],
            [
                'label' => 'Branch Payout Requests', 'hint' => 'Requested — to be paid', 'icon' => 'heroicon-o-banknotes',
                'count' => BranchPayoutRequest::where('status', 'requested')->count(),
                'url' => BranchPayoutRequestResource::getUrl('index', $filter(['status' => ['requested']])),
            ],
            [
                'label' => 'Distributor Tickets', 'hint' => 'Open tickets', 'icon' => 'heroicon-o-ticket',
                'count' => SupportTicket::where('status', 'open')
                    ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '<>', SupportTicket::CATEGORY_CANCEL))->count(),
                'url' => SupportTicketResource::getUrl('index', ['activeTab' => 'open']),
            ],
            [
                'label' => 'Support Chats', 'hint' => 'Waiting for a staff reply', 'icon' => 'heroicon-o-chat-bubble-left-right',
                'count' => SupportThread::where('status', 'open')->where('needs_staff', true)->count(),
                'url' => SupportThreadResource::getUrl('index', $filter(['status' => 'open', 'turn' => '1'])),
            ],
            [
                'label' => 'Order Source Requests', 'hint' => 'Change of supplier, waiting for a decision', 'icon' => 'heroicon-o-arrows-right-left',
                'count' => BranchSourceChangeRequest::where('status', 'pending')->count(),
                'url' => BranchSourceChangeRequestResource::getUrl('index', $filter(['status' => 'pending'])),
            ],
            [
                'label' => 'Cancel Invoice/Contract', 'hint' => 'Tickets asking Head Office to cancel', 'icon' => 'heroicon-o-x-circle',
                'count' => SupportTicket::where('status', 'open')->where('category', SupportTicket::CATEGORY_CANCEL)->count(),
                'url' => SupportTicketResource::getUrl('index', ['activeTab' => 'open'] + $filter(['category' => [SupportTicket::CATEGORY_CANCEL]])),
            ],
        ];
    }
}
