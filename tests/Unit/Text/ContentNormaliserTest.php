<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Text;

use Grafida\Storage\SettingsRepository;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\TestCase;
use Grafida\Text\ContentNormalisationService;
use Grafida\Text\ContentNormaliser;
use Joomla\Database\DatabaseInterface;

/**
 * The invisible-character clean-up applied to AI replies, imported Markdown,
 * plain-text pastes and everything published to a site.
 *
 * The interesting half of this class is not what it removes but what it
 * refuses to remove: an emoji sequence, a Persian joiner and a subdivision flag
 * are all built out of exactly the characters a watermark is built out of.
 */
final class ContentNormaliserTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();
    }

    private function normaliser(): ContentNormaliser
    {
        return new ContentNormaliser($this->settings());
    }

    private function settings(): ContentNormalisationService
    {
        return new ContentNormalisationService(new SettingsRepository($this->db));
    }

    /** Plain prose comes back byte-identical, without a single character being decoded. */
    public function testLeavesOrdinaryTextAlone(): void
    {
        $html = "<p>Καλημέρα, Ελλάδα — “quoted”, 25 °C.</p>\n<p>Ça va&nbsp;?</p>";

        self::assertSame($html, $this->normaliser()->normalise($html, ContentNormalisationService::FULL));
    }

    /** The zero-width family, when it is free-floating rather than joining anything. */
    public function testStripsZeroWidthCharacters(): void
    {
        $text = "He\u{200B}llo\u{200C} wo\u{200D}rld\u{2060}!\u{FEFF}";

        self::assertSame('Hello world!', $this->normaliser()->normalise($text, ContentNormalisationService::FULL));
    }

    /** Bidi marks, isolates and overrides — a reading-order attack as much as a watermark. */
    public function testStripsBidiControls(): void
    {
        $text = "\u{200E}left\u{202E}right\u{202C} \u{2066}iso\u{2069}\u{061C}";

        self::assertSame('leftright iso', $this->normaliser()->normalise($text, ContentNormalisationService::FULL));
    }

    /** Tag characters carry a hidden ASCII payload one code point at a time. */
    public function testStripsFreeFloatingTagCharacters(): void
    {
        $hidden = '';

        foreach (str_split('secret') as $letter) {
            $hidden .= mb_chr(0xE0000 + \ord($letter), 'UTF-8');
        }

        self::assertSame('Report', $this->normaliser()->normalise('Report' . $hidden, ContentNormalisationService::FULL));
    }

    /** Soft hyphens, variation selectors and the combining grapheme joiner. */
    public function testStripsOtherInvisibleMarks(): void
    {
        $text = "in\u{00AD}visible\u{034F} text\u{FE00}\u{E0101}";

        self::assertSame('invisible text', $this->normaliser()->normalise($text, ContentNormalisationService::FULL));
    }

    /** An unknown Cf format character is stripped by the catch-all, not published. */
    public function testStripsUnknownFormatCharacters(): void
    {
        $text = "a\u{1BCA0}b";

        self::assertSame('ab', $this->normaliser()->normalise($text, ContentNormalisationService::FULL));
    }

    /** Exotic spaces collapse to U+0020 in the full mode. */
    public function testCollapsesExoticSpaces(): void
    {
        $text = "one\u{00A0}two\u{2009}three\u{3000}four\u{202F}five";

        self::assertSame('one two three four five', $this->normaliser()->normalise($text, ContentNormalisationService::FULL));
    }

    /** …and are left exactly as typed in the invisible-only mode, which still strips carriers. */
    public function testInvisibleModeKeepsSpaces(): void
    {
        $text = "Bonjour\u{00A0}: voici\u{200B} le texte";

        self::assertSame(
            "Bonjour\u{00A0}: voici le texte",
            $this->normaliser()->normalise($text, ContentNormalisationService::INVISIBLE),
        );
    }

    /** Off is off: not even the unambiguous carriers go. */
    public function testOffModeChangesNothing(): void
    {
        $text = "He\u{200B}llo\u{00A0}world";

        self::assertSame($text, $this->normaliser()->normalise($text, ContentNormalisationService::OFF));
    }

    /** A ZWJ emoji family is three people and two joiners; stripping them shows three people. */
    public function testKeepsEmojiZeroWidthJoiners(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        self::assertSame(
            'Our ' . $family . ' story',
            $this->normaliser()->normalise('Our ' . $family . ' story', ContentNormalisationService::FULL),
        );
    }

    /** ❤️‍🔥 is base + VS16 + ZWJ + base: the ZWJ's predecessor is itself glue. */
    public function testKeepsEmojiSequenceAfterVariationSelector(): void
    {
        $heartOnFire = "\u{2764}\u{FE0F}\u{200D}\u{1F525}";

        self::assertSame($heartOnFire, $this->normaliser()->normalise($heartOnFire, ContentNormalisationService::FULL));
    }

    /** A subdivision flag is an emoji base followed by a whole run of tag characters. */
    public function testKeepsFlagTagSequence(): void
    {
        $scotland = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";

        self::assertSame($scotland, $this->normaliser()->normalise($scotland, ContentNormalisationService::FULL));
    }

    /** A keycap is a digit, U+FE0F and the enclosing mark. */
    public function testKeepsKeycapSequence(): void
    {
        $keycap = "1\u{FE0F}\u{20E3}";

        self::assertSame($keycap, $this->normaliser()->normalise($keycap, ContentNormalisationService::FULL));
    }

    /** ZWNJ between Persian letters is orthography: می‌روم is not می روم. */
    public function testKeepsScriptJoinersBetweenLetters(): void
    {
        $persian = "\u{0645}\u{06CC}\u{200C}\u{0631}\u{0648}\u{0645}";

        self::assertSame($persian, $this->normaliser()->normalise($persian, ContentNormalisationService::FULL));
    }

    /** The Arabic and Syriac number/annotation signs are Cf characters that mean something. */
    public function testKeepsOrthographicFormatCharacters(): void
    {
        $text = "\u{0600}\u{0661}\u{0662} \u{06DD}\u{0663}";

        self::assertSame($text, $this->normaliser()->normalise($text, ContentNormalisationService::FULL));
    }

    /** A stripped character must not lend its context to the next one. */
    public function testStrippedCharacterIsNotContextForTheNext(): void
    {
        // ZWSP (stripped), then ZWJ. The ZWJ's predecessor is the space before
        // the ZWSP, so it is free-floating and goes too.
        $text = "a \u{200B}\u{200D}b";

        self::assertSame('a b', $this->normaliser()->normalise($text, ContentNormalisationService::FULL));
    }

    /** Applying twice changes nothing the first pass did not. */
    public function testIsIdempotent(): void
    {
        $normaliser = $this->normaliser();
        $text       = "A\u{200B}rticle\u{00A0}text \u{1F468}\u{200D}\u{1F469}";
        $once       = $normaliser->normalise($text, ContentNormalisationService::FULL);

        self::assertSame($once, $normaliser->normalise($once, ContentNormalisationService::FULL));
    }

    /** apply() reads the stored preference; the default is the full clean-up. */
    public function testApplyFollowsTheStoredPreference(): void
    {
        $text = "x\u{200B}y\u{00A0}z";

        self::assertSame("xy z", $this->normaliser()->apply($text));

        $this->settings()->set(ContentNormalisationService::INVISIBLE);
        self::assertSame("xy\u{00A0}z", $this->normaliser()->apply($text));

        $this->settings()->set(ContentNormalisationService::OFF);
        self::assertSame($text, $this->normaliser()->apply($text));
    }

    /** An unrecognised stored value means the default, not "do nothing". */
    public function testUnknownModeSnapsBackToFull(): void
    {
        $settings = $this->settings();
        (new SettingsRepository($this->db))->set(ContentNormalisationService::SETTING_KEY, 'nonsense');

        self::assertSame(ContentNormalisationService::FULL, $settings->current());
    }
}
