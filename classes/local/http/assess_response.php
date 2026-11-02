<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Immutable result of a call to the Vektra assess API.
 *
 * @package    local_vektradiscuss
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_vektradiscuss\local\http;

/**
 * Response DTO: status, decoded JSON and the REQ-010 error envelope fields.
 */
final class assess_response {
    /**
     * Constructor.
     *
     * @param int $status HTTP status; 0 when no HTTP response was received.
     * @param array|null $json Decoded JSON body, or null when absent or not JSON.
     * @param string|null $errorcode Vektra error code (ERR-*) or a client code
     *        (transport, not_configured, too_large, insecure_url).
     * @param string|null $message Human-readable error message.
     * @param array|null $details Structured error details (field errors).
     * @param string|null $requestid Value of the X-Request-ID response header.
     * @param array $headers Response headers, lower-case names.
     */
    public function __construct(
        /** @var int HTTP status; 0 when no HTTP response was received. */
        public readonly int $status,
        /** @var array|null Decoded JSON body. */
        public readonly ?array $json = null,
        /** @var string|null Error code. */
        public readonly ?string $errorcode = null,
        /** @var string|null Error message. */
        public readonly ?string $message = null,
        /** @var array|null Structured error details. */
        public readonly ?array $details = null,
        /** @var string|null Request id. */
        public readonly ?string $requestid = null,
        /** @var array Response headers, lower-case names. */
        public readonly array $headers = []
    ) {
    }

    /**
     * Whether the call succeeded (2xx).
     *
     * @return bool
     */
    public function ok(): bool {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * A response for a call that never produced an HTTP status.
     *
     * @param string $errorcode Client error code.
     * @param string $message Message.
     * @return self
     */
    public static function failure(string $errorcode, string $message): self {
        return new self(0, null, $errorcode, $message);
    }
}
