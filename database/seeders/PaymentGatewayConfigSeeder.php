<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\School;

class PaymentGatewayConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Create a Co-op Bank 400222 gateway config if it doesn't exist
        if (DB::table('payment_gateway_configs')->where('gateway_name', 'coop_400222')->exists()) {
            return;
        }

        $school = School::first();
        $schoolId = $school ? $school->id : 1;

        DB::table('payment_gateway_configs')->insert([
            'school_id' => $schoolId,
            'gateway_name' => 'coop_400222',
            'environment' => 'production',
            'shortcode' => '400222',
            'passkey' => null,
            'consumer_key' => null,
            'consumer_secret' => null,
            'initiator_name' => null,
            'initiator_password' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
