<?php

declare(strict_types=1);

namespace Showcase\Narration\Tests;

use PHPUnit\Framework\TestCase;
use Showcase\Narration\Audio\Ffmpeg;
use Showcase\Narration\Narrator;
use Showcase\Narration\Synth\ToneSynthesizer;
use Showcase\Narration\Synth\Voice;

/** Real ffmpeg runs. Skipped if ffmpeg is not installed (CI installs it). */
final class FfmpegTest extends TestCase
{
    private Ffmpeg $ffmpeg;
    private string $dir;

    protected function setUp(): void
    {
        if (!Ffmpeg::find()) {
            self::markTestSkipped('ffmpeg not installed');
        }
        $this->ffmpeg = new Ffmpeg();
        $this->dir = sys_get_temp_dir() . '/narration-ff-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            array_map('unlink', glob("{$this->dir}/*"));
            rmdir($this->dir);
        }
    }

    private function voice(): array
    {
        return (new Narrator(new ToneSynthesizer()))->render(
            ['Settle into a comfortable position. Let your shoulders drop. Breathe slowly through your nose.'],
            new Voice('tone', 'test'),
            ['pauses' => Narrator::MEDITATION, 'lead' => 4.0, 'tail' => 10.0],
        );
    }

    public function testVoiceGainReachesTheTargetLoudness(): void
    {
        $pcm = $this->voice()['pcm'];
        $gain = $this->ffmpeg->voiceGain($pcm);
        self::assertNotSame(0.0, $gain);

        file_put_contents("{$this->dir}/v.mp3", $this->ffmpeg->voiceMp3($pcm, $gain));
        $raw = (string) shell_exec(escapeshellarg((string) Ffmpeg::find()) . ' -hide_banner -nostats -i ' . escapeshellarg("{$this->dir}/v.mp3") . ' -af ebur128 -f null - 2>&1');
        preg_match_all('/I:\s+(-?[\d.]+) LUFS/', $raw, $m);
        self::assertEqualsWithDelta(Ffmpeg::VOICE_LUFS, (float) end($m[1]), 1.5);
    }

    public function testMixHasExactlyTheVoiceDurationEvenWhenTheMusicLoops(): void
    {
        $voice = $this->voice();
        $music = "{$this->dir}/music.mp3";
        $this->ffmpeg->makeAmbient($music, 14);   // shorter than the voice: must loop with crossfades

        $mix = "{$this->dir}/mix.mp3";
        file_put_contents($mix, $this->ffmpeg->mix($voice['pcm'], $music, $this->ffmpeg->duration($music)));
        $voiceMp3 = "{$this->dir}/voice.mp3";
        file_put_contents($voiceMp3, $this->ffmpeg->voiceMp3($voice['pcm']));

        self::assertGreaterThan(14, $voice['duration']);
        self::assertEqualsWithDelta($voice['duration'], $this->ffmpeg->duration($mix), 0.06);
        self::assertEqualsWithDelta($this->ffmpeg->duration($voiceMp3), $this->ffmpeg->duration($mix), 0.06);
    }

    public function testShortTracksAreNotMeasured(): void
    {
        self::assertNull($this->ffmpeg->loudness(str_repeat("\0", 1000)));
        self::assertSame(0.0, $this->ffmpeg->voiceGain(str_repeat("\0", 1000)));
    }
}
