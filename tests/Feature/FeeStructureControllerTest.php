<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeStructureControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_fee_structure_items(): void
    {
        $school = School::create([
            'name' => 'Test School',
            'address' => '123 Test Lane',
            'phone' => '254700000000',
            'email' => 'school@test.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025',
            'is_current' => true,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classRoom = ClassRoom::create([
            'school_id' => $school->id,
            'session_id' => $session->id,
            'name' => 'Grade 1-A',
            'grade' => 1,
            'section' => 'A',
            'capacity' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = FeeCategory::create([
            'school_id' => $school->id,
            'name' => 'Tuition',
            'code' => 'TUITION',
            'description' => 'Tuition fee',
            'default_amount' => 1000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::create([
            'school_id' => $school->id,
            'name' => 'Admin User',
            'email' => 'admin@test.example',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $structure = FeeStructure::create([
            'school_id' => $school->id,
            'session_id' => $session->id,
            'class_id' => $classRoom->id,
            'name' => 'Term 1',
            'billing_period' => 'term',
            'currency' => 'KES',
            'status' => 'draft',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = FeeStructureItem::create([
            'fee_structure_id' => $structure->id,
            'fee_category_id' => $category->id,
            'description' => 'Original tuition fee',
            'amount' => 1000,
            'is_mandatory' => true,
            'is_recurring' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->putJson('/api/v1/finance/fee-structures/' . $structure->id, [
                'name' => 'Updated Term 1',
                'status' => 'active',
                'items' => [[
                    'fee_category_id' => $category->id,
                    'description' => 'Updated tuition fee',
                    'amount' => 1250,
                    'is_mandatory' => true,
                    'is_recurring' => true,
                ]],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Updated Term 1')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('fee_structure_items', [
            'fee_structure_id' => $structure->id,
            'description' => 'Updated tuition fee',
            'amount' => '1250.00',
        ]);

        $this->assertDatabaseMissing('fee_structure_items', [
            'id' => $item->id,
            'description' => 'Original tuition fee',
        ]);
    }
}
