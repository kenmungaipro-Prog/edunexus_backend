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
        $school = School::findOrFail(currentSchoolId());
        $settings = $this->settingsWithDefaults($school);

        return response()->json([
            'success' => true,
            'data' => $settings,
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
            'preferences.currency'     => 'sometimes|string|size:3',
            'preferences.items_per_page' => 'sometimes|integer|in:10,20,50,100',
            'academic'                 => 'sometimes|array',
            'academic.attendance_threshold' => 'sometimes|integer|between:50,90',
            'academic.passing_percentage'   => 'sometimes|integer|between:20,60',
        ]);

        $school = School::findOrFail(currentSchoolId());
        $settings = array_replace_recursive(
            $this->settingsWithDefaults($school),
            $request->only(['notifications', 'preferences', 'academic']),
        );
        $school->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'message' => 'Settings saved.',
            'data' => $settings,
        ]);
    }

    private function settingsWithDefaults(School $school): array
    {
        return array_replace_recursive([
            'notifications' => [
                'email_alerts' => true,
                'sms_alerts' => false,
                'fee_reminders' => true,
                'attendance_alerts' => true,
                'exam_reminders' => true,
            ],
            'preferences' => [
                'theme' => 'dark',
                'language' => 'en',
                'date_format' => 'd M Y',
                'currency' => 'KES',
                'timezone' => 'Africa/Nairobi',
                'items_per_page' => 20,
            ],
            'academic' => [
                'current_session' => currentSession(),
                'working_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                'periods_per_day' => 8,
                'period_duration' => 45,
                'attendance_threshold' => 75,
                'passing_percentage' => 35,
            ],
        ], $school->settings ?? []);
    }

    public function school(): JsonResponse
    {
        $school = School::findOrFail(currentSchoolId());

        return response()->json(['success' => true, 'data' => $school]);
    }

    public function branding(): JsonResponse
    {
        $school = School::findOrFail(currentSchoolId());

        return response()->json([
            'success' => true,
            'data' => $school->only(['name', 'address', 'phone', 'email', 'logo']),
        ]);
    }

    public function updateSchool(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'             => 'sometimes|required|string|max:255',
            'email'            => 'sometimes|required|email|max:255',
            'phone'            => 'sometimes|nullable|string|max:20',
            'address'          => 'sometimes|nullable|string|max:500',
            'principal'        => 'sometimes|nullable|string|max:255',
            'website'          => 'sometimes|nullable|url',
            'board'            => 'sometimes|nullable|string|max:50',
            'affiliation_no'   => 'sometimes|nullable|string|max:50',
            'established_year' => 'sometimes|required|integer|between:1800,' . now()->year,
            'admission_prefix' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'logo'             => 'sometimes|nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        $school = School::findOrFail(currentSchoolId());

        if ($request->hasFile('logo')) {
            if ($school->logo) {
                Storage::disk('public')->delete($school->logo);
            }
            $validated['logo'] = $request->file('logo')->store('schools/logos', 'public');
        } else {
            unset($validated['logo']);
        }

        if (isset($validated['admission_prefix'])) {
            $validated['admission_prefix'] = strtoupper(trim($validated['admission_prefix']));
        }

        $school->fill($validated)->save();

        return response()->json(['success' => true, 'data' => $school]);
    }
}
