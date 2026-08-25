<?php

namespace Tests\Unit;

use App\Services\Reconciliation\ReconciliationTimeAllocator;
use PHPUnit\Framework\TestCase;

class ReconciliationTimeAllocatorTest extends TestCase
{
    public function test_it_keeps_seven_regular_hours_and_splits_overtime_by_period(): void
    {
        $result = (new ReconciliationTimeAllocator())->allocate([
            ['start_time' => '06:30:00', 'end_time' => '10:30:00'],
            ['start_time' => '11:00:00', 'end_time' => '13:30:00'],
            ['start_time' => '14:00:00', 'end_time' => '17:00:00'],
            ['start_time' => '17:00:00', 'end_time' => '18:00:00'],
            ['start_time' => '18:30:00', 'end_time' => '21:00:00'],
        ]);

        $this->assertSame(420, $result['regular_minutes']);
        $this->assertSame(150, $result['lunch_minutes']);
        $this->assertSame(60, $result['ot_afternoon_minutes']);
        $this->assertSame(150, $result['ot_evening_minutes']);
        $this->assertSame('06:30', $result['regular_morning_start']);
        $this->assertSame('17:00', $result['regular_afternoon_end']);
    }

    public function test_day_below_seven_hours_is_all_regular(): void
    {
        $result = (new ReconciliationTimeAllocator())->allocate([
            ['start_time' => '06:30:00', 'end_time' => '10:30:00'],
            ['start_time' => '14:00:00', 'end_time' => '16:00:00'],
        ]);

        $this->assertSame(360, $result['regular_minutes']);
        $this->assertSame(0, $result['lunch_minutes']);
        $this->assertSame(0, $result['ot_afternoon_minutes']);
        $this->assertSame(0, $result['ot_evening_minutes']);
    }

    public function test_contiguous_overtime_tops_up_regular_to_seven_hours(): void
    {
        $result = (new ReconciliationTimeAllocator())->allocate([
            ['start_time' => '06:30:00', 'end_time' => '10:00:00'],
            ['start_time' => '14:00:00', 'end_time' => '17:00:00'],
            ['start_time' => '17:00:00', 'end_time' => '18:00:00'],
        ]);

        $this->assertSame(420, $result['regular_minutes']);
        $this->assertSame('17:30', $result['regular_afternoon_end']);
        $this->assertSame('17:30', $result['overtime_afternoon_start']);
        $this->assertSame('18:00', $result['overtime_afternoon_end']);
        $this->assertSame(30, $result['ot_afternoon_minutes']);
    }
}
