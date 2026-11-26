<?php

declare(strict_types=1);

namespace Showcase\Narration\Synth;

/** Everything that changes how a sentence sounds. It is part of the cache key. */
final class Voice
{
    public function __construct(
        public readonly string $provider,      // google | openai | tone
        public readonly string $name,          // en-US-Chirp3-HD-Aoede, coral, …
        public readonly float $rate = 1.0,
        public readonly string $language = 'en-US',
        public readonly string $instructions = '',   // tone direction, for providers that take it
    ) {
    }

    public function key(): array
    {
        return [$this->provider, $this->name, round($this->rate, 2), $this->language, $this->instructions];
    }
}
