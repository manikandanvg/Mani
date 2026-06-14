<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Payment')->columns(2)->schema([
                Infolists\Components\TextEntry::make('order.order_no')->label('Order'),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('amount')->baseMoney(),
                Infolists\Components\TextEntry::make('method')->placeholder('—'),
                Infolists\Components\TextEntry::make('razorpay_payment_id')->label('Payment ID')->copyable(),
                Infolists\Components\TextEntry::make('razorpay_order_id')->label('RZP Order')->copyable(),
                Infolists\Components\TextEntry::make('razorpay_signature')->label('Signature')->columnSpanFull(),
                Infolists\Components\TextEntry::make('email'),
                Infolists\Components\TextEntry::make('contact')->label('Phone'),
                Infolists\Components\TextEntry::make('created_at')->dateTime(),
            ]),
        ]);
    }
}
