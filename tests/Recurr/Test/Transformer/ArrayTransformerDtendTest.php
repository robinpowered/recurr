<?php

namespace Recurr\Test\Transformer;

use Recurr\Rule;
use Recurr\Transformer\ArrayTransformerConfig;

class ArrayTransformerDtendTest extends ArrayTransformerBase
{
    public function testDtend()
    {
        $rule = new Rule(
            'FREQ=MONTHLY;COUNT=3;DTEND=20140316T040000',
            new \DateTime('2014-03-14 04:00:00')
        );

        $computed = $this->transformer->transform($rule);

        $this->assertCount(3, $computed);
        $this->assertEquals(new \DateTime('2014-03-14 04:00:00'), $computed[0]->getStart());
        $this->assertEquals(new \DateTime('2014-03-16 04:00:00'), $computed[0]->getEnd());
        $this->assertEquals(new \DateTime('2014-04-14 04:00:00'), $computed[1]->getStart());
        $this->assertEquals(new \DateTime('2014-04-16 04:00:00'), $computed[1]->getEnd());
        $this->assertEquals(new \DateTime('2014-05-14 04:00:00'), $computed[2]->getStart());
        $this->assertEquals(new \DateTime('2014-05-16 04:00:00'), $computed[2]->getEnd());
    }
}
