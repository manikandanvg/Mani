<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Device;
use App\Services\Qr\QrCodeService;
use Filament\Pages\Page;

/**
 * L-BOX → My Branch QR (dealer login). Board 2026-10-31: launching without boxes, every
 * dealer collects through its own STATIC QR — the same QR an L-BOX would carry. The dealer
 * opens this page, prints the card and keeps it at the counter; distributors scan it in the
 * app under Wallet → Withdraw at branch. Head Office prints the same card from
 * Master → Branches → Static QR.
 */
class MyBranchQr extends Page
{
    use \App\Filament\Concerns\TranslatesNavigation;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'My Branch QR';

    protected static ?string $title = 'My Branch QR';

    protected static ?string $slug = 'my-branch-qr';

    protected static string $view = 'filament.pages.my-branch-qr';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && method_exists($u, 'isDistributor') && $u->isDistributor() && $u->branch_id);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getBranch(): ?Branch
    {
        return Branch::find(auth()->user()?->branch_id);
    }

    /** The branch's static QR device — generated on first visit if HQ has not done it yet. */
    public function getDevice(): ?Device
    {
        return $this->getBranch()?->ensureStaticQr();
    }

    public function getQrUrl(): ?string
    {
        $device = $this->getDevice();

        return $device ? app(QrCodeService::class)->store($device->uuid, "lbox-{$device->serial_no}") : null;
    }
}
