<?php

namespace Sentiment\Tests;

use PHPUnit\Framework\TestCase;
use Sentiment\Analyzer;
use Sentiment\Exceptions\InvalidLexiconTermException;
use Sentiment\SentimentResult;

final class NewApiTest extends TestCase
{
    private static ?Analyzer $shared = null;

    private static function analyzer(): Analyzer
    {
        return self::$shared ??= new Analyzer();
    }

    public function testAnalyzeReturnsASentimentResult(): void
    {
        $this->assertInstanceOf(SentimentResult::class, self::analyzer()->analyze('this is good'));
    }

    public function testAnalyzeMatchesGetSentiment(): void
    {
        $analyzer = self::analyzer();
        $legacy = $analyzer->getSentiment('this is good');
        $result = $analyzer->analyze('this is good');

        $this->assertSame($legacy['compound'], $result->compound());
        $this->assertSame($legacy['pos'], $result->positive());
    }

    public function testAnalyzeManyPreservesStringKeys(): void
    {
        $results = self::analyzer()->analyzeMany([
            'first' => 'This is amazing!',
            'second' => 'This product is terrible.',
        ]);

        $this->assertSame(['first', 'second'], array_keys($results));
        $this->assertTrue($results['first']->isPositive());
        $this->assertTrue($results['second']->isNegative());
    }

    public function testAnalyzeManyPreservesSparseIntegerKeys(): void
    {
        $results = self::analyzer()->analyzeMany([5 => 'good', 9 => 'terrible']);

        $this->assertSame([5, 9], array_keys($results));
    }

    public function testAnalyzeManyAcceptsATraversable(): void
    {
        $results = self::analyzer()->analyzeMany(new \ArrayIterator(['a' => 'good']));

        $this->assertSame(['a'], array_keys($results));
    }

    public function testAnalyzeManyOnEmptyInput(): void
    {
        $this->assertSame([], self::analyzer()->analyzeMany([]));
    }

    public function testWithLexiconIsImmutable(): void
    {
        $original = new Analyzer();
        $before = $original->getSentiment('it was slaps')['compound'];

        $modified = $original->withLexicon(['slaps' => 2.2]);

        $this->assertNotSame($original, $modified, 'withLexicon() must return a new instance');
        $this->assertSame(
            $before,
            $original->getSentiment('it was slaps')['compound'],
            'the receiver must be unchanged'
        );
        $this->assertGreaterThan($before, $modified->getSentiment('it was slaps')['compound']);
    }

    public function testWithLexiconLastWriteWins(): void
    {
        $analyzer = (new Analyzer())
            ->withLexicon(['slaps' => 2.2])
            ->withLexicon(['slaps' => -2.2]);

        $this->assertLessThan(0, $analyzer->analyze('it was slaps')->compound());
    }

    public function testWithLexiconLowercasesKeys(): void
    {
        $upper = (new Analyzer())->withLexicon(['SNAPPY' => 1.8]);
        $lower = (new Analyzer())->withLexicon(['snappy' => 1.8]);

        $this->assertSame(
            $lower->analyze('it was snappy')->compound(),
            $upper->analyze('it was snappy')->compound()
        );
    }

    public function testWithLexiconRejectsMultiWordTerms(): void
    {
        // Routing these into the idiom table would work only in some positions,
        // because that matcher has known defects. Fail loudly instead.
        $this->expectException(InvalidLexiconTermException::class);
        $this->expectExceptionMessageMatches('/Multi-word term/');

        (new Analyzer())->withLexicon(['cut the mustard' => 3.0]);
    }

    public function testWithLexiconRejectsNonNumericValues(): void
    {
        $this->expectException(InvalidLexiconTermException::class);
        $this->expectExceptionMessageMatches('/must be numeric/');

        (new Analyzer())->withLexicon(['clunky' => 'very bad']);
    }

    public function testWithLexiconAcceptsNumericStrings(): void
    {
        // README examples pass strings like '-1.5'; these stay valid.
        $analyzer = (new Analyzer())->withLexicon(['rubbish' => '-1.5']);

        $this->assertLessThan(0, $analyzer->analyze('it was rubbish')->compound());
    }

    public function testLegacyUpdateLexiconStaysLenientWhileWithLexiconIsStrict(): void
    {
        // The BC contract freezes updateLexicon()'s coercion. The new API is
        // allowed to be strict; the old one is not allowed to change.
        $legacy = new Analyzer();
        $legacy->updateLexicon(['clunky' => 'very bad']);
        $this->assertSame(0.0, $legacy->getSentiment('it was clunky')['compound']);

        $this->expectException(InvalidLexiconTermException::class);
        (new Analyzer())->withLexicon(['clunky' => 'very bad']);
    }

    public function testCloneDoesNotShareTransientState(): void
    {
        $original = new Analyzer();
        $original->getSentiment('THIS IS GREAT');

        $clone = $original->withLexicon(['slaps' => 2.2]);

        // A shared SentiText would leak the previous call's caps-differential
        // flag into the clone's first scoring run.
        $this->assertSame(
            (new Analyzer())->withLexicon(['slaps' => 2.2])->getSentiment('it was slaps'),
            $clone->getSentiment('it was slaps')
        );
    }
}
