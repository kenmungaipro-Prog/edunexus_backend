<?php

namespace Tests\Unit;

use App\Models\TimetableSlot;
use PHPUnit\Framework\TestCase;

class TimetableSlotTest extends TestCase
{
    public function test_it_allows_break_slot_type_and_title_to_be_mass_assigned()
    {
        $slot = new TimetableSlot();

        $slot->fill([
            'class_id' => 1,
            'slot_type' => 'break',
            'title' => 'Recess',
            'day_of_week' => 1,
            'period_number' => 3,
            'start_time' => '10:00',
            'end_time' => '10:15',
        ]);

        $this->assertSame('break', $slot->slot_type);
        $this->assertSame('Recess', $slot->title);
    }
}
