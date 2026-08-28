<?php

namespace Tests\Unit;

use App\Support\ConfiguredSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ConfiguredScheduleTest extends TestCase
{
    public function test_is_disabled_recognizes_off_values(): void
    {
        $this->assertTrue(ConfiguredSchedule::isDisabled('off'));
        $this->assertTrue(ConfiguredSchedule::isDisabled('disabled'));
        $this->assertFalse(ConfiguredSchedule::isDisabled('hourly'));
    }

    public function test_apply_cron_expression(): void
    {
        $schedule = app(Schedule::class);
        $event = $schedule->command('inspire');
        $applied = ConfiguredSchedule::apply($event, '*/10 * * * *');

        $this->assertInstanceOf(Event::class, $applied);
        $this->assertSame('*/10 * * * *', $applied->expression);
    }

    public function test_apply_returns_null_when_disabled(): void
    {
        $schedule = app(Schedule::class);
        $event = $schedule->command('inspire');

        $this->assertNull(ConfiguredSchedule::apply($event, 'disabled'));
    }
}
