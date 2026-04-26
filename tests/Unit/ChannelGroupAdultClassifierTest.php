<?php

namespace Tests\Unit;

use App\Support\ChannelGroupAdultClassifier;
use PHPUnit\Framework\TestCase;

class ChannelGroupAdultClassifierTest extends TestCase
{
    public function test_it_matches_adult_keywords(): void
    {
        $this->assertTrue(ChannelGroupAdultClassifier::isAdult('Adults (18+)'));
        $this->assertTrue(ChannelGroupAdultClassifier::isAdult('24/7 Adult'));
        $this->assertTrue(ChannelGroupAdultClassifier::isAdult('Playboy XXX'));
    }

    public function test_it_ignores_known_false_positives(): void
    {
        $this->assertFalse(ChannelGroupAdultClassifier::isAdult('Adult Swim'));
        $this->assertFalse(ChannelGroupAdultClassifier::isAdult('Family Entertainment'));
    }
}
