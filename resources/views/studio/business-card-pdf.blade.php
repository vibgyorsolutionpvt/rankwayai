<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $card['title'] }} — Business Card</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; color: #0b1220; }
        .card {
            width: 252px;
            height: 144px;
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }
        .card.digital {
            width: 220px;
            height: 380px;
        }
        .classic { background: #fff; border-left: 10px solid {{ $card['styles']['primary_color'] }}; padding: 14px 16px; }
        .modern { background: {{ $card['styles']['secondary_color'] }}; color: #fff; padding: 14px 16px; }
        .minimal { background: #fff; padding: 16px 18px; }
        .digital { background: #fff; }
        .company { font-size: 9px; text-transform: uppercase; letter-spacing: .08em; opacity: .75; margin: 0 0 6px; }
        .logo { max-height: 36px; max-width: 140px; margin-bottom: 8px; display: block; }
        .name { font-size: 15px; font-weight: bold; margin: 0; line-height: 1.2; }
        .role { font-size: 10px; margin: 3px 0 8px; opacity: .85; }
        .meta { font-size: 8.5px; line-height: 1.45; margin: 0; }
        .accent { color: {{ $card['styles']['accent_color'] }}; }
        .tag { display: inline-block; margin-top: 6px; padding: 2px 6px; font-size: 8px; font-weight: bold; background: {{ $card['styles']['accent_color'] }}; color: #0b1220; }
        .modern .top { height: 36px; margin: -14px -16px 10px; background: {{ $card['styles']['primary_color'] }}; }
        .digital-head {
            background: #f8fafb;
            color: {{ $card['styles']['secondary_color'] }};
            padding: 14px 14px 36px;
            border-bottom: 1px solid #e5e7eb;
        }
        .digital-head .name { color: {{ $card['styles']['secondary_color'] }}; font-size: 16px; }
        .digital-head .company { color: {{ $card['styles']['primary_color'] }}; opacity: 1; }
        .desc {
            font-size: 10px;
            line-height: 1.5;
            font-weight: 600;
            color: #0f172a;
            margin: 6px 0 8px;
            text-align: left;
        }
        .digital-bio {
            background: #f8fafc;
            padding: 11px 14px;
            font-size: 10px;
            line-height: 1.5;
            font-weight: 600;
            color: #0f172a;
            text-align: left;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }
        .modern .desc { color: rgba(255,255,255,.92); }
        .digital-meta { padding: 12px 14px; font-size: 9px; line-height: 1.55; }
        .digital-cta {
            margin: 0 14px 14px;
            text-align: center;
            background: {{ $card['styles']['secondary_color'] }};
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            padding: 8px;
        }
    </style>
</head>
<body>
@php
    $t = $template ?? 'classic';
    if ($t === 'bold') {
        $t = 'classic';
    }
@endphp
<div class="card {{ $t }}">
    @if($t === 'digital')
        <div class="digital-head">
            @if(!empty($card['logo_url']))
                <img class="logo" src="{{ $card['logo_url'] }}" alt="">
            @else
                <div class="company">{{ $card['company_name'] }}</div>
            @endif
            @if(!empty($card['person_name']))
                <div class="name">{{ $card['person_name'] }}</div>
            @endif
            @if(!empty($card['person_title']))
                <div class="role">{{ $card['person_title'] }}</div>
            @endif
        </div>
        @if(!empty($card['tagline']))
            <div class="digital-bio">{{ $card['tagline'] }}</div>
        @endif
        <div class="digital-meta">
            @if(!empty($card['phone'])){{ $card['phone'] }}<br>@endif
            @if(!empty($card['email'])){{ $card['email'] }}<br>@endif
            @if(!empty($card['website'])){{ $card['website'] }}@endif
        </div>
    @else
        @if($t === 'modern')
            <div class="top"></div>
        @endif
        @if(!empty($card['logo_url']))
            <img class="logo" src="{{ $card['logo_url'] }}" alt="">
        @else
            <div class="company">{{ $card['company_name'] }}</div>
        @endif
        @if(!empty($card['person_name']))
            <div class="name">{{ $card['person_name'] }}</div>
        @endif
        @if(!empty($card['person_title']))
            <div class="role">{{ $card['person_title'] }}</div>
        @endif
        @if(!empty($card['tagline']))
            <div class="desc">{{ $card['tagline'] }}</div>
        @endif
        <div class="meta">
            @if(!empty($card['phone'])){{ $card['phone'] }}<br>@endif
            @if(!empty($card['email'])){{ $card['email'] }}<br>@endif
            @if(!empty($card['website'])){{ $card['website'] }}@endif
        </div>
    @endif
</div>
</body>
</html>
