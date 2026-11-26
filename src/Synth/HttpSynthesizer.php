<?php

declare(strict_types=1);

namespace Showcase\Narration\Synth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Psr\Http\Message\RequestInterface;

/**
 * Parallel requests with a bounded pool, then sequential retries with exponential backoff for whatever failed.
 * TTS APIs limit requests per minute (Google answers 429 RESOURCE_EXHAUSTED), so a 300-sentence script has to slow
 * down rather than fail.
 */
abstract class HttpSynthesizer implements Synthesizer
{
    /** @param \Closure(int): void|null $sleep injectable for tests */
    public function __construct(
        protected ClientInterface $http = new Client(['timeout' => 90]),
        private int $concurrency = 4,
        private int $retries = 6,
        private ?\Closure $sleep = null,
    ) {
    }

    /** Replace the wait between retries (tests, or a scheduler that must not block). */
    public function withSleep(\Closure $sleep): static
    {
        $clone = clone $this;
        $clone->sleep = $sleep;
        return $clone;
    }

    abstract protected function request(string $text, Voice $voice): RequestInterface;

    /** Response body → PCM s16le mono 24 kHz. */
    abstract protected function pcm(string $body): string;

    public function synthesize(array $texts, Voice $voice): array
    {
        $texts = array_values($texts);
        $out = [];
        $errors = [];

        $requests = function () use ($texts, $voice) {
            foreach ($texts as $i => $text) {
                yield $i => $this->request($text, $voice);
            }
        };
        (new Pool($this->http, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function ($response, $i) use (&$out) {
                $out[$i] = $this->pcm((string) $response->getBody());
            },
            'rejected' => function ($reason, $i) use (&$errors) {
                $errors[$i] = self::describe($reason);
            },
        ]))->promise()->wait();

        foreach (array_keys($errors) as $i) {
            for ($try = 0, $wait = 2; $try < $this->retries; $try++, $wait *= 2) {
                ($this->sleep ?? fn (int $s) => sleep($s))($wait);   // 2, 4, 8, 16, 32, 64 s
                try {
                    $out[$i] = $this->pcm((string) $this->http->send($this->request($texts[$i], $voice))->getBody());
                    unset($errors[$i]);
                    break;
                } catch (\Throwable $e) {
                    $errors[$i] = self::describe($e);
                }
            }
        }
        if ($errors) {
            throw new \RuntimeException(count($errors) . ' sentence(s) without voice. First error: ' . reset($errors));
        }

        ksort($out);
        return array_values($out);
    }

    private static function describe(mixed $reason): string
    {
        if ($reason instanceof RequestException && $reason->getResponse()) {
            return mb_substr((string) $reason->getResponse()->getBody(), 0, 300);
        }
        return $reason instanceof \Throwable ? $reason->getMessage() : 'unknown error';
    }
}
