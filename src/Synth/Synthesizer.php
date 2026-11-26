<?php

declare(strict_types=1);

namespace Showcase\Narration\Synth;

interface Synthesizer
{
    /**
     * Speak each text separately.
     *
     * @param list<string> $texts
     * @return list<string> PCM (s16le, mono, 24 kHz) per text, same order
     */
    public function synthesize(array $texts, Voice $voice): array;
}
