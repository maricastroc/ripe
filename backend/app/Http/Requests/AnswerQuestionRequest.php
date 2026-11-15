<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Agent\Limits;
use Illuminate\Foundation\Http\FormRequest;

final class AnswerQuestionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'answer' => ['required', 'string', 'max:'.Limits::MAX_ANSWER_CHARACTERS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['answer.required' => 'Write an answer first.'];
    }
}
