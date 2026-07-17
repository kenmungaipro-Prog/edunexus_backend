<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{Vehicle, Driver, TransportRoute, Student, School, AcademicSession, ClassRoom};
use Illuminate\Support\Facades\DB;

class TransportSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Setup Prerequisites (School, Session, and Class)
        $school = School::first() ?? School::updateOrCreate(
            ['email' => 'info@edunexus.edu'],
            ['name' => 'EduNexus International', 'phone' => '0700000000']
        );

        $session = AcademicSession::where('school_id', $school->id)->first()
            ?? AcademicSession::updateOrCreate(
                ['school_id' => $school->id, 'name' => '2024-2025'],
                [
                    'is_current' => true,
                    'start_date' => '2024-01-01',
                    'end_date'   => '2024-12-31'
                ]
            );

        $class = ClassRoom::where('school_id', $school->id)->first()
            ?? ClassRoom::updateOrCreate(
                ['school_id' => $school->id, 'name' => 'Grade 10-A'],
                ['session_id' => $session->id, 'grade' => '10', 'section' => 'A']
            );

        // 2. Define multiple route scenarios
        $routeData = [
            [
                'name' => 'Eastern Bypass Route',
                'fee'  => 3200,
                'driver'  => ['name' => 'Frank Castle', 'license' => 'LIC-770088', 'phone' => '0711998877'],
                'vehicle' => ['reg' => 'BUS-EDN-01', 'make' => 'Toyota', 'model' => 'Coaster', 'speed' => 45]
            ],
            [
                'name' => 'Northern Suburbs',
                'fee'  => 4500,
                'driver'  => ['name' => 'Sarah Connor', 'license' => 'LIC-550022', 'phone' => '0722333444'],
                'vehicle' => ['reg' => 'BUS-EDN-02', 'make' => 'Isuzu', 'model' => 'FRR', 'speed' => 0] // Stationary
            ],
            [
                'name' => 'Western Express',
                'fee'  => 2800,
                'driver'  => ['name' => 'Joel Miller', 'license' => 'LIC-110044', 'phone' => '0733555666'],
                'vehicle' => ['reg' => 'BUS-EDN-03', 'make' => 'Mercedes', 'model' => 'Sprinter', 'speed' => 52]
            ],
            [
                'name' => 'Southern Link',
                'fee'  => 3500,
                'driver'  => ['name' => 'Ellen Ripley', 'license' => 'LIC-990033', 'phone' => '0744111222'],
                'vehicle' => ['reg' => 'BUS-EDN-04', 'make' => 'Mitsubishi', 'model' => 'Rosa', 'speed' => 15]
            ],
        ];

        foreach ($routeData as $data) {
            // Create or Update Driver
            $driver = Driver::updateOrCreate(
                ['license_no' => $data['driver']['license']],
                [
                    'name' => $data['driver']['name'], 
                    'phone' => $data['driver']['phone'], 
                    'license_expiry' => '2029-01-01'
                ]
            );

            // Create or Update Vehicle
            $vehicle = Vehicle::updateOrCreate(
                ['registration_number' => $data['vehicle']['reg']],
                [
                    'make' => $data['vehicle']['make'], 
                    'model' => $data['vehicle']['model'], 
                    'capacity' => 25, 
                    'last_lat' => -1.28 + (rand(-100, 100) / 10000), 
                    'last_lng' => 36.81 + (rand(-100, 100) / 10000), 
                    'last_speed' => $data['vehicle']['speed'], 
                    'location_updated_at' => now()
                ]
            );

            // Create the Transport Route
            $route = TransportRoute::updateOrCreate(
                ['name' => $data['name'], 'school_id' => $school->id],
                [
                    'vehicle_id'  => $vehicle->id,
                    'driver_id'   => $driver->id,
                    'monthly_fee' => $data['fee'],
                    'stops'       => [
                        ['name' => 'Main Stage', 'pickup_time' => '06:30', 'drop_time' => '17:30'],
                        ['name' => 'School Gate', 'pickup_time' => '07:30', 'drop_time' => '16:30']
                    ]
                ]
            );

            // Create and Assign 5 students per route
            $students = Student::factory()->count(5)->create([
                'school_id'  => $school->id,
                'session_id' => $session->id,
                'class_id'   => $class->id
            ]);

            foreach ($students as $student) {
                DB::table('transport_assignments')->updateOrInsert(
                    ['student_id' => $student->id, 'transport_route_id' => $route->id],
                    ['stop' => 'Main Stage', 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }
}