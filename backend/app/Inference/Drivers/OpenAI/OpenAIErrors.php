<?php

declare(strict_types=1);

namespace App\Inference\Drivers\OpenAI;

use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRequestRejected;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\ProviderQuotaExhausted;
use App\Exceptions\Inference\RateLimited;
use App\Inference\Support\Json;
use App\Inference\Transport\StreamResponse;

final class OpenAIErrors
{
    private const array ACCOUNT_LIMITS = [
        'credit_balance_exhausted',
        'organization_spend_limit_exceeded',
        'project_spend_limit_exceeded',
        'organization_usage_limit_exceeded',
        'insufficient_quota',
    ];

    private const array CONFIGURATION_PARAMETERS = [
        'model', 'service_tier', 'temperature', 'top_p', 'reasoning', 'reasoning.effort',
        'max_output_tokens', 'store', 'safety_identifier', 'text.format',
    ];

    private const array REJECTED_PROMPTS = [
        'invalid_prompt', 'bio_policy', 'misalignment_policy_violation', 'data_residency_mismatch',
    ];

    public function fromHttp(StreamResponse $response): InferenceException
    {
        $body = Json::decodeObject($response->errorBody) ?? [];
        $code = Json::string($body, 'error.code');
        $type = Json::string($body, 'error.type');
        $param = Json::string($body, 'error.param');
        $message = Json::string($body, 'error.message') ?? "OpenAI responded with HTTP {$response->status}.";
        $status = $response->status;

        return match (true) {
            $status === 401, $status === 403, $status === 404 => new DriverMisconfigured($message),
            $status === 429 && $this->isAccountLimit($code, $type) => new ProviderQuotaExhausted($message),
            $status === 429 => new RateLimited($message, RetryHint::from($response)),
            $status === 400 && $this->isConfiguration($param, $code) => new DriverMisconfigured($message),
            $status >= 400 && $status < 500 => new ModelRequestRejected($message),
            default => new ModelUnavailable($message, RetryHint::from($response)),
        };
    }

    /** @param array<array-key, mixed> $error */
    public function fromStreamError(array $error): InferenceException
    {
        $code = Json::string($error, 'code') ?? Json::string($error, 'error.code');
        $message = Json::string($error, 'message') ?? Json::string($error, 'error.message') ?? 'OpenAI reported an error while streaming.';

        return $this->fromCode($code, $message);
    }

    /** @param array<array-key, mixed> $response */
    public function fromFailedResponse(array $response): InferenceException
    {
        return $this->fromCode(
            Json::string($response, 'error.code'),
            Json::string($response, 'error.message') ?? 'OpenAI could not generate the response.',
        );
    }

    private function fromCode(?string $code, string $message): InferenceException
    {
        return match (true) {
            in_array($code, ['server_error', 'server_is_overloaded'], true) => new ModelUnavailable($message),
            in_array($code, ['rate_limit_exceeded', 'rate_limit_error', 'slow_down'], true) => new RateLimited($message),
            $this->isAccountLimit($code, null) => new ProviderQuotaExhausted($message),
            in_array($code, self::REJECTED_PROMPTS, true) => new ModelRequestRejected($message),
            default => new ModelCallFailed($message),
        };
    }

    private function isAccountLimit(?string $code, ?string $type): bool
    {
        return in_array($code, self::ACCOUNT_LIMITS, true) || $type === 'insufficient_quota';
    }

    private function isConfiguration(?string $param, ?string $code): bool
    {
        if (in_array($code, ['unsupported_parameter', 'unsupported_value', 'model_not_found'], true)) {
            return true;
        }

        if ($param === null) {
            return false;
        }

        foreach (self::CONFIGURATION_PARAMETERS as $parameter) {
            if ($param === $parameter || str_starts_with($param, $parameter.'.')) {
                return true;
            }
        }

        return false;
    }
}
