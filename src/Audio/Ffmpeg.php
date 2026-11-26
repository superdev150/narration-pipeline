<?php

declare(strict_types=1);

namespace Showcase\Narration\Audio;

use Showcase\Narration\Pcm;

/**
 * The ffmpeg side: encoding, loudness, mixing. Commands run as argument lists (proc_open, no shell), so no text from a
 * script can be interpreted as a command, and temp files are always removed.
 */
final class Ffmpeg
{
    /** Target loudness of the voice. Providers deliver anything from about −20 to −34 LUFS. */
    public const VOICE_LUFS = -19.0;
    /** Music is normalised here first, then lowered by the mix level. */
    public const MUSIC_LUFS = -18.0;

    private string $bin;

    public function __construct(?string $binary = null)
    {
        $this->bin = $binary ?? self::find() ?? throw new \RuntimeException('ffmpeg not found (apt install ffmpeg / brew install ffmpeg)');
    }

    public static function find(): ?string
    {
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        $which = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
        return $which !== '' && is_executable($which) ? $which : null;
    }

    /** Integrated loudness (EBU R128) of a PCM track, or null if too short to measure. */
    public function loudness(string $pcm): ?float
    {
        if (Pcm::seconds($pcm) < 1.0) {
            return null;
        }
        $output = $this->withPcm($pcm, fn (string $in) => $this->run(
            ['-hide_banner', '-nostats', ...self::pcmInput($in), '-af', 'ebur128', '-f', 'null', '-'],
            allowStderr: true,
        ));
        if (!preg_match_all('/I:\s+(-?[\d.]+) LUFS/', $output, $m) || !$m[1]) {
            return null;
        }
        return (float) end($m[1]);   // the summary line comes last
    }

    /** Gain in dB that brings the voice to VOICE_LUFS, clamped so a near-silent track is not blown up. */
    public function voiceGain(string $pcm): float
    {
        $lufs = $this->loudness($pcm);
        return $lufs === null ? 0.0 : round(max(-6.0, min(16.0, self::VOICE_LUFS - $lufs)), 1);
    }

    /** Voice alone → MP3 mono 64 kbps, with gain and a limiter so the gain never clips. */
    public function voiceMp3(string $pcm, float $gainDb = 0.0): string
    {
        return $this->withPcm($pcm, fn (string $in) => $this->toFile(fn (string $out) => $this->run([
            '-y', '-loglevel', 'error', ...self::pcmInput($in),
            '-af', "volume={$gainDb}dB,alimiter=limit=0.9:level=disabled",
            '-codec:a', 'libmp3lame', '-b:a', '64k', $out,
        ]), 'mp3'));
    }

    /**
     * Voice + music → MP3 stereo 96 kbps with EXACTLY the voice's duration. The app can then switch between
     * "voice" and "voice + music" files at the same second; on iOS a web view cannot change an <audio> element's
     * volume, so switching files is the only way to offer a music toggle.
     *
     * The music is looped with 6 s crossfades when shorter than the voice, normalised to MUSIC_LUFS, lowered by
     * `$musicDb`, faded in over 3 s and out over 6 s.
     */
    public function mix(string $voicePcm, string $musicFile, float $musicSeconds, float $musicDb = -19.0, float $voiceGainDb = 0.0): string
    {
        if (!is_file($musicFile)) {
            throw new \InvalidArgumentException("Music file not found: {$musicFile}");
        }
        $duration = round(Pcm::seconds($voicePcm), 3);
        $xf = 6.0;
        $copies = max(1, (int) ceil(($duration + $xf) / max(10.0, $musicSeconds - $xf)));

        $chain = '';
        $last = '[1:a]';
        for ($i = 2; $i <= $copies; $i++) {
            $chain .= "{$last}[{$i}:a]acrossfade=d={$xf}:c1=tri:c2=tri[m{$i}];";
            $last = "[m{$i}]";
        }
        $fadeOut = max(0, $duration - 6);
        $filter = $chain
            . "{$last}aformat=sample_rates=44100:channel_layouts=stereo,atrim=0:{$duration},"
            . 'loudnorm=I=' . self::MUSIC_LUFS . ":TP=-2:LRA=14,volume={$musicDb}dB,"
            . "afade=t=in:st=0:d=3,afade=t=out:st={$fadeOut}:d=6[music];"
            . "[0:a]volume={$voiceGainDb}dB,alimiter=limit=0.9:level=disabled,aresample=44100,aformat=channel_layouts=stereo[voice];"
            . '[voice][music]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[mix]';

        return $this->withPcm($voicePcm, function (string $in) use ($copies, $musicFile, $filter, $duration) {
            $inputs = self::pcmInput($in);
            for ($i = 0; $i < $copies; $i++) {
                array_push($inputs, '-i', $musicFile);
            }
            return $this->toFile(fn (string $out) => $this->run([
                '-y', '-loglevel', 'error', ...$inputs, '-filter_complex', $filter, '-map', '[mix]',
                '-t', (string) $duration, '-codec:a', 'libmp3lame', '-b:a', '96k', $out,
            ]), 'mp3');
        });
    }

    /** Duration of any media file, via ffmpeg itself (no ffprobe needed). */
    public function duration(string $file): float
    {
        $output = $this->run(['-hide_banner', '-i', $file, '-f', 'null', '-'], allowStderr: true);
        if (!preg_match_all('/time=(\d+):(\d+):([\d.]+)/', $output, $m) || !$m[0]) {
            throw new \RuntimeException("Cannot read duration of {$file}");
        }
        $i = count($m[0]) - 1;
        return (int) $m[1][$i] * 3600 + (int) $m[2][$i] * 60 + (float) $m[3][$i];
    }

    /** Synthetic ambient pad, to try the mix without a music file. */
    public function makeAmbient(string $outFile, float $seconds): void
    {
        $this->run([
            '-y', '-loglevel', 'error', '-f', 'lavfi',
            '-i', "sine=frequency=220:duration={$seconds},volume=0.3",
            '-f', 'lavfi', '-i', "sine=frequency=277:duration={$seconds},volume=0.2",
            '-f', 'lavfi', '-i', "anoisesrc=color=pink:duration={$seconds}:amplitude=0.05",
            '-filter_complex', '[0][1][2]amix=inputs=3,tremolo=f=0.15:d=0.4,lowpass=f=1800',
            '-codec:a', 'libmp3lame', '-b:a', '96k', $outFile,
        ]);
    }

    // ───────────────────────────── internals

    private static function pcmInput(string $file): array
    {
        return ['-f', 's16le', '-ar', (string) Pcm::RATE, '-ac', '1', '-i', $file];
    }

    private function withPcm(string $pcm, \Closure $fn): mixed
    {
        $in = (string) tempnam(sys_get_temp_dir(), 'npcm');
        file_put_contents($in, $pcm);
        try {
            return $fn($in);
        } finally {
            @unlink($in);
        }
    }

    private function toFile(\Closure $fn, string $ext): string
    {
        $out = tempnam(sys_get_temp_dir(), 'nout') . ".{$ext}";
        try {
            $fn($out);
            $bytes = @file_get_contents($out);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('ffmpeg produced no output');
            }
            return $bytes;
        } finally {
            @unlink($out);
            @unlink(substr($out, 0, -strlen(".{$ext}")));
        }
    }

    /** Run ffmpeg with an argument list (no shell). Returns stdout, plus stderr when asked (ffmpeg reports there). */
    private function run(array $args, bool $allowStderr = false): string
    {
        // stderr goes to a file, not a pipe: ebur128 writes a line every 100 ms, and a full pipe would block ffmpeg
        // while we wait on stdout
        $err = (string) tempnam(sys_get_temp_dir(), 'nerr');
        try {
            $process = proc_open([$this->bin, ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $err, 'w']], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('Cannot start ffmpeg');
            }
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);
            $stderr = (string) file_get_contents($err);
        } finally {
            @unlink($err);
        }
        if ($code !== 0) {
            throw new \RuntimeException('ffmpeg: ' . mb_substr(trim($stderr), -400));
        }
        return $allowStderr ? $stderr . $stdout : $stdout;
    }
}
