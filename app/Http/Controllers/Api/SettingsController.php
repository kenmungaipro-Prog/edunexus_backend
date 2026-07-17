<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{School, TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// ============================================================
// SettingsController
// ============================================================

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'notifications' => [
                    'email_alerts'      => true,
                    'sms_alerts'        => false,
                    'fee_reminders'     => true,
                    'attendance_alerts' => true,
                    'exam_reminders'    => true,
                ],
                'preferences' => [
                    'theme'           => 'dark',
                    'language'        => 'en',
                    'date_format'     => 'd M Y',
                    'currency'        => 'INR',
                    'timezone'        => 'Asia/Kolkata',
                    'items_per_page'  => 20,
                ],
                'academic' => [
                    'current_session'   => currentSession(),
                    'working_days'      => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                    'periods_per_day'   => 8,
                    'period_duration'   => 45,
                    'attendance_threshold' => 75,
                    'passing_percentage'   => 35,
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'notifications'            => 'sometimes|array',
            'notifications.*'          => 'boolean',
            'preferences'              => 'sometimes|array',
            'preferences.theme'        => 'sometimes|in:dark,light',
            'preferences.language'     => 'sometimes|string|max:5',
            'preferences.date_format'  => 'sometimes|string|max:20',
            'preferences.timezone'     => 'sometimes|string|max:50',
            'preferences.items_per_page' => 'sometimes|integer|in:10,20,50,100',
            'academic'                 => 'sometimes|array',
            'academic.attendance_threshold' => 'sometimes|integer|between:50,90',
            'academic.passing_percentage'   => 'sometimes|integer|between:20,60',
        ]);

        // In production, store in a school_settings table or JSON column
        // Here we return the merged result
        return response()->json([
            'success' => true,
            'message' => 'Settings saved.',
            'data'    => $request->all(),
        ]);
    }

    public function school(): JsonResponse
    {
        $school = School::findOrFail(currentSchoolId());

        return response()->json(['success' => true, 'data' => $school]);
    }

    public function updateSchool(Request $request): JsonResponse
        {
            $request->validate([
                'name'             => 'sometimes|required|string|max:255',
                'email'            => 'sometimes|required|email|max:255',
                'phone'            => 'sometimes|required|string|max:20',
                'address'          => 'sometimes|required|string|max:500',
                'principal'        => 'sometimes|required|string|max:255',
                'website'          => 'sometimes|nullable|url',
                'board'            => 'sometimes|required|string|max:50',
                'affiliation_no'   => 'sometimes|nullable|string|max:50',
                'established_year' => 'sometimes|required|integer|between:1800,' . now()->year,
                'logo'             => 'sometimes|nullable|image|mimes:png,jpg,jpeg|max:2048',
            ]);

            $school = School::findOrFail(currentSchoolId());

            if ($request->hasFile('logo')) {
                if ($school->logo) {
                    Storage::disk('public')->delete($school->logo);
                }
                $path = $request->file('logo')->store('schools/logos', 'public');
                $school->logo = $path;
            }

            $school->update($request->except('logo'));

            return response()->json(['success' => true, 'data' => $school]);
        }
}
