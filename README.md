# narration-pipeline

Turn a **markdown script into narrated audio with an exact timestamp for every sentence**, consistent loudness across
voices, and a background-music version of exactly the same length. PHP + ffmpeg, works with any TTS provider.

```php
$narrator = new Narrator(new CachedSynthesizer(new GoogleSynthesizer($token, 'my-project'), '/var/cache/tts'));
$track = $narrator->render($pages, new Voice('google', 'en-US-Chirp3-HD-Aoede', 0.9), ['pauses' => Narrator::MEDITATION, 'lead' => 4]);

$track['timings'];  // [{p: 0, s: 0, a: 4.0, b: 5.21}, {p: 0, s: 1, a: 6.41, b: 9.3}, …]   page, sentence, start, end
$ffmpeg = new Ffmpeg();
$gain = $ffmpeg->voiceGain($track['pcm']);                        // → −19 LUFS whatever the provider
$voiceMp3 = $ffmpeg->voiceMp3($track['pcm'], $gain);
$mixMp3 = $ffmpeg->mix($track['pcm'], 'music.mp3', 180.0, -19, $gain);   // same duration, music looped and ducked
```

> This is the audio engine behind the narrated content of my apps: guided lessons, meditations, breathing exercises
> and the guided prayers of Selah. There a content editor writes markdown in an admin panel and publishes. The app plays the audio and highlights each sentence
> as it is spoken.

## The problem

You want "karaoke" highlighting of the sentence being read, in an app, for long narrated content.

- Asking the TTS for word or SSML marks ties you to one provider. Several of the best voices (e.g. Google Chirp 3 HD)
  do not support marks at all.
- Forced alignment or speech recognition after the fact is heavy and drifts on long tracks.
- Every provider and voice comes out at a different volume (I measured −20 to −34 LUFS), so content sounds uneven.
- A music on/off toggle in a web-view app (Ionic/Capacitor) cannot just change the volume. iOS ignores `volume` on
  `<audio>`.

## The approach

1. **One splitter, used everywhere** (`Script`). Markdown → pages → blocks → sentences. It handles abbreviations
   (`Dr.`, `e.g.`), decimals (`2.5`), closing quotes, Spanish `¿¡`, ellipses, and bold spans that cross a sentence
   break. The app receives these same blocks, so text and audio can never disagree on where a sentence starts.
2. **Synthesize sentence by sentence** in PCM (16-bit, 24 kHz). Any provider works this way (`GoogleSynthesizer`,
   `OpenAiSynthesizer`, or the offline `ToneSynthesizer`).
3. **Join with silences counted in samples**: one length between sentences, another after a block, a heading or a
   page, and `[pause 8]` where the author wants it. A chunk lasts *bytes / 48 000* seconds, so `{p, s, a, b}` is
   **exact**, with no drift even after 400 sentences (there is a test).
4. **Encode once at the end** with ffmpeg. The voice is measured with EBU R128 and brought to −19 LUFS (with a
   limiter). The music is normalised to −18 LUFS, then lowered, looped with crossfades and faded. Both files come out
   with the **same duration**, so the app switches files at the same `currentTime` when music is toggled.

Also:

- **Caching per (voice, sentence)** (`CachedSynthesizer`). Editing one sentence of a 15-minute meditation
  re-synthesizes one sentence. A batch that dies halfway resumes for free. Repeated cues ("Breathe in") are paid once.
- **Rate limits** (`HttpSynthesizer`). Requests go in a bounded parallel pool, then failures are retried one by one
  with exponential backoff (2, 4, 8… s). Google returns 429 under load, and a 300-sentence script should slow down
  instead of failing.
- **Staleness.** `Script::hash()` fingerprints what the voice depends on. If the text changes, the stored audio is
  marked stale and the panel refuses to publish it until it is regenerated.
- **Breathing exercises** (`BreathingSession`). An exact timeline of in / hold / out / hold × cycles. Each cue starts
  on the first sample of its phase and is cut if longer, so the app's animation and the voice stay together for 10
  minutes.
- **Safe ffmpeg.** Commands run as argument lists through `proc_open`, never through a shell. stderr goes to a file
  so a chatty filter cannot block the process. Temp files are always cleaned up.

## Try it

```bash
composer install
composer test                       # 27 tests; the ffmpeg ones run if ffmpeg is installed (CI installs it)
php bin/demo                        # offline: tones instead of a voice, no keys
php -S 127.0.0.1:8090 -t demo       # open http://127.0.0.1:8090/player.html
```

`player.html` is a ~100-line version of what the app does: it renders the blocks, highlights the sentence whose
`[a, b)` contains `currentTime`, lets you click a sentence to jump to it, and switches between `voice.mp3` and
`mix.mp3` at the same second. With a real voice: `php bin/demo --openai` (needs `OPENAI_API_KEY`) or
`php bin/demo --google=<gcp-project>` (needs `gcloud`).

## Layout

```
src/Script.php               markdown → blocks → sentences; speakable text; hash; editor warnings
src/Narrator.php             sentence PCM + silences → track + exact timings
src/BreathingSession.php     phase-exact breathing timeline
src/Pcm.php                  sample math, WAV unwrapping
src/Synth/                   Synthesizer, Voice, cache, HTTP pool with backoff, Google, OpenAI, offline tones
src/Audio/Ffmpeg.php         LUFS measurement and gain, MP3 encoding, music mix with looping and fades
demo/                        a script, the renderer output and the player
```

MIT licensed.
