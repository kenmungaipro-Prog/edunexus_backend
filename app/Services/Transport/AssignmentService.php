<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportRoute;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function assignStudentToRoute(TransportRoute $route, int $studentId, string $stop): void
    {
        $exists = DB::table('transport_assignments')
            ->where('student_id', $studentId)
            ->where('transport_route_id', $route->id)
            ->exists();

        if ($exists) {
            throw new \RuntimeException('This student is already allocated to this transport route.');
        }

        $route->students()->attach($studentId, ['stop' => $stop]);
    }

    public function studentAssignedToRoute(TransportRoute $route, int $studentId): bool
    {
        return DB::table('transport_assignments')
            ->where('transport_route_id', $route->id)
            ->where('student_id', $studentId)
            ->exists();
    }
}
