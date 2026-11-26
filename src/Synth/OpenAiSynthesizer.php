<?php

declare(strict_types=1);

namespace Showcase\Narration\Synth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

/** OpenAI speech API with `response_format: pcm` (already s16le mono 24 kHz). `instructions` steer the tone. */
final class OpenAiSynthesizer extends HttpSynthesizer
{
    public function __construct(
        private string $apiKey,
        private string $model = 'gpt-4o-mini-tts',
        ClientInterface $http = new Client(['timeout' => 90]),
        int $concurrency = 4,
    ) {
        parent::__construct($http, $concurrency);
    }

    protected function request(string $text, Voice $voice): RequestInterface
    {
        $body = array_filter([
            'model' => $this->model,
            'voice' => $voice->name,
            'input' => $text,
            'instructions' => $voice->instructions ?: null,
            'response_format' => 'pcm',
            'speed' => abs($voice->rate - 1.0) > 0.001 ? round($voice->rate, 2) : null,
        ], fn ($v) => $v !== null);

        return new Request('POST', 'https://api.openai.com/v1/audio/speech', [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ], (string) json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    protected function pcm(string $body): string
    {
        return $body;
    }
}
