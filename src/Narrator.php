<?php

declare(strict_types=1);

namespace Showcase\Narration;

use Showcase\Narration\Synth\Synthesizer;
use Showcase\Narration\Synth\Voice;

/**
 * Script → one voice track with a timestamp for every sentence.
 *
 * Each sentence is synthesized on its own, then the PCM chunks are joined with silences of a chosen length (between
 * sentences, after a block, after a heading, between pages). Because both the speech and the silences are counted in
 * samples, `{p, s, a, b}` (page, sentence in page, start, end in seconds) is exact. No forced alignment or speech
 * recognition is involved, and it does not depend on the provider supporting SSML marks.
 */
final class Narrator
{
    /** Reading pace (an article). A guided meditation uses MEDITATION. */
    public const READING = ['sentence' => 0.28, 'block' => 0.55, 'heading' => 0.7, 'page' => 0.95];
    public const MEDITATION = ['sentence' => 1.2, 'block' => 2.6, 'heading' => 1.8, 'page' => 3.5];

    public function __construct(private Synthesizer $synth)
    {
    }

    /**
     * @param list<string> $pages markdown per page
     * @param array{pauses?: array, lead?: float, tail?: float} $options lead/tail = silence before/after (s);
     *        timings already include the lead
     * @return array{pcm: string, duration: float, timings: list<array{p: int, s: int, a: float, b: float}>, chars: int}
     */
    public function render(array $pages, Voice $voice, array $options = []): array
    {
        $pauses = ($options['pauses'] ?? []) + self::READING;
        $lead = (float) ($options['lead'] ?? 0.0);
        $tail = (float) ($options['tail'] ?? 0.6);   // some players clip the last few hundred ms

        // Flat queue of sentences, each with the silence that follows it
        $queue = [];
        foreach (Script::document($pages) as $p => $page) {
            $index = 0;
            $blocks = $page['blocks'];
            foreach ($blocks as $bi => $block) {
                if ($block['k'] === 'pause') {
                    // `[pause N]` lengthens the silence after the previous sentence (or the lead)
                    if ($queue) {
                        $queue[count($queue) - 1]['pause'] += (float) $block['n'];
                    } else {
                        $lead += (float) $block['n'];
                    }
                    continue;
                }
                $heading = in_array($block['k'], ['h2', 'h3'], true);
                foreach ($block['s'] as $si => $sentence) {
                    $lastInBlock = $si === count($block['s']) - 1;
                    $pause = match (true) {
                        $lastInBlock && $bi === count($blocks) - 1 => $pauses['page'],
                        $lastInBlock && $heading => $pauses['heading'],
                        $lastInBlock => $pauses['block'],
                        default => $pauses['sentence'],
                    };
                    $queue[] = ['p' => $p, 's' => $index++, 'text' => Script::speakable($sentence), 'pause' => $pause];
                }
            }
        }
        if (!$queue) {
            throw new \InvalidArgumentException('Nothing to read.');
        }

        $speech = $this->synth->synthesize(array_column($queue, 'text'), $voice);

        $parts = [Pcm::silence($lead)];
        $t = Pcm::samples($lead);   // in samples: no float drift over a long script
        $timings = [];
        $chars = 0;
        $last = count($queue) - 1;
        foreach ($queue as $i => $q) {
            $chunk = Pcm::whole($speech[$i]);
            $len = intdiv(strlen($chunk), 2);
            $timings[] = ['p' => $q['p'], 's' => $q['s'], 'a' => round($t / Pcm::RATE, 2), 'b' => round(($t + $len) / Pcm::RATE, 2)];
            $parts[] = $chunk;
            $t += $len;
            $chars += mb_strlen($q['text']);
            if ($i < $last) {
                $gap = Pcm::samples($q['pause']);
                $parts[] = str_repeat("\0", $gap * 2);
                $t += $gap;
            }
        }
        $parts[] = Pcm::silence($tail);
        $pcm = implode('', $parts);

        return ['pcm' => $pcm, 'duration' => round(Pcm::seconds($pcm), 2), 'timings' => $timings, 'chars' => $chars];
    }
}
