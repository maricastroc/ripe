<?php

declare(strict_types=1);

namespace App\Inference\Drivers\Groq;

use App\Exceptions\Inference\DriverMisconfigured;
use App\Exceptions\Inference\InferenceException;
use App\Exceptions\Inference\InvalidModelOutput;
use App\Exceptions\Inference\ModelCallFailed;
use App\Exceptions\Inference\ModelRequestRejected;
use App\Exceptions\Inference\ModelUnavailable;
use App\Exceptions\Inference\RateLimited;
use App\Inference\Support\Json;
use App\Inference\Support\RetryHint;
use App\Inference\Transport\StreamResponse;
use App\Inference\Usage;
use JsonException;

final class GroqErrors
{
    public function fromHttp(StreamResponse $response, Usage $attempted): InferenceException
    {
        $error = self::decode($response->errorBody);
        $message = self::message($error, "Groq responded with HTTP {$response->status}.");
        $wait = RetryHint::retryAfter($response->header('retry-after')) ?? RetryHint::from($response);
        $invalid = $response->status === 400 && (Json::string($error, 'error.code') === 'json_validate_failed' || str_contains(strtolower($message), 'does not match the expected schema'));
        $processed = $invalid || $response->status === 422 || $response->status >= 500;

        $exception = match (true) {
            $response->status === 401 => new DriverMisconfigured("Groq rejected the API key: {$message}"),
            $response->status === 403 => new DriverMisconfigured("Groq refused access to the model: {$message}"),
            $response->status === 404 => new DriverMisconfigured("Groq does not offer this model: {$message}"),
            $response->status === 413 => new ModelRequestRejected("The request is too large for Groq: {$message}"),
            $response->status === 422 => new InvalidModelOutput("Groq could not produce a valid answer: {$message}"),
            $response->status === 429 => new RateLimited($message, $wait, self::isDaily($message, $response)),
            $response->status === 498 => new RateLimited("Groq is at capacity: {$message}", $wait),
            $invalid => new InvalidModelOutput("Groq could not produce a valid answer: {$message}"),
            $response->status >= 500 => new ModelUnavailable($message, $wait),
            default => new ModelRequestRejected($message),
        };

        return $exception->withCallDetails($processed ? $attempted : null, $response->header('x-request-id'));
    }

    /** @param array<array-key, mixed> $chunk */
    public function fromStream(array $chunk): InferenceException
    {
        return new ModelCallFailed(self::message($chunk, 'Groq stopped the stream with an error.'));
    }

    private static function isDaily(string $message, StreamResponse $response): bool
    {
        return preg_match('/per day|\((?:RPD|TPD)\)/i', $message) === 1 || $response->header('x-ratelimit-remaining-requests') === '0';
    }

    /** @param array<array-key, mixed> $body */
    private static function message(array $body, string $fallback): string
    {
        $message = trim(Json::string($body, 'error.message') ?? Json::string($body, 'message') ?? '');
        $public = trim(preg_replace(['/\s+in organization `[^`]*`/', '/\s*Need more tokens\?.*$/s'], '', $message) ?? $message);

        return $public === '' ? $fallback : mb_substr($public, 0, 500);
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
