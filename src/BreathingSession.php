<?php

declare(strict_types=1);

namespace Showcase\Narration;

use Showcase\Narration\Synth\Synthesizer;
use Showcase\Narration\Synth\Voice;

/**
 * Guided breathing: an exact timeline of in / hold / out / hold × cycles. Each spoken cue ("Breathe in") starts on
 * the first sample of its phase and the rest of the phase is silence, so the app's animation (driven by `phases`) and
 * the voice never drift apart, even after 10 minutes.
 */
final class BreathingSession
{
    public const PHASES = ['in', 'hold', 'out', 'hold2'];

    public function __construct(private Synthesizer $synth)
    {
    }

    /**
     * @param array{in: float, hold: float, out: float, hold2: float} $pattern seconds per phase (holds may be 0)
     * @param array<string, string> $cues phase → spoken text; empty = no voice (music only, or the app's timer)
     * @param int $cueCycles speak the cues only in the first N cycles (0 = all), then let the person breathe in silence
     * @return array{pcm: string, duration: float, phases: list<array{k: string, a: float, b: float, c: int}>}
     */
    public function render(array $pattern, int $cycles, Voice $voice, array $cues = [], int $cueCycles = 0, float $lead = 2.0, float $tail = 4.0): array
    {
        $pattern = self::pattern($pattern);
        $cycles = max(1, min(60, $cycles));
        $cueCycles = $cueCycles > 0 ? $cueCycles : $cycles;

        $cuePcm = [];
        $cues = array_filter(array_intersect_key($cues, array_flip(self::PHASES)), fn ($t) => trim((string) $t) !== '');
        if ($cues) {
            $cuePcm = array_combine(array_keys($cues), $this->synth->synthesize(array_values($cues), $voice));
        }

        $parts = [Pcm::silence($lead)];
        $t = Pcm::samples($lead);
        $phases = [];
        for ($c = 0; $c < $cycles; $c++) {
            foreach (self::PHASES as $k) {
                if ($pattern[$k] <= 0) {
                    continue;
                }
                $cue = ($c < $cueCycles && isset($cuePcm[$k])) ? $cuePcm[$k] : '';
                $chunk = Pcm::fit($cue, $pattern[$k]);   // a cue longer than its phase is cut, never shifts the next one
                $len = intdiv(strlen($chunk), 2);
                $phases[] = ['k' => $k, 'a' => round($t / Pcm::RATE, 2), 'b' => round(($t + $len) / Pcm::RATE, 2), 'c' => $c];
                $parts[] = $chunk;
                $t += $len;
            }
        }
        $parts[] = Pcm::silence($tail);
        $pcm = implode('', $parts);

        return ['pcm' => $pcm, 'duration' => round(Pcm::seconds($pcm), 2), 'phases' => $phases];
    }

    /** Sane pattern: 0-20 s per phase, inhale and exhale at least something. */
    public static function pattern(array $p): array
    {
        $out = [];
        foreach (self::PHASES as $k) {
            $out[$k] = max(0.0, min(20.0, round((float) ($p[$k] ?? 0), 1)));
        }
        $out['in'] = $out['in'] > 0 ? $out['in'] : 4.0;
        $out['out'] = $out['out'] > 0 ? $out['out'] : 4.0;
        return $out;
    }
}
