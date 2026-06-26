<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Support;

use Grafida\Secret\ProcessRunner;

/**
 * Opens an external URL in the desktop's default web browser.
 *
 * The application is a native webview; following a normal link would replace
 * the single-page-app with the remote page. Outbound links (e.g. the licence
 * text on the FSF website) are therefore handed to the operating system, which
 * opens them in the user's real browser instead.
 */
final class UrlOpener
{
    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {}

    /**
     * Opens an http(s) URL in the default browser.
     *
     * @throws \InvalidArgumentException when the URL is not a valid http(s) URL
     * @throws \RuntimeException         when the OS command fails
     */
    public function open(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        if (($scheme !== 'http' && $scheme !== 'https') || filter_var($url, \FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Only http(s) URLs can be opened.');
        }

        $command = match (\PHP_OS_FAMILY) {
            'Darwin'  => ['open', $url],
            'Windows' => ['explorer', $url],
            default   => ['xdg-open', $url],
        };

        [$code, , $stderr] = $this->runner->run($command);

        // explorer.exe returns a non-zero exit code even when it succeeds, so we
        // only treat a failure as fatal on the platforms that report it reliably.
        if ($code !== 0 && \PHP_OS_FAMILY !== 'Windows') {
            $message = trim($stderr);

            throw new \RuntimeException($message !== '' ? $message : 'Unable to open the link.');
        }
    }
}
