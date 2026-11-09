<?php

declare(strict_types=1);

namespace Ksilyanov\Jev;

/**
 * One typed question, in Jev's own vocabulary. No validation: the API enforces its limits (422).
 * Instructions and descriptions may be a string or structured data (array), as the API allows.
 */
final class Question
{
    public const NOUL = 'noul';
    public const CHOICE = 'choice';
    public const SCORE = 'score';

    private function __construct(
        public readonly string $type,
        public readonly string|array $instructions,
        public readonly ?array $criteria,
    ) {
    }

    /** Yes/no; the answer is the probability of "true". Both descriptions are optional. */
    public static function noul(string|array $instructions, string|array|null $whenTrue = null, string|array|null $whenFalse = null): self
    {
        $criteria = array_filter(['true' => $whenTrue, 'false' => $whenFalse], static fn ($v) => $v !== null);

        return new self(self::NOUL, $instructions, $criteria === [] ? null : $criteria);
    }

    /** @param array<string, string|array|null> $options option => description (null = no description) */
    public static function choice(string|array $instructions, array $options): self
    {
        return new self(self::CHOICE, $instructions, $options);
    }

    /** @param list<string|array> $levels ordered from lowest to highest */
    public static function score(string|array $instructions, array $levels): self
    {
        return new self(self::SCORE, $instructions, array_values($levels));
    }

    /** Wire form. Maps are forced to JSON objects so numeric or empty keys don't turn into lists. */
    public function toWire(): array
    {
        $wire = ['type' => $this->type, 'instructions' => $this->instructions];
        if ($this->criteria !== null) {
            $wire['criteria'] = $this->type === self::SCORE ? $this->criteria : (object) $this->criteria;
        }

        return $wire;
    }
}
