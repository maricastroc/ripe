<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class KeepDraftRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'baseVersionId' => ['required', 'uuid'],
            'draftRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
