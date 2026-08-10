<?php

namespace App\Filament\Widgets;

use App\Models\SupportThread;
use App\Models\SupportTicket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The support persona's dashboard (board spec 2026-08-09): their world is tickets
 * and chat, so that's all the dashboard shows — open/closed tickets, what's
 * assigned to them, and live chat threads awaiting a reply.
 */
class SupportOverview extends BaseWidget
{
    protected static ?int $sort = -4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isSupport() ?? false;
    }

    protected function getStats(): array
    {
        $open = SupportTicket::where('status', 'open')->count();
        $closedMonth = SupportTicket::where('status', 'closed')
            ->where('closed_at', '>=', now()->startOfMonth())->count();
        $mine = SupportTicket::where('status', 'open')->where('assigned_to', auth()->id())->count();
        $urgent = SupportTicket::where('status', 'open')->whereIn('priority', ['high', 'urgent'])->count();
        $chats = SupportThread::where('status', 'open')->count();

        return [
            Stat::make(__('Open tickets'), (string) $open)
                ->description(__(':n high / urgent', ['n' => $urgent]))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($urgent > 0 ? 'danger' : 'warning'),

            Stat::make(__('Assigned to me'), (string) $mine)
                ->descriptionIcon('heroicon-m-user')
                ->color('info'),

            Stat::make(__('Closed this month'), (string) $closedMonth)
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make(__('Open chat threads'), (string) $chats)
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($chats > 0 ? 'warning' : 'gray'),
        ];
    }
}
