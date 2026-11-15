<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestoreVersionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'baseVersionId' => ['required', 'uuid'],
            'versionId' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function versionId(): ?string
    {
        return $this->filled('versionId') ? $this->string('versionId')->toString() : null;
    }
}
