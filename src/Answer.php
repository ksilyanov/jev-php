<?php

declare(strict_types=1);

namespace Ksilyanov\Jev;

use Ksilyanov\Jev\Exceptions\JevInvalidResponseException;

/** One answer, fields exactly as the API returns them for its type. */
final class Answer
{
    /**
     * @param array<string, float> $probabilities choice: option => p; score: level index => p
     * @param array<string, string> $legend score: level index => description
     * @param array $raw the answer as Jev returned it
     */
    public function __construct(
        public readonly string $type,
        public readonly ?float $noul = null,
        public readonly ?string $choice = null,
        public readonly ?float $score = null,
        public readonly ?float $confidence = null,
        public readonly array $probabilities = [],
        public readonly array $legend = [],
        public readonly array $raw = [],
    ) {
    }

    public function probabilityOf(string|int $option): float
    {
        return (float) ($this->probabilities[(string) $option] ?? 0.0);
    }

    public static function fromWire(array $raw): self
    {
        $type = (string) ($raw['type'] ?? '');
        $probabilities = array_map('floatval', (array) ($raw['probabilities'] ?? []));
        $confidence = isset($raw['confidence']) ? (float) $raw['confidence'] : null;

        return match ($type) {
            Question::NOUL => new self(
                type: $type,
                noul: isset($raw['noul']) ? (float) $raw['noul'] : throw new JevInvalidResponseException('noul answer without "noul"'),
                raw: $raw,
            ),
            Question::CHOICE => new self(
                type: $type,
                choice: isset($raw['choice']) ? (string) $raw['choice'] : throw new JevInvalidResponseException('choice answer without "choice"'),
                confidence: $confidence,
                probabilities: $probabilities,
                raw: $raw,
            ),
            Question::SCORE => new self(
                type: $type,
                score: isset($raw['score']) ? (float) $raw['score'] : throw new JevInvalidResponseException('score answer without "score"'),
                confidence: $confidence,
                probabilities: $probabilities,
                legend: array_map('strval', (array) ($raw['legend'] ?? [])),
                raw: $raw,
            ),
            default => throw new JevInvalidResponseException("unknown answer type '{$type}'"),
        };
    }
}
