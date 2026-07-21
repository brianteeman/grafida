<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Debug;

/**
 * One captured HTTP exchange — a single request/response pair recorded by
 * {@see RecordingTransport}.
 *
 * The record itself stores the raw (already capped, see {@see BodyFormatter})
 * request/response bodies; {@see toArray()} is the only place redaction and
 * body formatting happen, so an instance never leaves PHP with a live secret
 * in it.
 */
final readonly class RequestRecord
{
    /**
     * @param array<string, string> $requestHeaders  Header name => value.
     * @param string|null           $requestBody      Raw, already capped.
     * @param int|null              $status           Null when the transport threw.
     * @param array<string, string> $responseHeaders Header name => value.
     * @param string|null           $responseBody     Raw, already capped.
     * @param string|null           $error            Transport failure message, else null.
     */
    public function __construct(
        public string $timestamp,
        public float $durationMs,
        public string $method,
        public string $url,
        public array $requestHeaders,
        public ?string $requestBody,
        public ?int $status,
        public array $responseHeaders,
        public ?string $responseBody,
        public ?string $error = null,
    ) {}

    /**
     * Builds the presentation shape consumed by both the SPA and the export —
     * redacted and body-formatted, never raw.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $secrets = Redactor::secretsFromHeaders($this->requestHeaders);

        $requestTruncated  = $this->requestBody !== null && \strlen($this->requestBody) >= BodyFormatter::MAX_BYTES;
        $responseTruncated = $this->responseBody !== null && \strlen($this->responseBody) >= BodyFormatter::MAX_BYTES;

        return [
            'timestamp'  => $this->timestamp,
            'durationMs' => $this->durationMs,
            'request'    => [
                'method'  => $this->method,
                'url'     => Redactor::redactText($this->url, $secrets),
                'headers' => Redactor::headers($this->requestHeaders),
                'body'    => BodyFormatter::describe($this->requestBody, $requestTruncated, $secrets),
            ],
            'response'   => [
                'status'  => $this->status,
                'headers' => Redactor::headers($this->responseHeaders),
                'body'    => BodyFormatter::describe($this->responseBody, $responseTruncated, $secrets),
            ],
            'error'      => $this->error,
        ];
    }
}
