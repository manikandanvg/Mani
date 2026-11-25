{{-- Force delete of a member (user 2026-11-22): everything issued against the member, and what
     happens to each value, shown BEFORE the admin confirms. $s = MemberForceDeleteService::statement() --}}
@php
    $money = fn ($v) => '₹' . \App\Support\Money::group((float) $v);
    $th = 'padding:6px 8px;text-align:left;font-size:12px;font-weight:600;border-bottom:1px solid rgba(128,128,128,.35);white-space:nowrap;';
    $td = 'padding:6px 8px;font-size:13px;border-bottom:1px solid rgba(128,128,128,.18);vertical-align:top;';
    $num = $td . 'text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;';
    $box = 'border:1px solid rgba(128,128,128,.3);border-radius:10px;padding:10px 12px;';
@endphp
<div style="display:flex;flex-direction:column;gap:14px;">
    @if ($s['blockers'])
        <div style="{{ $box }}border-color:#dc2626;background:rgba(220,38,38,.08);">
            <div style="font-weight:700;color:#dc2626;margin-bottom:4px;">Force delete is not possible yet</div>
            <ul style="margin:0;padding-left:18px;font-size:13px;">
                @foreach ($s['blockers'] as $why)
                    <li>{{ $why }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">
        <div style="{{ $box }}">
            <div style="font-size:12px;opacity:.7;">Paid out / redeemed</div>
            <div style="font-size:18px;font-weight:700;">{{ $money($s['totals']['issued']) }}</div>
        </div>
        <div style="{{ $box }}">
            <div style="font-size:12px;opacity:.7;">Taken back from wallets</div>
            <div style="font-size:18px;font-weight:700;">{{ $money($s['totals']['recover']) }}</div>
        </div>
        <div style="{{ $box }}border-color:#dc2626;">
            <div style="font-size:12px;opacity:.7;">Charged to branch cash stock</div>
            <div style="font-size:18px;font-weight:700;color:#dc2626;">{{ $money($s['totals']['charge']) }}</div>
        </div>
        <div style="{{ $box }}">
            <div style="font-size:12px;opacity:.7;">Not paid yet — simply removed</div>
            <div style="font-size:18px;font-weight:700;">{{ $money($s['totals']['reverse']) }}</div>
        </div>
    </div>

    <div style="font-size:12px;opacity:.75;margin-top:-6px;">
        @if (! empty($s['only']))
            These figures cover <b>this bill / contract only ({{ $s['only'] }})</b>. {{ $s['member']->member_code }} and their other contracts are not touched.
            Tick "Delete the member + the rest of their contracts" above to see and close all of them.
        @else
            These figures cover <b>all {{ count($s['bills']) }} bill(s) / contract(s)</b> of {{ $s['member']->member_code }}.
            The "Bill" column below shows which bill each value belongs to.
        @endif
    </div>

    <div>
        <div style="font-weight:600;margin-bottom:4px;">Bills and contracts that will be cancelled ({{ count($s['bills']) }})</div>
        @if ($s['bills'])
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr>
                        <th style="{{ $th }}">Bill</th><th style="{{ $th }}">Date</th><th style="{{ $th }}">Scheme</th>
                        <th style="{{ $th }}">Issued by</th><th style="{{ $th }}text-align:right;">Amount</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($s['bills'] as $b)
                            <tr>
                                <td style="{{ $td }}">{{ $b['invoice_no'] }}</td><td style="{{ $td }}">{{ $b['date'] ?? '—' }}</td>
                                <td style="{{ $td }}">{{ $b['plan'] ?? '—' }}</td><td style="{{ $td }}">{{ $b['branch'] }}</td>
                                <td style="{{ $num }}">{{ $money($b['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="font-size:13px;opacity:.7;">No standing bill or contract.</div>
        @endif
    </div>

    <div>
        <div style="font-weight:600;margin-bottom:4px;">Values issued against this member</div>
        @if ($s['rows'])
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr>
                        <th style="{{ $th }}">Bill</th><th style="{{ $th }}">Value</th><th style="{{ $th }}">Details</th>
                        <th style="{{ $th }}text-align:right;">Amount</th><th style="{{ $th }}">What happens</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($s['rows'] as $r)
                            <tr>
                                <td style="{{ $td }}white-space:nowrap;">{{ $r['bill'] }}</td>
                                <td style="{{ $td }}font-weight:600;">{{ $r['kind'] }}</td>
                                <td style="{{ $td }}">{{ $r['detail'] }}</td>
                                <td style="{{ $num }}">{{ $money($r['amount']) }}</td>
                                <td style="{{ $td }}">
                                    @if ($r['action'] === 'reverse')
                                        Removed — nothing was paid
                                    @elseif ($r['action'] === 'info')
                                        Shown only — not moved
                                    @else
                                        @if ($r['recover'] > 0)
                                            <div>{{ $money($r['recover']) }} taken back from the wallet of {{ $r['wallet_member'] }}</div>
                                        @endif
                                        @if ($r['charge'] > 0)
                                            <div style="color:#dc2626;font-weight:600;">{{ $money($r['charge']) }} charged to {{ $r['branch'] }}{{ $r['action'] === 'recover' ? ' (already withdrawn)' : '' }}</div>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="font-size:13px;opacity:.7;">Nothing was issued — no incentive, coupon, margin or redemption on record.</div>
        @endif
    </div>

    {{-- User 2026-11-25: the settlement is a separate step — its amount is worked out on the Settlements page --}}
    @if (! empty($s['settlements']))
        <div style="{{ $box }}font-size:13px;">
            <b>Settlement:</b> not done here. {{ count($s['settlements']) === 1 ? 'This contract goes' : 'These ' . count($s['settlements']) . ' contracts go' }}
            to Sales &amp; Contracts → Settlements as <b>Force closed</b>; the amount is worked out and settled there.
        </div>
    @endif

    @if ($s['charges'])
        <div>
            <div style="font-weight:600;margin-bottom:4px;">Cash stock of the issuing branch</div>
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr>
                    <th style="{{ $th }}">Branch</th><th style="{{ $th }}text-align:right;">Cash stock now</th>
                    <th style="{{ $th }}text-align:right;">Deducted</th><th style="{{ $th }}text-align:right;">Cash stock after</th>
                </tr></thead>
                <tbody>
                    @foreach ($s['charges'] as $c)
                        <tr>
                            <td style="{{ $td }}">{{ $c['branch'] }}</td>
                            <td style="{{ $num }}">{{ $money($c['cash_stock']) }}</td>
                            <td style="{{ $num }}color:#dc2626;">− {{ $money($c['amount']) }}</td>
                            <td style="{{ $num }}font-weight:700;{{ $c['after'] < 0 ? 'color:#dc2626;' : '' }}">{{ $c['after'] < 0 ? '− ' . $money(abs($c['after'])) . ' (below zero)' : $money($c['after']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($s['totals']['forfeit'] > 0 || $s['forfeit']['gold_g'] > 0 || $s['forfeit']['silver_g'] > 0)
        <div style="{{ $box }}font-size:13px;">
            <b>Member's own wallet:</b> what is left after the recoveries above is forfeited —
            cash {{ $money($s['forfeit']['cash']) }}, coupon {{ $money($s['forfeit']['coupon']) }}, e-pin {{ $money($s['forfeit']['epin']) }},
            Digi gold {{ rtrim(rtrim(number_format($s['forfeit']['gold_g'], 4), '0'), '.') ?: '0' }} g,
            Digi silver {{ rtrim(rtrim(number_format($s['forfeit']['silver_g'], 4), '0'), '.') ?: '0' }} g.
        </div>
    @endif

    <div style="font-size:12px;opacity:.75;">
        Sold stock returns to each billing branch and the network volumes (BV / GBV) are rolled back, as with "Cancel invoice".
        @if (! empty($s['only']))
            The member's profile, wallet and other contracts stay as they are; this bill stays on record as CANCELLED. This cannot be undone.
        @else
            The member's profile, wallet, coupons and QRs are copied to the separate deleted-members store and then removed from the live data;
            the bills stay on record as CANCELLED. This cannot be undone.
        @endif
    </div>
</div>
