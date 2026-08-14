<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Text;

/**
 * Removes the invisible characters that mark text as machine-written.
 *
 * Large language models — and the web apps around them — routinely emit
 * characters that occupy no space on screen: zero-width spaces and joiners, the
 * word joiner, bidirectional overrides and isolates, variation selectors, and
 * the Unicode *tag* characters (U+E0000 block), which can carry a whole hidden
 * ASCII payload one code point at a time. Some of it is deliberate
 * watermarking, some of it is an artefact of copying out of a web page. Either
 * way it travels with the text, and it is not merely untidy: a screen reader
 * announces what it finds, a bidi override can reverse the reading order of a
 * sentence, and a soft hyphen inside a word breaks find-in-page for everyone.
 * An article is published to be read by people, so this is an accessibility fix
 * first and a de-fingerprinting one second.
 *
 * The character tables follow "Layer A" of guillaumemeyer/watermarks-remover
 * (https://github.com/guillaumemeyer/watermarks-remover): strip the invisible
 * formatting characters, and — in {@see ContentNormalisationService::FULL} —
 * collapse the exotic spaces onto U+0020. Homoglyph folding (that project's
 * aggressive mode, mapping Cyrillic *а* onto Latin *a*) is deliberately **not**
 * implemented: an article about Russian, or a Greek surname in an English
 * sentence, is not a watermark, and rewriting letters is a class of damage
 * removing invisibles cannot do.
 *
 * ⚠️ **Not every invisible character is a carrier.** Some are load-bearing, and
 * stripping them changes what the reader sees:
 *
 * - ZWJ and the emoji variation selector build emoji sequences — 👨‍👩‍👧 is three
 *   people joined by two ZWJs, and ❤️‍🔥 is a heart, U+FE0F, a ZWJ and a flame.
 * - A flag such as 🏴󠁧󠁢󠁳󠁣󠁴󠁿 *is* an emoji base followed by tag characters.
 * - ZWNJ and ZWJ are ordinary orthography in Persian (می‌روم), Arabic and the
 *   Indic scripts, where they select the joining form of a letter.
 * - U+0600–U+0605, U+06DD, U+070F, U+08E2, U+110BD and U+110CD are Arabic,
 *   Syriac and Kaithi number/annotation signs, not controls.
 *
 * So the decision is contextual: a joiner is kept when the character *kept
 * before it* makes it meaningful, and stripped when it is free-floating. (This
 * goes slightly further than the reference implementation, which loses every
 * tag character of a flag past the first, and the ZWJ of any sequence whose
 * previous character is a variation selector.)
 *
 * Two limits worth knowing. It works on **code points**, so a mark spelled as
 * an HTML entity (`&#8203;`) survives — nothing we produce writes them that
 * way. And it is applied to HTML as a flat string rather than through the DOM:
 * none of the characters it touches can occur in HTML syntax, and a string pass
 * cannot reformat the markup around them the way a parse/serialise round trip
 * would.
 */
final class ContentNormaliser
{
    /**
     * Every character the decision procedure might act on.
     *
     * Matching this is what makes the pass affordable: article bodies are tens
     * of kilobytes and almost never contain any of these, so one regex scan
     * answers "nothing to do" without decoding a single character. `\p{Cf}`
     * carries most of the strip set (and the tag characters); the rest are
     * combining marks, fillers or spaces, which it does not.
     */
    private const CANDIDATES = '/[\p{Cf}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180E}'
        . '\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}'
        . '\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u';

    /** Invisible formatting characters with no legitimate use in article prose. */
    private const STRIP_SINGLE = [
        0x00AD, // soft hyphen
        0x034F, // combining grapheme joiner
        0x061C, // Arabic letter mark
        0x115F, // Hangul choseong filler
        0x1160, // Hangul jungseong filler
        0x17B4, // Khmer vowel inherent AQ
        0x17B5, // Khmer vowel inherent AA
        0xFEFF, // BOM / zero-width no-break space
    ];

    /** @var list<array{int, int}> Inclusive code point ranges stripped outright. */
    private const STRIP_RANGES = [
        [0x180B, 0x180E],   // Mongolian free variation selectors + vowel separator
        [0x200B, 0x200F],   // ZWSP, ZWNJ, ZWJ, LRM, RLM
        [0x202A, 0x202E],   // bidi embeddings and overrides
        [0x2060, 0x2064],   // word joiner, invisible operators
        [0x2066, 0x206F],   // bidi isolates, deprecated format controls
        [0xFE00, 0xFE0F],   // variation selectors 1–16
        [0xFFF9, 0xFFFB],   // interlinear annotation
        [0xE0001, 0xE007F], // language tag + tag characters
        [0xE0100, 0xE01EF], // variation selectors 17–256
    ];

    /** Spaces that stand in for U+0020, and what they collapse to. */
    private const SPACES = [
        0x00A0 => ' ', // no-break space
        0x1680 => ' ', // Ogham space mark
        0x2000 => ' ', // en quad
        0x2001 => ' ', // em quad
        0x2002 => ' ', // en space
        0x2003 => ' ', // em space
        0x2004 => ' ', // three-per-em space
        0x2005 => ' ', // four-per-em space
        0x2006 => ' ', // six-per-em space
        0x2007 => ' ', // figure space
        0x2008 => ' ', // punctuation space
        0x2009 => ' ', // thin space
        0x200A => ' ', // hair space
        0x202F => ' ', // narrow no-break space
        0x205F => ' ', // medium mathematical space
        0x3000 => ' ', // ideographic space
    ];

    /** Format characters that are ordinary orthography, not carriers. */
    private const ORTHOGRAPHIC = [
        0x0600, 0x0601, 0x0602, 0x0603, 0x0604, 0x0605, // Arabic number signs
        0x06DD,                                          // Arabic end of ayah
        0x070F,                                          // Syriac abbreviation mark
        0x08E2,                                          // Arabic disputed end of ayah
        0x110BD, 0x110CD,                                // Kaithi number signs
    ];

    public function __construct(private readonly ContentNormalisationService $settings) {}

    /** Normalise text according to the user's stored preference. */
    public function apply(string $text): string
    {
        return $this->normalise($text, $this->settings->current());
    }

    /**
     * Normalise text in an explicitly named mode.
     *
     * @param string $mode One of {@see ContentNormalisationService::AVAILABLE}.
     */
    public function normalise(string $text, string $mode): string
    {
        if ($mode === ContentNormalisationService::OFF || $text === '') {
            return $text;
        }

        $found = preg_match_all(self::CANDIDATES, $text, $matches, \PREG_OFFSET_CAPTURE);

        if ($found === false || $found === 0) {
            return $text;
        }

        $collapseSpaces = $mode === ContentNormalisationService::FULL;
        $out            = '';
        $pos            = 0;
        // The last character actually kept, which is the context a joiner is
        // judged against. Deliberately *not* the previous input character: a
        // stripped one must not lend its meaning to the next.
        $previous = null;

        foreach ($matches[0] as $match) {
            [$char, $offset] = $match;

            if ($offset > $pos) {
                $span     = substr($text, $pos, $offset - $pos);
                $out     .= $span;
                $previous = $this->lastCharacter($span) ?? $previous;
            }

            $pos = $offset + \strlen($char);

            $kept = $this->decide($char, $previous, $collapseSpaces);

            if ($kept !== '') {
                $out     .= $kept;
                $previous = $kept;
            }
        }

        return $out . substr($text, $pos);
    }

    /**
     * What survives of one candidate character: itself, a plain space, or
     * nothing at all.
     *
     * @param string|null $previous The last character kept before it, if any.
     */
    private function decide(string $char, ?string $previous, bool $collapseSpaces): string
    {
        $codepoint = mb_ord($char, 'UTF-8');

        if ($codepoint === false) {
            return $char;
        }

        $before = ($previous === null || $previous === '') ? null : mb_ord($previous, 'UTF-8');
        $before = $before === false ? null : $before;

        if ($before !== null && $this->isLoadBearing($codepoint, $before)) {
            return $char;
        }

        if (\in_array($codepoint, self::ORTHOGRAPHIC, true)) {
            return $char;
        }

        if ($this->isStripped($codepoint)) {
            return '';
        }

        if (isset(self::SPACES[$codepoint])) {
            return $collapseSpaces ? self::SPACES[$codepoint] : $char;
        }

        // Anything else in the Cf category is a format control we have no reason
        // to publish: unknown to this table, invisible by definition.
        return preg_match('/^\p{Cf}$/u', $char) === 1 ? '' : $char;
    }

    /**
     * Is this invisible character part of what the reader sees, given the
     * character kept before it?
     */
    private function isLoadBearing(int $codepoint, int $before): bool
    {
        // Emoji glue: the joiner and the text/emoji presentation selectors, held
        // together by a base that may itself be glue (❤️‍🔥 is base, VS16, ZWJ, base).
        if (\in_array($codepoint, [0x200D, 0xFE0E, 0xFE0F], true) && $this->continuesEmoji($before)) {
            return true;
        }

        // ZWNJ/ZWJ after a letter or mark, i.e. the joining-form control of
        // Persian, Arabic, Devanagari and their neighbours.
        if (($codepoint === 0x200C || $codepoint === 0x200D) && $before > 0x7F && $this->isLetterOrMark($before)) {
            return true;
        }

        // A tag sequence spelling out a subdivision flag, e.g. 🏴 + gbsct + cancel.
        if ($codepoint >= 0xE0020 && $codepoint <= 0xE007F) {
            return $this->isEmojiBase($before) || ($before >= 0xE0020 && $before <= 0xE007F);
        }

        return false;
    }

    /** Can this character start or continue an emoji sequence? */
    private function continuesEmoji(int $codepoint): bool
    {
        return $this->isEmojiBase($codepoint)
            || $codepoint === 0x200D
            || $codepoint === 0xFE0E
            || $codepoint === 0xFE0F;
    }

    /** Pictographs, symbols and the keycap bases, i.e. what emoji are built from. */
    private function isEmojiBase(int $codepoint): bool
    {
        if ($codepoint >= 0x1F000 && $codepoint <= 0x1FAFF) {
            return true;
        }

        if ($codepoint >= 0x2600 && $codepoint <= 0x27BF) {
            return true;
        }

        if ($codepoint >= 0x2B00 && $codepoint <= 0x2BFF) {
            return true;
        }

        if (\in_array($codepoint, [0x00A9, 0x00AE, 0x2122, 0x3030, 0x303D, 0x3297, 0x3299], true)) {
            return true;
        }

        // Keycaps: # * 0-9.
        return $codepoint === 0x23 || $codepoint === 0x2A || ($codepoint >= 0x30 && $codepoint <= 0x39);
    }

    private function isLetterOrMark(int $codepoint): bool
    {
        $char = mb_chr($codepoint, 'UTF-8');

        return $char !== false && preg_match('/^[\p{L}\p{M}]$/u', $char) === 1;
    }

    private function isStripped(int $codepoint): bool
    {
        if (\in_array($codepoint, self::STRIP_SINGLE, true)) {
            return true;
        }

        foreach (self::STRIP_RANGES as [$from, $to]) {
            if ($codepoint >= $from && $codepoint <= $to) {
                return true;
            }
        }

        return false;
    }

    /** The last whole UTF-8 character of a byte span, or null if it has none. */
    private function lastCharacter(string $span): ?string
    {
        $last = mb_substr($span, -1, 1, 'UTF-8');

        return $last === '' ? null : $last;
    }
}
