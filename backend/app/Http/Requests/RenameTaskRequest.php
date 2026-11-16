<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Agent\Limits;
use Illuminate\Foundation\Http\FormRequest;

final class RenameTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Limits::MAX_TITLE_CHARACTERS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => __("The title can't be empty."),
            'title.max' => __('Keep the title under :max characters.'),
        ];
    }
}
