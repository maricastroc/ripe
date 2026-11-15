<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Prompts\Prompt;
use App\Enums\RunKind;
use App\Inference\Message;
use App\Inference\ModelRequest;
use Illuminate\Support\Facades\Config;

final class AgentRequests
{
    public function build(RunKind $kind, Prompt $prompt, ?string $safetyIdentifier = null): ModelRequest
    {
        return new ModelRequest(
            instructions: $prompt->instructions,
            messages: [Message::user($prompt->input)],
            maxOutputTokens: Config::integer("inference.output_tokens.{$kind->value}"),
            schema: $prompt->schema?->format(),
            temperature: Config::float("inference.temperature.{$kind->value}"),
            safetyIdentifier: $safetyIdentifier,
        );
    }
}
