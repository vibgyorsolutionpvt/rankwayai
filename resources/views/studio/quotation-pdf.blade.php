<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation['number'] }} — Quotation</title>
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
        ul { margin: 6px 0 0; padding-left: 18px; }
        li { margin-bottom: 3px; }
    </style>
</head>
<body>
    @php
        $currency = $quotation['currency'] ?? 'INR';
        $fmt = function ($n) use ($currency) {
            $v = number_format((float) $n, 2);
            return $currency === 'INR' ? '₹'.$v : $currency.' '.$v;
        };
    @endphp

    <div class="row">
        <div class="col">
            <h1>{{ $quotation['company_name'] ?? $workspace->name }}</h1>
            <div class="muted">Quotation</div>
        </div>
        <div class="col col-right">
            <div style="font-size:18px;font-weight:bold;">{{ $quotation['number'] }}</div>
            <div class="muted" style="margin-top:4px;">{{ $generated_at }}</div>
            <div style="margin-top:6px;"><span class="badge">{{ strtoupper($quotation['status']) }}</span></div>
        </div>
    </div>

    <h2>{{ $quotation['title'] }}</h2>

    <div class="row">
        <div class="col">
            <div class="muted">Bill to</div>
            <div style="font-weight:bold;margin-top:4px;">{{ $quotation['customer_name'] ?: 'Customer' }}</div>
            @if(!empty($quotation['customer_company']))<div>{{ $quotation['customer_company'] }}</div>@endif
            @if(!empty($quotation['customer_email']))<div>{{ $quotation['customer_email'] }}</div>@endif
            @if(!empty($quotation['customer_phone']))<div>{{ $quotation['customer_phone'] }}</div>@endif
        </div>
        <div class="col col-right">
            @if(!empty($quotation['trip_title']) || !empty($quotation['destination']))
                <div class="muted">Trip</div>
                <div>{{ $quotation['trip_title'] ?: $quotation['destination'] }}</div>
            @endif
            @if(!empty($quotation['duration_days']))
                <div class="muted" style="margin-top:8px;">Duration</div>
                <div>{{ $quotation['duration_days'] }} days · {{ $quotation['travellers'] }} travellers</div>
            @endif
            @if(!empty($quotation['valid_until']))
                <div class="muted" style="margin-top:8px;">Valid until</div>
                <div>{{ \Illuminate\Support\Carbon::parse($quotation['valid_until'])->format('d M Y') }}</div>
            @endif
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
            @foreach($quotation['line_items'] as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item['qty'], 2), '0'), '.') }}</td>
                    <td class="num">{{ $fmt($item['unit_price'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($item['amount'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Subtotal</td>
            <td class="num">{{ $fmt($quotation['subtotal']) }}</td>
        </tr>
        @if(($quotation['discount_amount'] ?? 0) > 0)
            <tr>
                <td class="muted">Discount</td>
                <td class="num">-{{ $fmt($quotation['discount_amount']) }}</td>
            </tr>
        @endif
        <tr>
            <td class="muted">Tax ({{ rtrim(rtrim(number_format((float) ($quotation['tax_percent'] ?? 0), 2), '0'), '.') }}%)</td>
            <td class="num">{{ $fmt($quotation['tax_amount'] ?? 0) }}</td>
        </tr>
        <tr>
            <td class="grand">Total</td>
            <td class="num grand">{{ $fmt($quotation['total']) }}</td>
        </tr>
    </table>

    @if(!empty($quotation['inclusions']))
        <h2>Inclusions</h2>
        <ul>
            @foreach($quotation['inclusions'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    @if(!empty($quotation['exclusions']))
        <h2>Exclusions</h2>
        <ul>
            @foreach($quotation['exclusions'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    @if(!empty($quotation['payment_terms']))
        <div class="notes">
            <div class="muted" style="margin-bottom:4px;">Payment terms</div>
            {!! nl2br(e($quotation['payment_terms'])) !!}
        </div>
    @endif

    @if(!empty($quotation['notes']))
        <div class="notes">
            <div class="muted" style="margin-bottom:4px;">Notes</div>
            {!! nl2br(e($quotation['notes'])) !!}
        </div>
    @endif

    <div class="muted" style="margin-top:28px;">Generated {{ $generated_at }} · rankwayAI</div>
</body>
</html>
