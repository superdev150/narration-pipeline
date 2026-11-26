<?php

declare(strict_types=1);

namespace Showcase\Narration\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Showcase\Narration\Synth\GoogleSynthesizer;
use Showcase\Narration\Synth\OpenAiSynthesizer;
use Showcase\Narration\Synth\Voice;

final class HttpSynthesizerTest extends TestCase
{
    private array $history = [];

    private function client(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new Client(['handler' => $stack]);
    }

    private static function googleBody(string $pcm): string
    {
        $wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
        return (string) json_encode(['audioContent' => base64_encode($wav)]);
    }

    public function testGoogleRequestAndWavDecoding(): void
    {
        $synth = new GoogleSynthesizer(fn () => 'token-1', 'quota-project', $this->client([
            new Response(200, [], self::googleBody('AAAA')),
            new Response(200, [], self::googleBody('BBBB')),
        ]), concurrency: 1);

        $pcm = $synth->synthesize(['Hello.', 'World.'], new Voice('google', 'en-US-Chirp3-HD-Aoede', 0.9));

        self::assertSame(['AAAA', 'BBBB'], $pcm);
        $request = $this->history[0]['request'];
        self::assertSame('Bearer token-1', $request->getHeaderLine('Authorization'));
        self::assertSame('quota-project', $request->getHeaderLine('x-goog-user-project'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame(['languageCode' => 'en-US', 'name' => 'en-US-Chirp3-HD-Aoede'], $body['voice']);
        self::assertSame(['audioEncoding' => 'LINEAR16', 'sampleRateHertz' => 24000, 'speakingRate' => 0.9], $body['audioConfig']);
    }

    public function testRateLimitedSentencesAreRetriedWithBackoffInOrder(): void
    {
        $waits = [];
        $synth = (new OpenAiSynthesizer('key', 'gpt-4o-mini-tts', $this->client([
            new Response(200, [], 'one'),
            new Response(429, [], '{"error":"rate limit"}'),
            new Response(200, [], 'three'),
            new Response(429, [], '{"error":"still"}'),   // first retry of sentence 2
            new Response(200, [], 'two'),                  // second retry
        ]), concurrency: 1))->withSleep(function (int $s) use (&$waits) {
            $waits[] = $s;
        });

        self::assertSame(['one', 'two', 'three'], $synth->synthesize(['1', '2', '3'], new Voice('openai', 'coral', instructions: 'calm')));
        self::assertSame([2, 4], $waits);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame(['model' => 'gpt-4o-mini-tts', 'voice' => 'coral', 'input' => '1', 'instructions' => 'calm', 'response_format' => 'pcm'], $body);
    }

    public function testGivesUpWithAUsefulMessage(): void
    {
        $synth = (new OpenAiSynthesizer('key', 'm', $this->client(array_fill(0, 8, new Response(500, [], 'provider down'))), concurrency: 1))
            ->withSleep(fn () => null);
        $this->expectExceptionMessage('1 sentence(s) without voice');
        $synth->synthesize(['x'], new Voice('openai', 'coral'));
    }
}
