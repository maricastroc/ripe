<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Agent\Limits;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class PlantTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:3', 'max:'.Limits::MAX_PROMPT_CHARACTERS],
            'defer' => ['sometimes', 'boolean'],
            'returnAt' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Write what you keep putting off.',
            'prompt.min' => 'Write a little more about the task.',
            'prompt.max' => 'Keep the task under '.Limits::MAX_PROMPT_CHARACTERS.' characters.',
        ];
    }

    public function prompt(): string
    {
        return $this->string('prompt')->toString();
    }

    public function shouldDefer(): bool
    {
        return $this->boolean('defer', true);
    }

    public function returnAt(): ?CarbonImmutable
    {
        return $this->filled('returnAt') ? CarbonImmutable::parse($this->string('returnAt')->toString()) : null;
    }
}
