<?php

namespace Database\Seeders;

use App\Models\FestivalEvent;
use Illuminate\Database\Seeder;

class FestivalCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->year;

        $events = [
            ['name' => 'Independence Day', 'occurs_on' => "{$year}-08-15", 'category' => 'festival', 'suggested_angles' => ['Patriotic offer', 'Thank your local customers']],
            ['name' => 'Raksha Bandhan', 'occurs_on' => "{$year}-08-09", 'category' => 'festival', 'suggested_angles' => ['Family bond offer', 'Gift ideas']],
            ['name' => 'Ganesh Chaturthi', 'occurs_on' => "{$year}-08-27", 'category' => 'festival', 'suggested_angles' => ['New beginnings CTA', 'Community celebration']],
            ['name' => 'Diwali', 'occurs_on' => "{$year}-10-20", 'category' => 'festival', 'suggested_angles' => ['Festival sale', 'Light up your brand story']],
            ['name' => 'Christmas', 'occurs_on' => "{$year}-12-25", 'category' => 'festival', 'suggested_angles' => ['Year-end thank you', 'Holiday hours']],
            ['name' => 'Republic Day', 'occurs_on' => "{$year}-01-26", 'category' => 'festival', 'suggested_angles' => ['Pride + local business', 'Citizen offer']],
            ['name' => 'Holi', 'occurs_on' => "{$year}-03-14", 'category' => 'festival', 'suggested_angles' => ['Colorful creative', 'Joyful CTA']],
            ['name' => 'New Year Sale window', 'occurs_on' => "{$year}-12-28", 'category' => 'sale', 'suggested_angles' => ['Year-end clearance', 'Goals for next year']],
            ['name' => 'Women’s Day', 'occurs_on' => "{$year}-03-08", 'category' => 'awareness', 'suggested_angles' => ['Celebrate women customers', 'Team spotlight']],
            ['name' => 'Labour Day', 'occurs_on' => "{$year}-05-01", 'category' => 'awareness', 'suggested_angles' => ['Thank your team', 'Hard work story']],
        ];

        foreach ($events as $event) {
            FestivalEvent::query()->updateOrCreate(
                ['name' => $event['name'], 'occurs_on' => $event['occurs_on']],
                [
                    'region' => 'IN',
                    'category' => $event['category'],
                    'suggested_angles' => $event['suggested_angles'],
                ]
            );
        }
    }
}
