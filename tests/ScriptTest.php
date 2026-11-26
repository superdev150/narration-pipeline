<?php

declare(strict_types=1);

namespace Showcase\Narration\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Showcase\Narration\Script;

final class ScriptTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function sentences(): iterable
    {
        yield 'simple' => ['One. Two! Three?', ['One.', 'Two!', 'Three?']];
        yield 'abbreviations' => ['See Dr. Smith, e.g. on Monday. Then rest.', ['See Dr. Smith, e.g. on Monday.', 'Then rest.']];
        yield 'decimals' => ['Hold for 2.5 seconds. Release.', ['Hold for 2.5 seconds.', 'Release.']];
        yield 'closing quote' => ['She said "stop." Then she left.', ['She said "stop."', 'Then she left.']];
        yield 'lowercase after dot is not a new sentence' => ['Version 3. it works.', ['Version 3. it works.']];
        yield 'spanish opening marks' => ['Respira. ¿Lo notas? ¡Bien!', ['Respira.', '¿Lo notas?', '¡Bien!']];
        yield 'ellipsis' => ['Wait… Now go.', ['Wait…', 'Now go.']];
        yield 'numbers start sentences' => ['Breathe out. 4 more times.', ['Breathe out.', '4 more times.']];
    }

    #[DataProvider('sentences')]
    public function testSentenceSplitting(string $text, array $expected): void
    {
        self::assertSame($expected, Script::sentences($text));
    }

    public function testBoldAcrossASentenceBreakIsReopened(): void
    {
        self::assertSame(['**Relax your jaw.**', '**Drop your shoulders.**'], Script::sentences('**Relax your jaw. Drop your shoulders.**'));
    }

    public function testBlocks(): void
    {
        $md = <<<'MD'
## Before you start
Find a quiet place. Sit comfortably.

> Never hold your breath
> if you feel dizzy.

- Feet flat on the floor.
1. Close your eyes. [pause 4] Notice the room.
MD;
        $blocks = Script::blocks($md);

        self::assertSame(['h2', 'p', 'quote', 'li', 'oli', 'pause', 'p'], array_column($blocks, 'k'));
        self::assertSame(['Find a quiet place.', 'Sit comfortably.'], $blocks[1]['s']);
        self::assertSame(['Never hold your breath if you feel dizzy.'], $blocks[2]['s']);
        self::assertSame(1, $blocks[4]['n']);
        self::assertSame(4.0, $blocks[5]['n']);
    }

    public function testInlineCleanup(): void
    {
        self::assertSame('Read the guide now.', Script::inline('Read <b>the</b> [guide](https://x.y) now.![img](a.png)'));
        self::assertSame('Slow, then faster', Script::speakable('**Slow** — then *faster*'));
    }

    public function testPagesAndHash(): void
    {
        $pages = Script::pages("One.\n<!-- page -->\n\n<!-- page -->\nTwo.");
        self::assertSame(['One.', 'Two.'], $pages);

        $a = Script::hash(['Hello there. Bye.']);
        self::assertSame($a, Script::hash(['Hello   there.  Bye.']));   // spacing does not change what is read
        self::assertNotSame($a, Script::hash(['Hello there! Bye.']));
    }

    public function testProblems(): void
    {
        self::assertSame([], Script::problems(['Fine.']));
        self::assertCount(2, Script::problems(['<div>x</div> ' . str_repeat('word ', 100) . '.']));
    }
}
