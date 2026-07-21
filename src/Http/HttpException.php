<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

/** Thrown when an HTTP request cannot be completed at the transport level. */
final class HttpException extends \RuntimeException
{
    /**
     * cURL error numbers that mean "the request never reached a server": DNS
     * failure, connection refused/unreachable, and timeouts. These are what an
     * offline machine (or a site that is down) produces, as opposed to a TLS
     * or protocol error, which means we *did* talk to something.
     */
    private const CONNECTIVITY_ERRNOS = [
        5,  // CURLE_COULDNT_RESOLVE_PROXY
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
        28, // CURLE_OPERATION_TIMEDOUT
        55, // CURLE_SEND_ERROR
        56, // CURLE_RECV_ERROR
    ];

    /**
     * @param int $curlErrno The libcurl error number, or 0 when the failure did
     *                       not come from cURL (the stream-wrapper fallback).
     */
    public function __construct(string $message, public readonly int $curlErrno = 0)
    {
        parent::__construct($message);
    }

    /**
     * Whether the request failed because the host could not be reached at all —
     * i.e. the machine is offline, DNS is dead, or the site is down.
     */
    public function isConnectivityFailure(): bool
    {
        return \in_array($this->curlErrno, self::CONNECTIVITY_ERRNOS, true);
    }
}
