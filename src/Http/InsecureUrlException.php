<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

/**
 * Raised when a URL is rejected before any outbound HTTP request is made
 * because its scheme is not HTTPS.
 *
 * The API token carried in the Authorization header is the credential needed
 * to read, create and modify articles on the remote Joomla site; sending it
 * over cleartext HTTP would expose it to every router between the app and
 * the site. Rejecting non-HTTPS URLs at the input boundary — before any
 * transport call is dispatched — keeps the token from ever leaving the
 * process in plaintext.
 */
final class InsecureUrlException extends \RuntimeException
{
    public function __construct(string $message = 'Only HTTPS URLs are allowed.')
    {
        parent::__construct($message);
    }
}
