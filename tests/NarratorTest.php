<?php

declare(strict_types=1);

namespace Showcase\Narration\Tests;

use PHPUnit\Framework\TestCase;
use Showcase\Narration\BreathingSession;
use Showcase\Narration\Narrator;
use Showcase\Narration\Pcm;
use Showcase\Narration\Synth\CachedSynthesizer;
use Showcase\Narration\Synth\Synthesizer;
use Showcase\Narration\Synth\ToneSynthesizer;
use Showcase\Narration\Synth\Voice;

final class NarratorTest extends TestCase
{
    private Voice $voice;

    protected function setUp(): void
    {
        $this->voice = new Voice('tone', 'test');
    }

    /** Every sentence is exactly 1 s, so expected timings are easy to compute by hand. */
    private static function oneSecond(): Synthesizer
    {
        return new class implements Synthesizer {
            public array $seen = [];

            public function synthesize(array $texts, Voice $voice): array
            {
                array_push($this->seen, ...$texts);
                return array_map(fn () => str_repeat("\x01\x00", Pcm::RATE), $texts);
            }
        };
    }

    public function testTimingsAreExact(): void
    {
        $r = (new Narrator(self::oneSecond()))->render(["## Title\nFirst. Second.\n\nThird.", 'Fourth.'], $this->voice, [
            'pauses' => ['sentence' => 0.5, 'block' => 1.0, 'heading' => 2.0, 'page' => 3.0],
            'lead' => 1.0,
            'tail' => 0.5,
        ]);

        self::assertSame([
            ['p' => 0, 's' => 0, 'a' => 1.0, 'b' => 2.0],     // Title, then heading pause 2
            ['p' => 0, 's' => 1, 'a' => 4.0, 'b' => 5.0],     // First, sentence pause 0.5
            ['p' => 0, 's' => 2, 'a' => 5.5, 'b' => 6.5],     // Second, block pause 1
            ['p' => 0, 's' => 3, 'a' => 7.5, 'b' => 8.5],     // Third, page pause 3
            ['p' => 1, 's' => 0, 'a' => 11.5, 'b' => 12.5],   // Fourth, then tail 0.5
        ], $r['timings']);
        self::assertSame(13.0, $r['duration']);
        self::assertSame(13 * Pcm::BYTES_PER_SECOND, strlen($r['pcm']));
    }

    public function testInlinePauseExtendsTheSilence(): void
    {
        $r = (new Narrator(self::oneSecond()))->render(['Close your eyes. [pause 8] Notice your breath.'], $this->voice, [
            'pauses' => ['sentence' => 0.5, 'block' => 1.0, 'heading' => 1.0, 'page' => 1.0],
            'tail' => 0,
        ]);
        // "Close your eyes." ends its paragraph (1.0) + 8 s pause
        self::assertSame(10.0, $r['timings'][1]['a']);
    }

    public function testThePauseMarkerIsNeverSpoken(): void
    {
        $synth = self::oneSecond();
        (new Narrator($synth))->render(['**Relax.** [pause 3] Good — *very* good.'], $this->voice);
        self::assertSame(['Relax.', 'Good, very good.'], $synth->seen);
    }

    public function testNoDriftOverALongScript(): void
    {
        $page = implode(' ', array_fill(0, 400, 'Breathe gently and notice.'));
        $r = (new Narrator(new ToneSynthesizer()))->render([$page], $this->voice, ['tail' => 0]);

        $last = end($r['timings']);
        self::assertEqualsWithDelta($r['duration'], $last['b'], 0.011);
        self::assertSame(400, count($r['timings']));
    }

    public function testCacheSkipsSentencesAlreadyPaidFor(): void
    {
        $tone = new ToneSynthesizer();
        $dir = sys_get_temp_dir() . '/narration-cache-' . bin2hex(random_bytes(4));
        $narrator = new Narrator(new CachedSynthesizer($tone, $dir));

        $narrator->render(['One. Two. Three. Breathe in. Breathe in.'], $this->voice);
        self::assertSame(4, $tone->calls);   // the repeated sentence is synthesized once

        $narrator->render(['One. Two. Three changed. Breathe in.'], $this->voice);
        self::assertSame(5, $tone->calls);   // only the edited sentence

        $narrator->render(['One.'], new Voice('tone', 'other-voice'));
        self::assertSame(6, $tone->calls);   // another voice is another cache entry

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    public function testBreathingPhasesAreExactAndCuesStartOnThePhase(): void
    {
        $r = (new BreathingSession(new ToneSynthesizer()))->render(
            ['in' => 4, 'hold' => 2, 'out' => 6, 'hold2' => 0],
            cycles: 3,
            voice: $this->voice,
            cues: ['in' => 'Breathe in', 'out' => 'And out'],
            cueCycles: 1,
            lead: 2.0,
            tail: 4.0,
        );

        self::assertSame(['in', 'hold', 'out', 'in', 'hold', 'out', 'in', 'hold', 'out'], array_column($r['phases'], 'k'));
        self::assertSame(['k' => 'in', 'a' => 2.0, 'b' => 6.0, 'c' => 0], $r['phases'][0]);
        self::assertSame(['k' => 'out', 'a' => 32.0, 'b' => 38.0, 'c' => 2], $r['phases'][8]);
        self::assertSame(2 + 3 * 12 + 4.0, $r['duration']);

        // Voice only in the first cycle: the second inhale is pure silence
        $second = substr($r['pcm'], (int) (14 * Pcm::BYTES_PER_SECOND), 4 * Pcm::BYTES_PER_SECOND);
        self::assertSame(str_repeat("\0", strlen($second)), $second);
        $first = substr($r['pcm'], 2 * Pcm::BYTES_PER_SECOND, Pcm::BYTES_PER_SECOND);
        self::assertNotSame(str_repeat("\0", strlen($first)), $first);
    }

    public function testPatternIsSanitised(): void
    {
        self::assertSame(['in' => 4.0, 'hold' => 20.0, 'out' => 4.0, 'hold2' => 0.0], BreathingSession::pattern(['in' => -1, 'hold' => 99]));
    }

    public function testWavUnwrapping(): void
    {
        $data = str_repeat("\x10\x00", 10);
        $wav = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            . 'LIST' . pack('V', 4) . 'INFO'
            . 'data' . pack('V', strlen($data)) . $data;
        self::assertSame($data, Pcm::fromWav($wav));
    }
}
