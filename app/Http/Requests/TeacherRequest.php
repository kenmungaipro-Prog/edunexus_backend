<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/// ============================================================
// TeacherRequest.php
// ============================================================

class TeacherRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $teacherId = $this->route('teacher')?->id;
        $isUpdate  = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $sometimes = $isUpdate ? 'sometimes|' : '';

        return [
            'name'           => $sometimes . 'required|string|max:255',
            'email'          => [
                $isUpdate ? 'sometimes' : 'required',
                'email',
                Rule::unique('users', 'email')->ignore(
                    \App\Models\Teacher::find($teacherId)?->user_id
                ),
            ],
            'phone'          => $sometimes . 'required|string|max:20',
            'department'     => $sometimes . 'required|string|max:100',
            'qualification'  => $sometimes . 'required|string|max:255',
            'experience_yrs' => $sometimes . 'required|integer|min:0|max:60',
            'join_date'      => $sometimes . 'required|date',
            'salary'         => 'nullable|numeric|min:0',
            'status'         => 'sometimes|in:active,inactive,on_leave',
            'subjects'       => 'sometimes|array',
            'subjects.*'     => 'exists:subjects,id',
        ];
    }
}

