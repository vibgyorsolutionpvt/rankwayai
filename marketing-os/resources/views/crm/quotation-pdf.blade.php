<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->number }} — Quotation</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0b1220; margin: 36px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 22px 0 8px; border-bottom: 1px solid #d5dce6; padding-bottom: 4px; }
        .muted { color: #5b667a; font-size: 11px; }
        .row { width: 100%; margin-top: 18px; }
        .col { display: inline-block; vertical-align: top; width: 48%; }
        .col-right { text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d5dce6; padding: 7px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f5f8; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 280px; margin-left: auto; margin-top: 12px; }
        .totals td { border: none; padding: 4px 0; }
        .totals .grand { font-size: 14px; font-weight: bold; border-top: 1px solid #d5dce6; padding-top: 8px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; background: #e8eef6; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .notes { margin-top: 20px; padding: 10px 12px; background: #f7f9fc; border: 1px solid #e3e8f0; }
    </style>
</head>
<body>
    <div class="row">
        <div class="col">
            <h1>{{ $workspace->name }}</h1>
            <div class="muted">Quotation</div>
        </div>
        <div class="col col-right">
            <div style="font-size:18px;font-weight:bold;">{{ $quotation->number }}</div>
            <div class="muted" style="margin-top:4px;">{{ $quotation->created_at?->timezone(config('app.timezone'))->format('d M Y') }}</div>
            <div style="margin-top:6px;"><span class="badge">{{ strtoupper($quotation->status) }}</span></div>
        </div>
    </div>

    <h2>{{ $quotation->title }}</h2>

    <div class="row">
        <div class="col">
            <div class="muted">Bill to</div>
            <div style="font-weight:bold;margin-top:4px;">{{ $lead->name }}</div>
            @if($lead->company)<div>{{ $lead->company }}</div>@endif
            @if($lead->email)<div>{{ $lead->email }}</div>@endif
            @if($lead->phone)<div>{{ $lead->phone }}</div>@endif
        </div>
        <div class="col col-right">
            @if($quotation->valid_until)
                <div class="muted">Valid until</div>
                <div>{{ $quotation->valid_until->format('d M Y') }}</div>
            @endif
            <div class="muted" style="margin-top:8px;">Currency</div>
            <div>{{ $quotation->currency }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->line_items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item['qty'], 2), '0'), '.') }}</td>
                    <td class="num">${{ number_format(($item['unit_cents'] ?? 0) / 100, 2) }}</td>
                    <td class="num">${{ number_format(($item['total_cents'] ?? 0) / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Subtotal</td>
            <td class="num">${{ number_format($quotation->subtotal_cents / 100, 2) }}</td>
        </tr>
        <tr>
            <td class="muted">Tax ({{ rtrim(rtrim(number_format((float) $quotation->tax_percent, 2), '0'), '.') }}%)</td>
            <td class="num">${{ number_format($quotation->tax_cents / 100, 2) }}</td>
        </tr>
        <tr>
            <td class="grand">Total</td>
            <td class="num grand">${{ number_format($quotation->total_cents / 100, 2) }}</td>
        </tr>
    </table>

    @if($quotation->notes)
        <div class="notes">
            <div class="muted" style="margin-bottom:4px;">Notes</div>
            {!! nl2br(e($quotation->notes)) !!}
        </div>
    @endif

    <div class="muted" style="margin-top:28px;">Generated {{ $generated_at }} · rankwayAI</div>
</body>
</html>
