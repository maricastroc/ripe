<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class DeferTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'returnAt' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    public function returnAt(): ?CarbonImmutable
    {
        return $this->filled('returnAt') ? CarbonImmutable::parse($this->string('returnAt')->toString()) : null;
    }
}
