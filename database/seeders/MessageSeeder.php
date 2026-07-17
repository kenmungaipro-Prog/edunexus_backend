<?php

namespace Database\Seeders;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

class MessageSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Assume ID 1 is the user you are logged in as in the frontend
        $me = User::find(1) ?? User::factory()->create(['id' => 1, 'name' => 'Admin User']);
        
        // 2. Get some other users to talk to
        $others = User::where('id', '!=', $me->id)->limit(5)->get();

        if ($others->isEmpty()) {
            $others = User::factory()->count(5)->create();
        }

        foreach ($others as $contact) {
            // Create a back-and-forth conversation (10 messages per person)
            for ($i = 0; $i < 10; $i++) {
                $isMine = fake()->boolean();
                
                Message::factory()->create([
                    'sender_id'   => $isMine ? $me->id : $contact->id,
                    'receiver_id' => $isMine ? $contact->id : $me->id,
                    'created_at'  => now()->subMinutes((10 - $i) * 20), // Spread them out
                ]);
            }
        }
    }
}