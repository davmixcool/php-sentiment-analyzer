<?php

namespace Sentiment\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentiment\Analyzer;

/**
 * Golden-master suite pinning the behaviour of the CURRENT implementation.
 *
 * Milestone 0 of the v2 PRD: this must run green against unmodified v1 before
 * any refactor begins, and must stay green through v2.0, which guarantees
 * byte-identical scores.
 *
 * A failure here means a score moved. That is a regression unless it is an
 * intentional, changelogged scoring change — and those are out of scope for
 * v2.0 entirely.
 *
 * Some pinned values encode known bugs (15 of 21 idioms do not fire). They are
 * pinned deliberately. See KNOWN-DIVERGENCES.md before "fixing" anything here.
 *
 * Regenerate with: composer baseline
 */
final class CharacterizationTest extends TestCase
{
    private static ?Analyzer $shared = null;

    /**
     * Built once: constructing an Analyzer parses two lexicon files, and doing
     * that 341 times is needlessly slow.
     */
    private static function sharedAnalyzer(): Analyzer
    {
        return self::$shared ??= new Analyzer();
    }

    public static function provideCases(): array
    {
        $path = __DIR__ . '/fixtures/baseline.json';

        if (!is_file($path)) {
            self::fail('Baseline fixture missing. Run: composer baseline');
        }

        $baseline = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($baseline as $key => $case) {
            $cases[$key] = [$key, $case];
        }

        return $cases;
    }

    #[DataProvider('provideCases')]
    public function testScoresMatchBaseline(string $key, array $case): void
    {
        if (isset($case['lexicon'])) {
            // updateLexicon() mutates, so lexicon cases need an isolated instance.
            $analyzer = new Analyzer();
            $analyzer->updateLexicon($case['lexicon']);
        } else {
            $analyzer = self::sharedAnalyzer();
        }

        $scores = $analyzer->getSentiment($case['text']);

        $actual = [
            'neg'      => sprintf('%.3f', $scores['neg']),
            'neu'      => sprintf('%.3f', $scores['neu']),
            'pos'      => sprintf('%.3f', $scores['pos']),
            'compound' => sprintf('%.4f', $scores['compound']),
        ];

        $expected = [
            'neg'      => $case['neg'],
            'neu'      => $case['neu'],
            'pos'      => $case['pos'],
            'compound' => $case['compound'],
        ];

        $this->assertSame(
            $expected,
            $actual,
            sprintf('Score drift on "%s" (text: %s)', $key, var_export($case['text'], true))
        );
    }

    public function testBaselineCoversEveryRuleTableEntry(): void
    {
        $keys = array_keys(self::provideCases());

        $sections = [];
        foreach ($keys as $key) {
            $sections[explode('/', $key)[0]] = true;
        }

        // Guards against a section being silently dropped from the generator.
        foreach ([
            'negation', 'booster', 'idiom', 'emoji', 'lexicon', 'caps',
            'punctuation', 'but_clause', 'least_never', 'never_check', 'kind_of', 'edge',
            'readme', 'readme_advanced', 'custom_lexicon',
        ] as $section) {
            $this->assertArrayHasKey($section, $sections, "Corpus section '{$section}' is missing");
        }

        $this->assertGreaterThan(300, count($keys), 'Corpus shrank unexpectedly');
    }
}
