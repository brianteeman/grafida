<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Editor;

/**
 * Turns on WKWebView's native "check spelling while typing" on macOS (gh-24).
 *
 * WKWebView gates ALL native spell checking on the NSUserDefaults flag
 * WebContinuousSpellCheckingEnabled, which its text checker reads once, lazily, on
 * the first spell-check (see WebKit's TextCheckerMac.mm). When the flag is off no
 * misspelling is ever underlined — not even a freshly typed one. A normal Mac app
 * flips this flag from its Edit ▸ Spelling ▸ "Check Spelling While Typing" menu item
 * (bound to -toggleContinuousSpellChecking:); Boson wires up no menu bar, so the flag
 * is never enabled and the editor's spell checking is dead on any machine where some
 * other WebKit app has not already turned it on. That is why it "worked for one person
 * and not another" on identical code.
 *
 * {@see enable()} sets the flag to true in Grafida's OWN preferences domain (never the
 * global domain — that would change every WebKit app) via CoreFoundation's CFPreferences
 * C API through FFI. CFPreferences is plain C, so it avoids the arm64 objc_msgSend
 * variadic-calling-convention hazard, and unlike a `defaults write` subprocess it spawns
 * nothing. It must run before the webview first spell-checks, i.e. before the app boots
 * (see index.php). Best-effort: any failure just leaves spell checking off, as before.
 */
final class MacSpellCheck
{
    /** The WebKit user-defaults flag that gates continuous spell checking. */
    private const KEY = 'WebContinuousSpellCheckingEnabled';

    /** kCFStringEncodingUTF8. */
    private const ENCODING_UTF8 = 0x08000100;

    /** dlopen path for the CoreFoundation framework (resolved from the dyld cache). */
    private const FRAMEWORK = '/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation';

    /**
     * Enable continuous spell checking for this process's preferences domain.
     *
     * @return bool True when the flag was set, false when it could not be (non-macOS,
     *              FFI unavailable, or a CoreFoundation error).
     */
    public static function enable(): bool
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || !\extension_loaded('ffi')) {
            return false;
        }

        try {
            $cf = \FFI::cdef(
                'typedef void *CFTypeRef;'
                . ' typedef const void *CFStringRef;'
                . ' typedef const void *CFAllocatorRef;'
                . ' typedef unsigned char Boolean;'
                . ' typedef unsigned int CFStringEncoding;'
                . ' extern CFTypeRef kCFBooleanTrue;'
                . ' extern CFStringRef kCFPreferencesCurrentApplication;'
                . ' CFStringRef CFStringCreateWithCString(CFAllocatorRef alloc, const char *cStr, CFStringEncoding encoding);'
                . ' void CFPreferencesSetAppValue(CFStringRef key, CFTypeRef value, CFStringRef applicationID);'
                . ' Boolean CFPreferencesAppSynchronize(CFStringRef applicationID);'
                . ' void CFRelease(CFTypeRef cf);',
                self::FRAMEWORK
            );

            // Build the CFString key (kCFAllocatorDefault = NULL). Owned (+1), released below.
            $key = $cf->CFStringCreateWithCString(null, self::KEY, self::ENCODING_UTF8);

            if (\FFI::isNull($key)) {
                return false;
            }

            $cf->CFPreferencesSetAppValue($key, $cf->kCFBooleanTrue, $cf->kCFPreferencesCurrentApplication);
            $cf->CFPreferencesAppSynchronize($cf->kCFPreferencesCurrentApplication);
            $cf->CFRelease($key);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
