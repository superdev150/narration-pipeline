<?php

declare(strict_types=1);

namespace Showcase\Narration;

/**
 * Markdown script → pages → blocks → sentences.
 *
 * This is the ONLY place sentences are cut. The voice is synthesized sentence by sentence with this same cut, and the
 * timestamps point at (page, sentence index in the page). The app only renders what it receives, so the highlighted
 * sentence never drifts from the audio, whatever the TTS provider does with punctuation.
 *
 * Supported per page: `## heading`, `### subheading`, paragraphs, `- list`, `1. list`, `> callout`, `**bold**`,
 * `*italic*`, and `[pause 8]` (8 s of silence at that point). Pages are separated by a `<!-- page -->` line.
 * No HTML, links or images (links keep their text).
 *
 * Block: { k: h2|h3|p|li|oli|quote|pause, n?: list number or pause seconds, s: [sentences with inline markdown] }
 */
final class Script
{
    public const PAGE_BREAK = '<!-- page -->';

    /** Ends with a dot but does not end a sentence. */
    private const ABBREVIATIONS = ['e.g.', 'i.e.', 'etc.', 'vs.', 'Dr.', 'Mr.', 'Mrs.', 'Ms.', 'Prof.', 'St.', 'approx.',
        'min.', 'No.', 'Fig.', 'al.', 'cf.', 'Jr.', 'Sr.', 'p.ej.', 'aprox.', 'Sra.', 'Sr.'];

    private const PROTECTED_DOT = "\u{2024}";   // one-dot leader: looks like a dot, is not one

    /** One source file → markdown per page (empty pages dropped). */
    public static function pages(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        return array_values(array_filter(array_map('trim', explode(self::PAGE_BREAK, $source)), fn ($p) => $p !== ''));
    }

    /** @return list<array{blocks: list<array>}> what the app receives */
    public static function document(array $pages): array
    {
        return array_map(fn ($md) => ['blocks' => self::blocks((string) $md)], array_values($pages));
    }

    /** One page of markdown → blocks. */
    public static function blocks(string $markdown): array
    {
        // `[pause N]` inside a line goes to its own line: it is never read aloud
        $markdown = (string) preg_replace('/[ \t]*\[pause\s+(\d+(?:\.\d+)?)\s*s?\][ \t]*/i', "\n\n[pause $1]\n\n", $markdown);
        $blocks = [];
        $para = [];
        $quote = [];
        $flushPara = function () use (&$para, &$blocks) {
            if ($para) {
                $blocks[] = ['k' => 'p', 's' => self::sentences(implode(' ', $para))];
                $para = [];
            }
        };
        $flushQuote = function () use (&$quote, &$blocks) {
            if ($quote) {
                $blocks[] = ['k' => 'quote', 's' => self::sentences(implode(' ', $quote))];
                $quote = [];
            }
        };

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $raw) {
            $line = trim($raw);
            if ($line === '') {
                $flushPara();
                $flushQuote();
            } elseif (preg_match('/^\[pause\s+(\d+(?:\.\d+)?)\s*s?\]$/i', $line, $m)) {
                $flushPara();
                $flushQuote();
                $blocks[] = ['k' => 'pause', 'n' => min(60.0, (float) $m[1]), 's' => []];
            } elseif (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $flushPara();
                $flushQuote();
                $blocks[] = ['k' => strlen($m[1]) >= 3 ? 'h3' : 'h2', 's' => [self::inline($m[2])]];
            } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flushPara();
                if (trim($m[1]) !== '') {
                    $quote[] = trim($m[1]);
                }
            } elseif (preg_match('/^[-*•]\s+(.+)$/u', $line, $m)) {
                $flushPara();
                $flushQuote();
                $blocks[] = ['k' => 'li', 's' => self::sentences($m[1])];
            } elseif (preg_match('/^(\d{1,2})[.)]\s+(.+)$/', $line, $m)) {
                $flushPara();
                $flushQuote();
                $blocks[] = ['k' => 'oli', 'n' => (int) $m[1], 's' => self::sentences($m[2])];
            } else {
                $flushQuote();
                $para[] = $line;
            }
        }
        $flushPara();
        $flushQuote();

        return array_values(array_filter($blocks, fn ($b) => $b['s'] !== [] || $b['k'] === 'pause'));
    }

    /** Text with inline markdown → sentences. Respects abbreviations, decimals and closing quotes/brackets. */
    public static function sentences(string $text): array
    {
        $text = self::inline($text);
        if ($text === '') {
            return [];
        }

        foreach (self::ABBREVIATIONS as $abbr) {
            $text = (string) preg_replace('/(?<![\p{L}])' . preg_quote($abbr, '/') . '/u', str_replace('.', self::PROTECTED_DOT, $abbr), $text);
        }
        $text = (string) preg_replace('/(\d)\.(\d)/u', '$1' . self::PROTECTED_DOT . '$2', $text);

        // Cut after . ! ? … (plus closing quotes, brackets, emphasis) followed by space and something that starts a sentence
        $parts = preg_split('/(?<=[.!?…])((?:["”’)\]]|\*{1,2})*)\s+(?=["“‘(\[¿¡*]*[\p{Lu}\d])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $out = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $sentence = trim(str_replace(self::PROTECTED_DOT, '.', $parts[$i] . ($parts[$i + 1] ?? '')));
            if ($sentence !== '') {
                $out[] = $sentence;
            }
        }
        return self::balanceEmphasis($out);
    }

    /** A **bold** or *italic* span cut by a sentence break is closed and reopened, so every sentence renders alone. */
    private static function balanceEmphasis(array $sentences): array
    {
        $carry = '';
        foreach ($sentences as $i => $s) {
            $s = $carry . $s;
            $carry = '';
            if (substr_count($s, '**') % 2 === 1) {
                $s .= '**';
                $carry = '**';
            }
            if (substr_count(str_replace('**', '', $s), '*') % 2 === 1) {
                $s .= '*';
                $carry .= '*';
            }
            $sentences[$i] = $s;
        }
        return $sentences;
    }

    /** Keep only ** and *; links become their text; HTML, images and code marks go. */
    public static function inline(string $text): string
    {
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
        $text = strip_tags($text);
        $text = (string) preg_replace('/`([^`]*)`/', '$1', $text);
        $text = (string) preg_replace('/__([^_]+)__/', '**$1**', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** What the voice reads: no markdown, dashes become a short pause. */
    public static function speakable(string $sentence): string
    {
        $s = str_replace(['**', '*'], '', $sentence);
        $s = (string) preg_replace('/\s*[—–]\s*/u', ', ', $s);   // a dash is a short pause, not a word
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Fingerprint of what the voice depends on: if it changes, stored audio is stale and must be re-rendered. */
    public static function hash(array $pages): string
    {
        return substr(sha1((string) json_encode(self::document($pages), JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /** Human-readable problems to show in an editor before paying for TTS (empty = fine). */
    public static function problems(array $pages): array
    {
        $problems = [];
        foreach (array_values($pages) as $i => $md) {
            $n = $i + 1;
            if (preg_match('/<(?!!--)[a-z]/i', (string) $md)) {
                $problems[] = "Page {$n}: contains HTML (it is removed).";
            }
            foreach (self::blocks((string) $md) as $b) {
                foreach ($b['s'] as $s) {
                    if (mb_strlen($s) > 420) {
                        $problems[] = "Page {$n}: a " . mb_strlen($s) . '-character sentence; split it for reading aloud.';
                    }
                }
            }
        }
        return $problems;
    }
}
