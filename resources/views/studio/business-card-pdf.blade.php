<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $card['title'] }} — Business Card</title>
    <style>
        @page { margin: 0; size: auto; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #0b1220;
        }
        .card {
            width: 252px;
            height: 144px;
            position: relative;
            overflow: hidden;
            page-break-inside: avoid;
            page-break-after: avoid;
        }
        .card.digital {
            width: 260px;
            height: 460px;
        }
        .wrap {
            word-wrap: break-word;
            overflow-wrap: anywhere;
            word-break: break-word;
            white-space: normal;
        }
        .classic { background: #fff; border-left: 10px solid {{ $card['styles']['primary_color'] }}; padding: 12px 14px; }
        .modern { background: {{ $card['styles']['secondary_color'] }}; color: #fff; padding: 12px 14px; }
        .minimal { background: #fff; padding: 14px 16px; }
        .connect { background: #fff; padding: 12px 14px; border-top: 8px solid {{ $card['styles']['primary_color'] }}; }
        .digital { background: #fff; }
        .company { font-size: 8px; text-transform: uppercase; letter-spacing: .08em; opacity: .75; margin: 0 0 4px; }
        .logo { max-height: 28px; max-width: 110px; margin-bottom: 6px; display: block; }
        .digital .logo { max-height: 36px; max-width: 140px; margin-bottom: 8px; }
        .name { font-size: 13px; font-weight: bold; margin: 0; line-height: 1.2; }
        .role { font-size: 9px; margin: 2px 0 6px; opacity: .85; }
        .meta { font-size: 8px; line-height: 1.4; margin: 0; }
        .desc {
            font-size: 8px;
            line-height: 1.35;
            font-weight: 600;
            color: #0f172a;
            margin: 4px 0 6px;
            text-align: left;
        }
        .modern .desc { color: rgba(255,255,255,.92); }
        .modern .top { height: 28px; margin: -12px -14px 8px; background: {{ $card['styles']['primary_color'] }}; }
        .row { width: 100%; }
        .row:after { content: ""; display: table; clear: both; }
        .col-left { float: left; width: 68%; }
        .col-right { float: right; width: 28%; text-align: right; }
        .photo {
            width: 48px;
            height: 48px;
            border-radius: 24px;
            object-fit: cover;
            display: block;
            margin-left: auto;
            border: 2px solid {{ $card['styles']['primary_color'] }};
        }
        .photo-lg {
            width: 64px;
            height: 64px;
            border-radius: 32px;
            object-fit: cover;
            display: block;
            border: 3px solid {{ $card['styles']['primary_color'] }};
        }
        .photo-fallback {
            width: 48px;
            height: 48px;
            border-radius: 24px;
            background: {{ $card['styles']['primary_color'] }};
            color: #fff;
            text-align: center;
            line-height: 48px;
            font-size: 16px;
            font-weight: bold;
            margin-left: auto;
        }
        .digital-head {
            background: #f8fafb;
            color: {{ $card['styles']['secondary_color'] }};
            padding: 14px 14px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .digital-head .name { color: {{ $card['styles']['secondary_color'] }}; font-size: 16px; }
        .digital-head .company { color: {{ $card['styles']['primary_color'] }}; opacity: 1; font-size: 9px; }
        .digital-head .role { color: #64748b; opacity: 1; font-size: 10px; margin: 3px 0 0; }
        .digital-bio {
            background: #f8fafc;
            padding: 10px 14px;
            font-size: 10px;
            line-height: 1.45;
            font-weight: 600;
            color: #0f172a;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        .digital-meta { padding: 12px 14px; font-size: 10px; line-height: 1.55; color: #0f172a; }
        .digital-meta .line { margin-bottom: 6px; }
        .digital-meta .label { color: #64748b; font-size: 8px; }
    </style>
</head>
<body>
@php
    $t = $template ?? 'classic';
    if ($t === 'bold') {
        $t = 'classic';
    }
    $initial = strtoupper(mb_substr((string) ($card['person_name'] ?: $card['company_name'] ?: 'C'), 0, 1));
@endphp
<div class="card {{ $t }}">
    @if($t === 'digital')
        <div class="digital-head">
            <div class="row">
                <div class="col-left">
                    @if(!empty($card['logo_url']))
                        <img class="logo" src="{{ $card['logo_url'] }}" alt="">
                    @elseif(!empty($card['company_name']))
                        <div class="company">{{ $card['company_name'] }}</div>
                    @endif
                    @if(!empty($card['person_name']))
                        <div class="name wrap">{{ $card['person_name'] }}</div>
                    @endif
                    @if(!empty($card['person_title']))
                        <div class="role wrap">{{ $card['person_title'] }}</div>
                    @endif
                </div>
                <div class="col-right">
                    @if(!empty($card['person_photo_url']))
                        <img class="photo-lg" src="{{ $card['person_photo_url'] }}" alt="">
                    @else
                        <div class="photo-fallback" style="width:64px;height:64px;line-height:64px;border-radius:32px;font-size:20px;">{{ $initial }}</div>
                    @endif
                </div>
            </div>
        </div>
        @if(!empty($card['tagline']))
            <div class="digital-bio wrap">{{ $card['tagline'] }}</div>
        @endif
        <div class="digital-meta">
            @if(!empty($card['phone']))
                <div class="line wrap">{{ $card['phone'] }}<div class="label">Work</div></div>
            @endif
            @if(!empty($card['email']))
                <div class="line wrap">{{ $card['email'] }}<div class="label">Email</div></div>
            @endif
            @if(!empty($card['website']))
                <div class="line wrap">{{ $card['website'] }}<div class="label">Website</div></div>
            @endif
            @if(!empty($card['address']))
                <div class="line wrap">{{ $card['address'] }}<div class="label">Address</div></div>
            @endif
        </div>
    @else
        @if($t === 'modern')
            <div class="top"></div>
        @endif
        <div class="row">
            <div class="col-left">
                @if(!empty($card['logo_url']))
                    <img class="logo" src="{{ $card['logo_url'] }}" alt="">
                @elseif(!empty($card['company_name']))
                    <div class="company">{{ $card['company_name'] }}</div>
                @endif
                @if(!empty($card['person_name']))
                    <div class="name wrap">{{ $card['person_name'] }}</div>
                @endif
                @if(!empty($card['person_title']))
                    <div class="role wrap">{{ $card['person_title'] }}</div>
                @endif
                @if(!empty($card['tagline']))
                    <div class="desc wrap">{{ \Illuminate\Support\Str::limit($card['tagline'], 90) }}</div>
                @endif
                <div class="meta wrap">
                    @if(!empty($card['phone'])){{ $card['phone'] }}<br>@endif
                    @if(!empty($card['email'])){{ $card['email'] }}<br>@endif
                    @if(!empty($card['website'])){{ $card['website'] }}@endif
                </div>
            </div>
            <div class="col-right">
                @if(!empty($card['person_photo_url']))
                    <img class="photo" src="{{ $card['person_photo_url'] }}" alt="">
                @else
                    <div class="photo-fallback">{{ $initial }}</div>
                @endif
            </div>
        </div>
    @endif
</div>
</body>
</html>
