<?php

declare(strict_types=1);

namespace Ksilyanov\Jev;

use Ksilyanov\Jev\Exceptions\JevInvalidResponseException;

final class Response
{
    /**
     * @param array<string, Answer> $answers keyed by the question names you passed
     * @param string|null $model exact version that answered (e.g. jev-1.13.0); null if the provider did not say
     * @param int|null $inputTokens usage of the final attempt; null if not reported
     * @param int $latencyMs whole call, retries and waits included
     * @param int $attempts 1 = no retries
     */
    public function __construct(
        public readonly array $answers,
        public readonly ?string $model,
        public readonly string $driver,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly int $latencyMs,
        public readonly int $attempts,
        public readonly ?string $requestId,
        public readonly array $raw,
    ) {
    }

    public function answer(string $name): Answer
    {
        return $this->answers[$name] ?? throw new JevInvalidResponseException("no answer for '{$name}'");
    }
}
