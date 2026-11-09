<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Tests;

use Ksilyanov\Jev\Question;
use PHPUnit\Framework\TestCase;

final class QuestionTest extends TestCase
{
    private static function encode(Question $q): string
    {
        return json_encode($q->toWire(), JSON_UNESCAPED_UNICODE);
    }

    public function test_noul_criteria_are_optional_each(): void
    {
        $this->assertSame('{"type":"noul","instructions":"Urgent?"}', self::encode(Question::noul('Urgent?')));
        $this->assertSame(
            '{"type":"noul","instructions":"Urgent?","criteria":{"true":"Time-sensitive"}}',
            self::encode(Question::noul('Urgent?', whenTrue: 'Time-sensitive')),
        );
    }

    public function test_choice_options_always_encode_as_object(): void
    {
        $this->assertSame('{"type":"choice","instructions":"Pick","criteria":{"0":"a","1":"b"}}', self::encode(Question::choice('Pick', ['a', 'b'])));
        $this->assertSame('{"type":"choice","instructions":"Pick","criteria":{}}', self::encode(Question::choice('Pick', [])));
    }

    public function test_score_levels_always_encode_as_list(): void
    {
        $this->assertSame('{"type":"score","instructions":"Rate","criteria":["low","high"]}', self::encode(Question::score('Rate', [3 => 'low', 7 => 'high'])));
    }

    public function test_structured_instructions_pass_through(): void
    {
        $q = Question::noul(['candidate' => ['name' => 'John'], 'question' => 'Same person as `candidate`?']);

        $this->assertSame('{"type":"noul","instructions":{"candidate":{"name":"John"},"question":"Same person as `candidate`?"}}', self::encode($q));
    }
}
