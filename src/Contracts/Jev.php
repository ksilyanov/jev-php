<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Contracts;

use Ksilyanov\Jev\Question;
use Ksilyanov\Jev\Response;

interface Jev
{
    /**
     * @param string|array $state what is being judged: text, or structured data (object/list)
     * @param array<string, Question> $questions keyed by name; answers come back under the same names
     *
     * @throws \Ksilyanov\Jev\Exceptions\JevUnavailableException transient; retries exhausted
     * @throws \Ksilyanov\Jev\Exceptions\JevRequestRejectedException this request was rejected (e.g. too long, malformed)
     * @throws \Ksilyanov\Jev\Exceptions\JevAccessException credentials, balance or account limit — every call will fail
     * @throws \Ksilyanov\Jev\Exceptions\JevInvalidResponseException the provider answered with something unreadable
     */
    public function evaluate(string|array $state, array $questions): Response;

    /** The model this client requests (as configured), known before any call. */
    public function model(): string;
}
