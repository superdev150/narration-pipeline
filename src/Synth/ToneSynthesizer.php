<?php

declare(strict_types=1);

namespace Showcase\Narration\Synth;

use Showcase\Narration\Pcm;

/**
 * Offline stand-in for a TTS provider: each sentence becomes a soft tone whose length follows the text (~15 characters
 * per second, like speech). Deterministic, free and instant, so it works for tests and for the demo without keys.
 */
final class ToneSynthesizer implements Synthesizer
{
    public int $calls = 0;

    public function __construct(private float $charsPerSecond = 15.0)
    {
    }

    public function synthesize(array $texts, Voice $voice): array
    {
        $this->calls += count($texts);
        return array_map(fn (string $t) => $this->tone($t, $voice), array_values($texts));
    }

    private function tone(string $text, Voice $voice): string
    {
        $seconds = max(0.4, mb_strlen($text) / ($this->charsPerSecond * max(0.5, $voice->rate)));
        $n = Pcm::samples($seconds);
        $freq = 180 + (crc32($text) % 120);   // a different pitch per sentence, to hear the cuts
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $envelope = min(1, $i / 1200, ($n - $i) / 1200);   // 50 ms fade in/out: no clicks
            $out .= pack('v', (int) (sin(2 * M_PI * $freq * $i / Pcm::RATE) * 6000 * $envelope) & 0xFFFF);
        }
        return $out;
    }
}
