<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $brochure['title'] }} — Brochure</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0b1220; }
        h1 { font-size: 26px; margin: 0 0 6px; color: {{ $brochure['styles']['secondary_color'] }}; }
        h2 { font-size: 16px; margin: 0 0 8px; color: {{ $brochure['styles']['primary_color'] }}; border-bottom: 2px solid {{ $brochure['styles']['accent_color'] }}; padding-bottom: 4px; }
        .cover { page-break-after: always; padding-top: 160px; text-align: center; }
        .cover .tag { display: inline-block; margin-top: 14px; padding: 4px 10px; background: {{ $brochure['styles']['accent_color'] }}; color: #0b1220; font-size: 10px; font-weight: bold; }
        .muted { color: #5b667a; }
        .section { page-break-inside: avoid; margin-bottom: 22px; }
        ul { margin: 8px 0 0 16px; padding: 0; }
        li { margin: 3px 0; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #5b667a; text-align: center; }
    </style>
</head>
<body>
@php
    $sections = $brochure['enabled_sections'] ?? [];
    $cover = collect($sections)->firstWhere('key', 'cover');
@endphp

<div class="cover">
    @if(!empty($brochure['logo_url']))
        <div style="margin-bottom:18px;"><img src="{{ $brochure['logo_url'] }}" style="height:48px;"></div>
    @endif
    <h1>{{ $brochure['headline'] ?: ($cover['title'] ?? $brochure['company_name']) }}</h1>
    <div class="muted" style="font-size:13px;">{{ $brochure['subheadline'] ?: ($cover['body'] ?? '') }}</div>
    <div class="tag">{{ $brochure['title'] }}</div>
</div>

@foreach($sections as $section)
    @if(($section['key'] ?? '') === 'cover')
        @continue
    @endif
    <div class="section">
        <h2>{{ $section['title'] ?? 'Section' }}</h2>
        @if(!empty($section['body']))
            <p>{{ $section['body'] }}</p>
        @endif
        @if(!empty($section['items']))
            <ul>
                @foreach($section['items'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endforeach

@if(!empty($brochure['styles']['default_footer']))
    <div class="footer">{{ $brochure['styles']['default_footer'] }}</div>
@endif
</body>
</html>
