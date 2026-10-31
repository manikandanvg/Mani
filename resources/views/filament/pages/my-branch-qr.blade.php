<x-filament-panels::page>
    @php($device = $this->getDevice())
    @if (! $device)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            {{ __('Your login is not linked to a branch yet — ask Head Office.') }}
        </div>
    @else
        <div class="space-y-4">
            <p class="text-sm text-gray-600">
                {{ __('This is your branch\'s static payment QR — the same QR an L-BOX carries. Print it and keep it at the counter: distributors scan it in the LORDICL app (Wallet → Withdraw at branch) to pay or withdraw their wallet with you. Head Office sees the same QR under Master → Branches.') }}
            </p>

            <div id="icl-branch-qr-card">
                @include('filament.modals.device-static-qr', ['device' => $device, 'qrUrl' => $this->getQrUrl()])
            </div>

            <div class="flex flex-wrap gap-3">
                <x-filament::button icon="heroicon-o-printer" color="gray" onclick="window.print()">
                    {{ __('Print') }}
                </x-filament::button>
                <x-filament::button icon="heroicon-o-arrow-down-tray" tag="a" href="{{ $this->getQrUrl() }}" download="branch-qr-{{ $device->serial_no }}.png" color="gray">
                    {{ __('Download PNG') }}
                </x-filament::button>
            </div>
        </div>

        <style>
            @media print {
                body * { visibility: hidden !important; }
                #icl-branch-qr-card, #icl-branch-qr-card * { visibility: visible !important; }
                #icl-branch-qr-card { position: absolute; left: 0; top: 0; width: 100%; }
            }
        </style>
    @endif
</x-filament-panels::page>
