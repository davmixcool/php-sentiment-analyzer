<?php

namespace Sentiment\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentiment\SentimentResult;

final class SentimentResultTest extends TestCase
{
    private static function make(float $compound): SentimentResult
    {
        return new SentimentResult(0.5, 0.2, 0.3, $compound);
    }

    public function testAccessorsReturnConstructorValues(): void
    {
        $result = new SentimentResult(0.746, 0.0, 0.254, 0.8316);

        $this->assertSame(0.746, $result->positive());
        $this->assertSame(0.0, $result->negative());
        $this->assertSame(0.254, $result->neutral());
        $this->assertSame(0.8316, $result->compound());
    }

    public function testFromScoresMapsLegacyKeys(): void
    {
        // The legacy shape uses neg/neu/pos; mixing these up would be silent
        // and catastrophic, so pin the mapping explicitly.
        $result = SentimentResult::fromScores([
            'neg' => 0.1,
            'neu' => 0.2,
            'pos' => 0.7,
            'compound' => 0.5,
        ]);

        $this->assertSame(0.1, $result->negative());
        $this->assertSame(0.2, $result->neutral());
        $this->assertSame(0.7, $result->positive());
        $this->assertSame(0.5, $result->compound());
    }

    public static function provideLabels(): array
    {
        return [
            'well above threshold' => [0.8316, 'positive'],
            'exactly at positive threshold' => [0.05, 'positive'],
            'just below positive threshold' => [0.0499, 'neutral'],
            'zero' => [0.0, 'neutral'],
            'just above negative threshold' => [-0.0499, 'neutral'],
            'exactly at negative threshold' => [-0.05, 'negative'],
            'well below threshold' => [-0.5423, 'negative'],
        ];
    }

    #[DataProvider('provideLabels')]
    public function testLabelBoundaries(float $compound, string $expected): void
    {
        $this->assertSame($expected, self::make($compound)->label());
    }

    public function testThresholdsAreTheVaderConventionAndPublic(): void
    {
        // Public so callers can reclassify without hardcoding. Changing these
        // silently reclassifies every result, so pin them.
        $this->assertSame(0.05, SentimentResult::POSITIVE_THRESHOLD);
        $this->assertSame(-0.05, SentimentResult::NEGATIVE_THRESHOLD);
    }

    public function testPredicatesAreMutuallyExclusive(): void
    {
        foreach ([0.8, 0.0, -0.8] as $compound) {
            $result = self::make($compound);

            $true = array_filter([
                $result->isPositive(),
                $result->isNeutral(),
                $result->isNegative(),
            ]);

            $this->assertCount(1, $true, "Exactly one predicate must hold for {$compound}");
        }
    }

    public function testToArrayUsesTheNewKeyNames(): void
    {
        // Deliberately different from getSentiment()'s frozen neg/neu/pos.
        $result = new SentimentResult(0.76, 0.0, 0.24, 0.81);

        $this->assertSame(
            ['positive' => 0.76, 'negative' => 0.0, 'neutral' => 0.24, 'compound' => 0.81, 'label' => 'positive'],
            $result->toArray()
        );
    }
}
