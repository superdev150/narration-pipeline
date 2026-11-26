<?php

declare(strict_types=1);

namespace Showcase\Narration\Synth;

/**
 * Disk cache per (voice, sentence). Editing one sentence of a 15-minute script re-synthesizes one sentence. A batch
 * that dies halfway resumes without paying again for what it already did.
 */
final class CachedSynthesizer implements Synthesizer
{
    public int $hits = 0;

    public function __construct(private Synthesizer $inner, private string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}");
        }
    }

    public function synthesize(array $texts, Voice $voice): array
    {
        $texts = array_values($texts);
        $out = [];
        $missing = [];
        foreach ($texts as $i => $text) {
            $file = $this->path($voice, $text);
            $pcm = is_file($file) ? file_get_contents($file) : false;
            if ($pcm !== false && $pcm !== '') {
                $out[$i] = $pcm;
                $this->hits++;
            } else {
                $missing[$i] = $text;
            }
        }

        if ($missing) {
            // Duplicate sentences ("Breathe in.") are synthesized once
            $unique = array_values(array_unique($missing));
            $fresh = array_combine($unique, $this->inner->synthesize($unique, $voice));
            foreach ($missing as $i => $text) {
                $out[$i] = $fresh[$text];
            }
            foreach ($fresh as $text => $pcm) {
                $tmp = tempnam($this->directory, 'tmp');
                file_put_contents($tmp, $pcm);
                rename($tmp, $this->path($voice, (string) $text));
            }
        }

        ksort($out);
        return array_values($out);
    }

    private function path(Voice $voice, string $text): string
    {
        return $this->directory . '/' . sha1((string) json_encode([$voice->key(), $text])) . '.pcm';
    }
}
