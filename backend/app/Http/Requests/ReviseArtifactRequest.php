<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Agent\Limits;
use Illuminate\Foundation\Http\FormRequest;

final class ReviseArtifactRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:'.Limits::MAX_INSTRUCTION_CHARACTERS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['instruction.required' => 'Say what you want to change.'];
    }
}
