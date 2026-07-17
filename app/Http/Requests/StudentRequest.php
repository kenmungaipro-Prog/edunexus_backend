<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ============================================================
// StudentRequest.php
// ============================================================

class StudentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $studentId = $this->route('student')?->id;
        $isUpdate  = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $sometimes = $isUpdate ? 'sometimes|' : '';

        $parentNameRule = $isUpdate
            ? 'nullable|string|max:255'
            : 'required_without:parent_id|nullable|string|max:255';

        $parentEmailRule = $isUpdate
            ? 'nullable|email'
            : 'required_without:parent_id|nullable|email';

        $parentPhoneRule = 'nullable|string|max:20';

        return [
            'first_name'    => $sometimes . 'required|string|max:100',
            'last_name'     => $sometimes . 'required|string|max:100',
            'date_of_birth' => $sometimes . 'required|date|before:today',
            'gender'        => $sometimes . 'required|in:male,female,other',
            'blood_group'   => 'nullable|in:A+,A-,B+,B-,O+,O-,AB+,AB-',
            'address'       => 'nullable|string|max:500',
            'class_id'      => $sometimes . 'required|exists:class_rooms,id',
            'session_id'    => 'nullable|exists:academic_sessions,id',
            'parent_id'     => 'nullable|exists:users,id',
            'secondary_parent_id' => 'nullable|exists:users,id',
            'emergency_contact_parent_id' => 'nullable|exists:users,id',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'status'        => 'sometimes|in:active,inactive,alumni',
            'admission_date'=> 'nullable|date',
            'religion'      => 'nullable|string|max:50',
            'category'      => 'nullable|in:General,OBC,SC,ST,EWS',
            // Parent creation fields (only on store)
            'parent_name'   => $parentNameRule,
            'parent_email'  => $parentEmailRule,
            'parent_phone'  => $parentPhoneRule,
            'secondary_parent_name'  => 'nullable|string|max:255',
            'secondary_parent_email' => 'nullable|email',
            'secondary_parent_phone' => $parentPhoneRule,
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('parent_name', 'required|string|max:255', function ($input) {
            if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
                return false;
            }

            if (! empty($input->parent_id)) {
                return false;
            }

            if (empty($input->parent_email)) {
                return true;
            }

            return ! User::where('email', $input->parent_email)
                         ->where('role', 'parent')
                         ->exists();
        });

        $validator->sometimes('secondary_parent_name', 'required|string|max:255', function ($input) {
            if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
                return false;
            }

            if (! empty($input->secondary_parent_id)) {
                return false;
            }

            if (empty($input->secondary_parent_email)) {
                return false;
            }

            return ! User::where('email', $input->secondary_parent_email)
                         ->where('role', 'parent')
                         ->exists();
        });

        $validator->after(function ($validator) {
            if ($this->filled('parent_email')) {
                $user = User::where('email', $this->parent_email)->first();

                if ($user && ! $user->isParent()) {
                    $validator->errors()->add('parent_email', 'The provided email belongs to a non-parent user.');
                }
            }

            if ($this->filled('secondary_parent_email')) {
                $user = User::where('email', $this->secondary_parent_email)->first();

                if ($user && ! $user->isParent()) {
                    $validator->errors()->add('secondary_parent_email', 'The provided email belongs to a non-parent user.');
                }
            }

            if ($this->filled('parent_id')) {
                $parent = User::find($this->parent_id);

                if ($parent && ! $parent->isParent()) {
                    $validator->errors()->add('parent_id', 'The selected parent account must belong to a parent user.');
                }
            }

            if ($this->filled('secondary_parent_id')) {
                $parent = User::find($this->secondary_parent_id);

                if ($parent && ! $parent->isParent()) {
                    $validator->errors()->add('secondary_parent_id', 'The selected secondary guardian account must belong to a parent user.');
                }

                if ($this->filled('parent_id') && $this->secondary_parent_id === $this->parent_id) {
                    $validator->errors()->add('secondary_parent_id', 'The secondary guardian must be different from the primary guardian.');
                }
            }

            if ($this->filled('emergency_contact_parent_id')) {
                $contact = User::find($this->emergency_contact_parent_id);

                if ($contact && ! $contact->isParent()) {
                    $validator->errors()->add('emergency_contact_parent_id', 'The emergency contact must be a parent user.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'class_id.required'     => 'Please select a class for the student.',
            'date_of_birth.before'  => 'Date of birth must be in the past.',
            'parent_email.required_without' => 'Parent email is required when no parent account exists.',
            'parent_name.required'  => 'Parent name is required when creating a new parent account.',
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name'    => 'first name',
            'last_name'     => 'last name',
            'date_of_birth' => 'date of birth',
            'class_id'      => 'class',
            'parent_id'     => 'parent',
        ];
    }
}
