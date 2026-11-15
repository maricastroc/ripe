<?php

declare(strict_types=1);

final class Fixtures
{
    private int $sequence = 0;

    public function __construct(private readonly string $directory) {}

    public function write(string $name, string $content): void
    {
        file_put_contents("{$this->directory}/{$name}", $content);
        echo "{$name} ".strlen($content)." bytes\n";
    }

    public function restart(): void
    {
        $this->sequence = 0;
    }

    /** @param array<string, mixed> $data */
    public function event(string $type, array $data): string
    {
        $payload = array_replace(['type' => $type, 'sequence_number' => $this->sequence++], $data);

        return "event: {$type}\ndata: ".self::json($payload)."\n\n";
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function response(string $id, string $status, array $changes = []): array
    {
        return array_replace([
            'id' => $id, 'object' => 'response', 'created_at' => 1795000000, 'status' => $status,
            'model' => 'gpt-test-2026-06-01', 'error' => null, 'incomplete_details' => null,
            'output' => [], 'usage' => null, 'store' => false,
        ], $changes);
    }

    public function opening(string $id): string
    {
        $this->restart();

        return $this->event('response.created', ['response' => $this->response($id, 'in_progress')])
            .$this->event('response.in_progress', ['response' => $this->response($id, 'in_progress')])
            .$this->event('response.output_item.added', ['output_index' => 0, 'item' => ['id' => 'msg_1', 'type' => 'message', 'status' => 'in_progress', 'role' => 'assistant', 'content' => []]]);
    }

    public function deltas(string $text, string $type = 'response.output_text.delta'): string
    {
        $out = '';

        foreach (self::pieces($text) as $piece) {
            $out .= $this->event($type, ['item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'delta' => $piece, 'logprobs' => [], 'obfuscation' => substr(md5($piece), 0, 6)]);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function usage(int $input, int $cached, int $written, int $output, int $reasoning): array
    {
        return [
            'input_tokens' => $input,
            'input_tokens_details' => ['cached_tokens' => $cached, 'cache_write_tokens' => $written],
            'output_tokens' => $output,
            'output_tokens_details' => ['reasoning_tokens' => $reasoning],
            'total_tokens' => $input + $output,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function message(string $type, string $field, string $value): array
    {
        return [['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => $type, $field => $value, 'annotations' => []]]]];
    }

    public static function ollama(string $content, ?string $doneReason = 'stop', bool $done = true, ?string $error = null): string
    {
        $lines = '';

        foreach (self::pieces($content) as $piece) {
            $lines .= self::json(['model' => 'gemma4:e4b', 'created_at' => '2026-11-15T12:00:00Z', 'message' => ['role' => 'assistant', 'content' => $piece], 'done' => false])."\n";
        }

        if ($error !== null) {
            $lines .= self::json(['error' => $error])."\n";
        }

        if ($done) {
            $lines .= self::json([
                'model' => 'gemma4:e4b', 'created_at' => '2026-11-15T12:00:05Z', 'message' => ['role' => 'assistant', 'content' => ''],
                'done' => true, 'done_reason' => $doneReason, 'total_duration' => 5000000000, 'prompt_eval_count' => 1200, 'eval_count' => 180,
            ])."\n";
        }

        return $lines;
    }

    /** @return list<string> */
    private static function pieces(string $text): array
    {
        return preg_split('/(?<=\s)/u', $text, flags: PREG_SPLIT_NO_EMPTY) ?: [$text];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

$text = "## Thank-you note\n\nDear [[TBD: neighbour's name]],\n\nThank you for watering my plants while I was away. They look happier than ever.";
$json = json_encode([
    'verdict' => 'almost',
    'summary' => 'A warm note that only needs the names.',
    'strengths' => ['Warm tone'],
    'issues' => [],
    'missing' => ["The neighbour's name"],
    'nextActions' => ['Fill in the name'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cutText = mb_substr($text, 0, 40);
$cutJson = substr($json, 0, 30);
$refusal = "I can't help with that request.";

$f = new Fixtures($argv[1] ?? __DIR__);

$f->write('openai/text.sse', $f->opening('resp_text').$f->deltas($text)
    .$f->event('response.output_text.done', ['item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'text' => $text])
    .$f->event('response.completed', ['response' => $f->response('resp_text', 'completed', ['output' => Fixtures::message('output_text', 'text', $text), 'usage' => Fixtures::usage(1200, 1024, 0, 180, 96)])]));

$f->write('openai/structured.sse', $f->opening('resp_json').$f->deltas($json)
    .$f->event('response.completed', ['response' => $f->response('resp_json', 'completed', ['output' => Fixtures::message('output_text', 'text', $json), 'usage' => Fixtures::usage(900, 0, 512, 60, 0)])]));

$f->write('openai/refusal.sse', $f->opening('resp_refusal').$f->deltas($refusal, 'response.refusal.delta')
    .$f->event('response.completed', ['response' => $f->response('resp_refusal', 'completed', ['output' => Fixtures::message('refusal', 'refusal', $refusal), 'usage' => Fixtures::usage(300, 0, 0, 12, 0)])]));

$f->write('openai/length-text.sse', $f->opening('resp_len').$f->deltas($cutText)
    .$f->event('response.incomplete', ['response' => $f->response('resp_len', 'incomplete', ['incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => Fixtures::message('output_text', 'text', $cutText), 'usage' => Fixtures::usage(1000, 0, 0, 2200, 2190)])]));

$f->write('openai/length-structured.sse', $f->opening('resp_len_json').$f->deltas($cutJson)
    .$f->event('response.incomplete', ['response' => $f->response('resp_len_json', 'incomplete', ['incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => Fixtures::message('output_text', 'text', $cutJson), 'usage' => Fixtures::usage(1000, 0, 0, 1100, 1090)])]));

$f->write('openai/content-filter.sse', $f->opening('resp_filter').$f->deltas('Part of a ')
    .$f->event('response.incomplete', ['response' => $f->response('resp_filter', 'incomplete', ['incomplete_details' => ['reason' => 'content_filter']])]));

$f->write('openai/failed-server.sse', $f->opening('resp_failed')
    .$f->event('response.failed', ['response' => $f->response('resp_failed', 'failed', ['error' => ['code' => 'server_error', 'message' => 'The model failed to generate a response.']])]));

$f->write('openai/failed-prompt.sse', $f->opening('resp_prompt')
    .$f->event('response.failed', ['response' => $f->response('resp_prompt', 'failed', ['error' => ['code' => 'invalid_prompt', 'message' => 'Invalid prompt.']])]));

$f->write('openai/error-rate-limit.sse', $f->opening('resp_rate')
    .$f->event('error', ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached for requests.', 'param' => null]));

$f->write('openai/truncated.sse', $f->opening('resp_cut').$f->deltas(mb_substr($text, 0, 50)));

$f->write('ollama/text.ndjson', Fixtures::ollama($text));
$f->write('ollama/structured.ndjson', Fixtures::ollama($json));
$f->write('ollama/length-text.ndjson', Fixtures::ollama($cutText, 'length'));
$f->write('ollama/length-structured.ndjson', Fixtures::ollama($cutJson, 'length'));
$f->write('ollama/error.ndjson', Fixtures::ollama('Partial ', null, false, 'model runner has unexpectedly stopped'));
$f->write('ollama/truncated.ndjson', Fixtures::ollama(mb_substr($text, 0, 50), null, false));
