<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Pace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAgentRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'pace' => ['sometimes', 'nullable', Rule::enum(Pace::class)],
            'paused' => ['sometimes', 'nullable', 'boolean'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'baseUrl' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['baseUrl.prohibited' => "The model's address is set by whoever runs Ripe, not from the interface."];
    }

    public function pace(): ?Pace
    {
        return $this->filled('pace') ? $this->enum('pace', Pace::class) : null;
    }

    public function paused(): ?bool
    {
        return $this->has('paused') && $this->input('paused') !== null ? $this->boolean('paused') : null;
    }

    public function model(): ?string
    {
        return $this->filled('model') ? $this->string('model')->toString() : null;
    }
}
