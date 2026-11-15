<?php

declare(strict_types=1);

namespace App\Inference;

use App\Enums\Inference\ReasoningEffort;
use App\Inference\Contracts\ModelDriver;
use App\Inference\Drivers\Fake\FakeDriver;
use App\Inference\Drivers\Ollama\OllamaDriver;
use App\Inference\Drivers\OpenAI\OpenAIDriver;
use App\Inference\Pricing\CostCalculator;
use App\Inference\Transport\StreamTransport;
use App\Inference\Transport\Timeouts;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Manager;
use UnexpectedValueException;

final class InferenceManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return Config::string('inference.driver');
    }

    public function current(?string $name = null): ModelDriver
    {
        $driver = $this->driver($name);

        if (! $driver instanceof ModelDriver) {
            throw new UnexpectedValueException('Inference drivers must implement ModelDriver.');
        }

        return $driver;
    }

    public function useFake(FakeDriver $fake): FakeDriver
    {
        $this->extend('fake', fn (): FakeDriver => $fake);
        $this->forgetDrivers();
        Config::set('inference.driver', 'fake');

        return $fake;
    }

    protected function createOpenaiDriver(): OpenAIDriver
    {
        $config = 'inference.drivers.openai';
        $allowance = [];

        foreach (Config::array("{$config}.reasoning_allowance") as $effort => $tokens) {
            if (is_string($effort) && is_int($tokens)) {
                $allowance[$effort] = $tokens;
            }
        }

        return new OpenAIDriver(
            transport: $this->container->make(StreamTransport::class),
            http: $this->container->make(HttpFactory::class),
            costs: new CostCalculator,
            timeouts: Timeouts::fromConfig(Config::array("{$config}.timeouts")),
            apiKey: $this->optional("{$config}.api_key"),
            modelId: $this->optional("{$config}.model"),
            baseUrl: Config::string("{$config}.base_url"),
            defaultEffort: ReasoningEffort::from(Config::string("{$config}.reasoning_effort")),
            reasoningAllowance: $allowance,
            catalogue: Config::array("{$config}.models"),
            serviceTier: $this->optional("{$config}.service_tier"),
            organization: $this->optional("{$config}.organization"),
            project: $this->optional("{$config}.project"),
        );
    }

    protected function createOllamaDriver(): OllamaDriver
    {
        $config = 'inference.drivers.ollama';

        return new OllamaDriver(
            transport: $this->container->make(StreamTransport::class),
            http: $this->container->make(HttpFactory::class),
            timeouts: Timeouts::fromConfig(Config::array("{$config}.timeouts")),
            modelId: Config::string("{$config}.model"),
            baseUrl: Config::string("{$config}.base_url"),
            keepAlive: Config::string("{$config}.keep_alive"),
            contextWindow: Config::integer("{$config}.context_window"),
        );
    }

    protected function createFakeDriver(): FakeDriver
    {
        return FakeDriver::replying();
    }

    private function optional(string $key): ?string
    {
        $value = Config::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
