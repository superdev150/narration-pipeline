<?php

declare(strict_types=1);

namespace Showcase\Narration;

/**
 * Raw audio as the pipeline handles it: signed 16-bit little-endian, mono, 24 kHz. Working in PCM until the very end
 * is what makes the timestamps exact. A chunk's duration is its byte length / 48 000, with no codec padding involved.
 */
final class Pcm
{
    public const RATE = 24000;
    public const BYTES_PER_SECOND = self::RATE * 2;

    public static function silence(float $seconds): string
    {
        return str_repeat("\0", self::samples($seconds) * 2);
    }

    public static function seconds(string $pcm): float
    {
        return strlen($pcm) / self::BYTES_PER_SECOND;
    }

    public static function samples(float $seconds): int
    {
        return max(0, (int) round($seconds * self::RATE));
    }

    /** Odd byte counts happen with some providers; pad to whole samples. */
    public static function whole(string $pcm): string
    {
        return strlen($pcm) % 2 ? $pcm . "\0" : $pcm;
    }

    /** Exactly `$seconds` long: cut, or padded with silence. */
    public static function fit(string $pcm, float $seconds): string
    {
        $want = self::samples($seconds) * 2;
        return str_pad(substr($pcm, 0, $want), $want, "\0");
    }

    /** A WAV file's sample data (the `data` chunk), for providers that wrap PCM in WAV. */
    public static function fromWav(string $wav): string
    {
        if (strlen($wav) < 44 || !str_starts_with($wav, 'RIFF')) {
            throw new \RuntimeException('Not a WAV file');
        }
        $pos = 12;
        while ($pos + 8 <= strlen($wav)) {
            $id = substr($wav, $pos, 4);
            $size = unpack('V', substr($wav, $pos + 4, 4))[1];
            if ($id === 'data') {
                return substr($wav, $pos + 8, $size);
            }
            $pos += 8 + $size + ($size % 2);
        }
        throw new \RuntimeException('WAV without data chunk');
    }
}
