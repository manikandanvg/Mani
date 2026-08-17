<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #111827; margin: 26mm 16mm; }
    .head { text-align: center; border-bottom: 2px solid #ab222f; padding-bottom: 8px; }
    .head h1 { margin: 0; color: #ab222f; font-size: 18px; letter-spacing: .04em; }
    .head .sub { color: #6b7280; font-size: 9px; }
    .title { text-align: center; font-weight: bold; font-size: 13px; margin: 12px 0 4px; letter-spacing: .08em; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 3px 6px; }
    .meta .k { color: #6b7280; width: 22%; }
    .meta .v { font-weight: bold; width: 28%; }
    .box { border: 1px solid #e5e7eb; border-radius: 4px; margin-top: 10px; }
    .grid th, .grid td { border: 1px solid #e5e7eb; padding: 5px 8px; }
    .grid th { background: #faf5f5; color: #ab222f; text-align: left; }
    .num { text-align: right; }
    .net { background: #faf5f5; font-weight: bold; }
    .words { margin-top: 8px; font-style: italic; color: #374151; }
    .foot { margin-top: 26px; color: #6b7280; font-size: 8.5px; text-align: center; }
</style>
</head>
<body>
    <div class="head">
        <h1>{{ $company['name'] }}</h1>
        <div class="sub">{{ $company['legal_name'] }}</div>
    </div>

    <div class="title">SALARY SLIP — {{ \Illuminate\Support\Carbon::create($run->period_year, $run->period_month, 1)->format('F Y') }}</div>

    <table class="meta">
        <tr>
            <td class="k">Employee</td><td class="v">{{ $member?->name ?? '—' }}</td>
            <td class="k">Employee Code</td><td class="v">{{ $employee?->employee_code }}</td>
        </tr>
        <tr>
            <td class="k">Designation</td><td class="v">{{ $employee?->designation ?? '—' }}</td>
            <td class="k">Branch</td><td class="v">{{ $member?->branch?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Date of Joining</td><td class="v">{{ optional($employee?->date_of_joining)->format('d-m-Y') }}</td>
            <td class="k">PAN</td><td class="v">{{ $member?->pan ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">UAN (PF)</td><td class="v">{{ $employee?->uan ?: '—' }}</td>
            <td class="k">ESIC No</td><td class="v">{{ $employee?->esic_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">Days in Month</td><td class="v">{{ $run->working_days }}</td>
            <td class="k">Payable Days</td><td class="v">{{ rtrim(rtrim(number_format((float) $slip->payable_days, 1), '0'), '.') }}</td>
        </tr>
    </table>

    <div class="box">
        <table class="grid">
            <tr>
                <th style="width:35%">Earnings</th><th class="num" style="width:15%">Amount (₹)</th>
                <th style="width:35%">Deductions</th><th class="num" style="width:15%">Amount (₹)</th>
            </tr>
            <tr>
                <td>Basic ({{ rtrim(rtrim(number_format((float) ($slip->snapshot['basic_pct'] ?? 50), 2), '0'), '.') }}%)</td>
                <td class="num">{{ \App\Support\Money::group((float) $slip->basic) }}</td>
                <td>Provident Fund (EPF)</td>
                <td class="num">{{ \App\Support\Money::group((float) $slip->pf_employee) }}</td>
            </tr>
            <tr>
                <td>Other Allowances</td>
                <td class="num">{{ \App\Support\Money::group((float) $slip->gross - (float) $slip->basic) }}</td>
                <td>ESI</td>
                <td class="num">{{ \App\Support\Money::group((float) $slip->esi_employee) }}</td>
            </tr>
            <tr>
                <td></td><td class="num"></td>
                <td>TDS {{ ($slip->snapshot['tds_mode'] ?? 'flat') === 'slab' ? '(Sec 192)' : '' }}</td>
                <td class="num">{{ \App\Support\Money::group((float) $slip->tds) }}</td>
            </tr>
            <tr>
                <th>Gross Earnings</th>
                <th class="num">{{ \App\Support\Money::group((float) $slip->gross) }}</th>
                <th>Total Deductions</th>
                <th class="num">{{ \App\Support\Money::group((float) $slip->pf_employee + (float) $slip->esi_employee + (float) $slip->tds) }}</th>
            </tr>
            <tr class="net">
                <td colspan="3">NET PAY</td>
                <td class="num">₹ {{ \App\Support\Money::group((float) $slip->net) }}</td>
            </tr>
        </table>
    </div>

    <div class="words">Net pay in words: {{ $netWords }}</div>

    <table class="meta" style="margin-top:8px">
        <tr>
            <td class="k">Employer PF Contribution</td><td class="v">₹ {{ \App\Support\Money::group((float) $slip->pf_employer) }}</td>
            <td class="k">Employer ESI Contribution</td><td class="v">₹ {{ \App\Support\Money::group((float) $slip->esi_employer) }}</td>
        </tr>
        <tr>
            <td class="k">Payment Status</td><td class="v" style="text-transform:uppercase">{{ $slip->status }}</td>
            <td class="k">Paid On</td><td class="v">{{ optional($slip->paid_at)->format('d-m-Y') ?? '—' }}</td>
        </tr>
    </table>

    <div class="foot">System-generated payslip — no signature required. {{ $company['legal_name'] }}</div>
</body>
</html>
