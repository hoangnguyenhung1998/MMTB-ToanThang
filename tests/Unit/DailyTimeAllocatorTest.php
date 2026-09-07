<?php

namespace Tests\Unit;

use App\Services\Reconciliation\DailyTimeAllocator;
use PHPUnit\Framework\TestCase;

class DailyTimeAllocatorTest extends TestCase
{
    public function test_entry_grace_and_exit_rounding_at_all_boundaries(): void
    {
        $service = new DailyTimeAllocator();
        foreach (['06:00'=>'06:00', '06:04'=>'06:00', '06:10'=>'06:00', '06:11'=>'06:30', '06:14'=>'06:30',
            '06:30'=>'06:30', '06:40'=>'06:30', '06:41'=>'07:00', '06:59'=>'07:00'] as $raw => $expected) {
            $this->assertSame($expected, $service->format($service->round($service->minute($raw), true)));
        }
        foreach (['17:00'=>'17:00', '17:29'=>'17:00', '17:30'=>'17:30', '17:44'=>'17:30', '17:59'=>'17:30'] as $raw => $expected) {
            $this->assertSame($expected, $service->format($service->round($service->minute($raw), false)));
        }
    }

    public function test_sop_keeps_lunch_evening_separate_and_caps_regular(): void
    {
        $result = (new DailyTimeAllocator())->allocate([
            ['kind'=>'regular_morning','start'=>'06:00','end'=>'10:00'],
            ['kind'=>'overtime_lunch','start'=>'11:00','end'=>'14:00'],
            ['kind'=>'regular_afternoon','start'=>'14:30','end'=>'18:30'],
            ['kind'=>'overtime_evening','start'=>'19:00','end'=>'22:00'],
        ]);
        $this->assertSame(420, $result['regular_minutes']);
        $this->assertSame(180, $result['lunch_minutes']);
        $this->assertSame(60, $result['ot_afternoon_minutes']);
        $this->assertSame(180, $result['ot_evening_minutes']);
        $this->assertSame('17:30', $result['regular_afternoon_end']);
    }

    public function test_short_day_does_not_absorb_lunch_or_evening(): void
    {
        $result = (new DailyTimeAllocator())->allocate([
            ['kind'=>'regular_morning','start'=>'06:14','end'=>'10:44'],
            ['kind'=>'overtime_lunch','start'=>'11:00','end'=>'12:00'],
            ['kind'=>'overtime_evening','start'=>'19:00','end'=>'03:30'],
        ]);
        $this->assertSame(240, $result['regular_minutes']);
        $this->assertSame(60, $result['lunch_minutes']);
        $this->assertSame(510, $result['ot_evening_minutes']);
        $this->assertSame('03:30', $result['confirmed_check_out']);
    }
}
