<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Tests;

use Ksilyanov\Jev\Exceptions\JevUnavailableException;
use Ksilyanov\Jev\Question;
use Ksilyanov\Jev\Testing\FakeJev;
use PHPUnit\Framework\TestCase;

final class FakeJevTest extends TestCase
{
    public function test_returns_queued_answers_then_exceptions_and_records_calls(): void
    {
        $jev = (new FakeJev(model: 'typesafe/jev'))
            ->push(['same' => ['type' => 'noul', 'noul' => 0.8]])
            ->pushException(new JevUnavailableException('down'));
        $questions = ['same' => Question::noul('Same event?')];

        $this->assertSame('typesafe/jev', $jev->model());
        $this->assertEqualsWithDelta(0.8, $jev->evaluate('a', $questions)->answer('same')->noul, 1e-9);

        try {
            $jev->evaluate('b', $questions);
            $this->fail('expected exception');
        } catch (JevUnavailableException) {
        }
        $this->assertSame(['a', 'b'], array_column($jev->calls, 'state'));
    }
}
