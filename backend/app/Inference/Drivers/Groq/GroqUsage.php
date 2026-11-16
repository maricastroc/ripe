<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Groq;

use App\Inference\Support\Json;
use App\Inference\Usage;

final class GroqUsage
{
    /** @param array<array-key, mixed> $body */
    public static function from(array $body): ?Usage
    {
        foreach (['usage', 'x_groq.usage'] as $path) {
            if (is_array(Json::get($body, $path)) && Json::get($body, "{$path}.prompt_tokens") !== null) {
                return new Usage(
                    inputTokens: Json::integer($body, "{$path}.prompt_tokens"),
                    outputTokens: Json::integer($body, "{$path}.completion_tokens"),
                    cachedInputTokens: Json::integer($body, "{$path}.prompt_tokens_details.cached_tokens"),
                    reasoningTokens: Json::integer($body, "{$path}.completion_tokens_details.reasoning_tokens"),
                );
            }
        }

        return null;
    }
}
