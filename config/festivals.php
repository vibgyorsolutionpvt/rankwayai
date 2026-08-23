<?php

/**
 * India marketing calendar — dates per year (update annually or run festivals:sync).
 *
 * @return array<int, list<array{name:string,occurs_on:string,category:string,suggested_angles:list<string>}>>
 */
return [
    'regions' => ['IN'],
    'country_code' => env('FESTIVAL_COUNTRY', 'IN'),

    /** Pull India public holidays from date.nager.at (free, no API key). */
    'nager_enabled' => env('FESTIVAL_NAGER_ENABLED', true),

    /**
     * Google / Apple / custom ICS calendar feeds (one URL per line in env).
     * Default: Google “Holidays in India” public calendar.
     */
    'ics_enabled' => env('FESTIVAL_ICS_ENABLED', true),
    'ics_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'FESTIVAL_ICS_URLS',
            'https://calendar.google.com/calendar/ical/en.indian%23holiday%40group.v.calendar.google.com/public/basic.ics'
        ))
    ))),

    'post_window_days' => 14,
    'list_window_days' => 45,

    /** Lunar / marketing events not always in ICS — kept as fallback override. */
    'years' => [
        2025 => [
            ['name' => 'Republic Day', 'occurs_on' => '2025-01-26', 'category' => 'festival', 'suggested_angles' => ['Pride + local business', 'Citizen offer']],
            ['name' => 'Holi', 'occurs_on' => '2025-03-14', 'category' => 'festival', 'suggested_angles' => ['Colorful creative', 'Joyful CTA']],
            ['name' => "Women's Day", 'occurs_on' => '2025-03-08', 'category' => 'awareness', 'suggested_angles' => ['Celebrate women customers', 'Team spotlight']],
            ['name' => 'Labour Day', 'occurs_on' => '2025-05-01', 'category' => 'awareness', 'suggested_angles' => ['Thank your team', 'Hard work story']],
            ['name' => 'Independence Day', 'occurs_on' => '2025-08-15', 'category' => 'festival', 'suggested_angles' => ['Patriotic offer', 'Thank your local customers']],
            ['name' => 'Raksha Bandhan', 'occurs_on' => '2025-08-09', 'category' => 'festival', 'suggested_angles' => ['Family bond offer', 'Gift ideas']],
            ['name' => 'Ganesh Chaturthi', 'occurs_on' => '2025-08-27', 'category' => 'festival', 'suggested_angles' => ['New beginnings CTA', 'Community celebration']],
            ['name' => 'Diwali', 'occurs_on' => '2025-10-20', 'category' => 'festival', 'suggested_angles' => ['Festival sale', 'Light up your brand story']],
            ['name' => 'Christmas', 'occurs_on' => '2025-12-25', 'category' => 'festival', 'suggested_angles' => ['Year-end thank you', 'Holiday hours']],
            ['name' => 'New Year Sale window', 'occurs_on' => '2025-12-28', 'category' => 'sale', 'suggested_angles' => ['Year-end clearance', 'Goals for next year']],
        ],
        2026 => [
            ['name' => 'Republic Day', 'occurs_on' => '2026-01-26', 'category' => 'festival', 'suggested_angles' => ['Pride + local business', 'Citizen offer']],
            ['name' => "Women's Day", 'occurs_on' => '2026-03-08', 'category' => 'awareness', 'suggested_angles' => ['Celebrate women customers', 'Team spotlight']],
            ['name' => 'Holi', 'occurs_on' => '2026-03-03', 'category' => 'festival', 'suggested_angles' => ['Colorful creative', 'Joyful CTA']],
            ['name' => 'Labour Day', 'occurs_on' => '2026-05-01', 'category' => 'awareness', 'suggested_angles' => ['Thank your team', 'Hard work story']],
            ['name' => 'Independence Day', 'occurs_on' => '2026-08-15', 'category' => 'festival', 'suggested_angles' => ['Patriotic offer', 'Thank your local customers']],
            ['name' => 'Raksha Bandhan', 'occurs_on' => '2026-08-28', 'category' => 'festival', 'suggested_angles' => ['Family bond offer', 'Gift ideas']],
            ['name' => 'Ganesh Chaturthi', 'occurs_on' => '2026-09-14', 'category' => 'festival', 'suggested_angles' => ['New beginnings CTA', 'Community celebration']],
            ['name' => 'Diwali', 'occurs_on' => '2026-11-08', 'category' => 'festival', 'suggested_angles' => ['Festival sale', 'Light up your brand story']],
            ['name' => 'Christmas', 'occurs_on' => '2026-12-25', 'category' => 'festival', 'suggested_angles' => ['Year-end thank you', 'Holiday hours']],
            ['name' => 'New Year Sale window', 'occurs_on' => '2026-12-28', 'category' => 'sale', 'suggested_angles' => ['Year-end clearance', 'Goals for next year']],
        ],
        2027 => [
            ['name' => 'Republic Day', 'occurs_on' => '2027-01-26', 'category' => 'festival', 'suggested_angles' => ['Pride + local business', 'Citizen offer']],
            ['name' => "Women's Day", 'occurs_on' => '2027-03-08', 'category' => 'awareness', 'suggested_angles' => ['Celebrate women customers', 'Team spotlight']],
            ['name' => 'Holi', 'occurs_on' => '2027-03-22', 'category' => 'festival', 'suggested_angles' => ['Colorful creative', 'Joyful CTA']],
            ['name' => 'Independence Day', 'occurs_on' => '2027-08-15', 'category' => 'festival', 'suggested_angles' => ['Patriotic offer', 'Thank your local customers']],
            ['name' => 'Raksha Bandhan', 'occurs_on' => '2027-08-18', 'category' => 'festival', 'suggested_angles' => ['Family bond offer', 'Gift ideas']],
            ['name' => 'Ganesh Chaturthi', 'occurs_on' => '2027-09-03', 'category' => 'festival', 'suggested_angles' => ['New beginnings CTA', 'Community celebration']],
            ['name' => 'Diwali', 'occurs_on' => '2027-10-28', 'category' => 'festival', 'suggested_angles' => ['Festival sale', 'Light up your brand story']],
            ['name' => 'Christmas', 'occurs_on' => '2027-12-25', 'category' => 'festival', 'suggested_angles' => ['Year-end thank you', 'Holiday hours']],
        ],
    ],
];
