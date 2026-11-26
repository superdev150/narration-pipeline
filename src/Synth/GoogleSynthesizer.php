<?php

declare(strict_types=1);

namespace Showcase\Narration\Synth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Showcase\Narration\Pcm;

/**
 * Google Cloud Text-to-Speech (e.g. Chirp 3 HD voices, which do not support SSML marks: one more reason to synthesize
 * sentence by sentence). Pass a closure that returns an OAuth access token, for example with google/auth:
 *
 *   $creds = ApplicationDefaultCredentials::getCredentials(['https://www.googleapis.com/auth/cloud-platform']);
 *   new GoogleSynthesizer(fn () => $creds->fetchAuthToken()['access_token'], 'my-project');
 */
final class GoogleSynthesizer extends HttpSynthesizer
{
    private ?string $token = null;

    /** @param \Closure(): string $accessToken */
    public function __construct(
        private \Closure $accessToken,
        private string $quotaProject,
        ClientInterface $http = new Client(['timeout' => 90]),
        int $concurrency = 4,
    ) {
        parent::__construct($http, $concurrency);
    }

    protected function request(string $text, Voice $voice): RequestInterface
    {
        $this->token ??= ($this->accessToken)();
        $audio = ['audioEncoding' => 'LINEAR16', 'sampleRateHertz' => Pcm::RATE];
        if (abs($voice->rate - 1.0) > 0.001) {
            $audio['speakingRate'] = round($voice->rate, 2);
        }
        return new Request('POST', 'https://texttospeech.googleapis.com/v1/text:synthesize', [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'x-goog-user-project' => $this->quotaProject,
        ], (string) json_encode([
            'input' => ['text' => $text],
            'voice' => ['languageCode' => $voice->language, 'name' => $voice->name],
            'audioConfig' => $audio,
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function pcm(string $body): string
    {
        $wav = base64_decode((string) (json_decode($body, true)['audioContent'] ?? ''));
        return Pcm::fromWav($wav);   // LINEAR16 comes wrapped in a WAV header
    }
}
