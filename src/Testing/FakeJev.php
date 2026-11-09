<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Testing;

use Ksilyanov\Jev\Answer;
use Ksilyanov\Jev\Contracts\Jev;
use Ksilyanov\Jev\Exceptions\JevException;
use Ksilyanov\Jev\Response;

/**
 * Test double: returns queued answers in order and records every call. Never touches the network.
 *
 *   $jev = new FakeJev();
 *   $jev->push(['same' => ['type' => 'noul', 'noul' => 0.93]]);
 *   $jev->pushException(new JevUnavailableException('down'));
 */
final class FakeJev implements Jev
{
    /** @var list<array|\Throwable> */
    private array $queue = [];
    /** @var list<array{state: string|array, questions: array}> */
    public array $calls = [];

    public function __construct(
        private readonly string $model = 'fake-jev',
        private readonly ?string $answeringModel = 'fake-jev-1.0.0',
    ) {
    }

    /** @param array<string, array> $answers question name => answer in Jev's wire form */
    public function push(array $answers): self
    {
        $this->queue[] = $answers;

        return $this;
    }

    public function pushException(\Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function evaluate(string|array $state, array $questions): Response
    {
        $this->calls[] = ['state' => $state, 'questions' => $questions];

        $next = array_shift($this->queue) ?? throw new JevException('FakeJev: no queued response');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return new Response(
            answers: array_map(static fn (array $a) => Answer::fromWire($a), $next),
            model: $this->answeringModel,
            driver: 'fake',
            inputTokens: 0,
            outputTokens: 0,
            latencyMs: 0,
            attempts: 1,
            requestId: null,
            raw: ['answers' => $next],
        );
    }
}
