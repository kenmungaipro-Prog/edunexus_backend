<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ============================================================
// ExamRequest.php
// ============================================================

class ExamRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $sometimes = $isUpdate ? 'sometimes|' : '';

        return [
            'title'          => $sometimes . 'required|string|max:255',
            'class_id'       => $sometimes . 'required|exists:class_rooms,id',
            'subject_id'     => $sometimes . 'required|exists:subjects,id',
            'exam_date'      => $sometimes . 'required|date',
            'start_time'     => $sometimes . 'required|date_format:H:i',
            'end_time'       => $sometimes . 'required|date_format:H:i|after:start_time',
            'total_marks'    => $sometimes . 'required|integer|min:1|max:1000',
            'passing_marks'  => $sometimes . 'required|integer|min:1|lt:total_marks',
            'room'           => 'nullable|string|max:100',
            'invigilator_id' => 'nullable|exists:teachers,id',
            'instructions'   => 'nullable|string|max:2000',
            'status'         => 'sometimes|in:scheduled,ongoing,completed,cancelled',
        ];
    }

    public function messages(): array
    {
        return [
            'end_time.after'           => 'End time must be after start time.',
            'passing_marks.lt'         => 'Passing marks must be less than total marks.',
            'invigilator_id.exists'    => 'Selected invigilator is not a valid teacher.',
        ];
    }
}
