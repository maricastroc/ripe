<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Agent\Limits;
use App\Http\Middleware\ThroughGateway;
use App\Http\Visitors\Turnstile;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PlantTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:3', 'max:'.Limits::MAX_PROMPT_CHARACTERS],
            'defer' => ['sometimes', 'boolean'],
            'returnAt' => ['sometimes', 'nullable', 'date', 'after:now'],
            'turnstile' => app(Turnstile::class)->enabled() ? ['required', 'string', 'max:2048'] : ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $turnstile = app(Turnstile::class);

            if (! $turnstile->enabled() || $validator->errors()->isNotEmpty()) {
                return;
            }

            $address = $this->attributes->get(ThroughGateway::CLIENT_ATTRIBUTE);

            if (! $turnstile->passes($this->string('turnstile')->toString(), is_string($address) ? $address : $this->ip())) {
                $validator->errors()->add('turnstile', 'The greenhouse could not confirm you are a person. Try again.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Write what you keep putting off.',
            'prompt.min' => 'Write a little more about the task.',
            'prompt.max' => 'Keep the task under '.Limits::MAX_PROMPT_CHARACTERS.' characters.',
            'turnstile.required' => 'The greenhouse could not confirm you are a person. Try again.',
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
