<?php

namespace Sentiment\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sentiment\Analyzer;
use Sentiment\Exceptions\InvalidLexiconException;

/**
 * Pins the frozen backward-compatibility surface documented in MIGRATION.md.
 *
 * These are the guarantees v2 must not break. Unlike CharacterizationTest,
 * which pins numbers, this pins SHAPE: return keys, mutation semantics, and
 * constructor behaviour that existing installations depend on.
 */
final class ApiContractTest extends TestCase
{
    public function testGetSentimentReturnsFrozenKeysInOrder(): void
    {
        $result = (new Analyzer())->getSentiment('This is good.');

        // Key order is part of the contract: callers use list()/array destructuring
        // and print_r output appears in downstream test fixtures.
        $this->assertSame(['neg', 'neu', 'pos', 'compound'], array_keys($result));
    }

    public function testGetSentimentReturnsPlainArrayNotAnObject(): void
    {
        // getSentiment() must NOT return SentimentResult, and SentimentResult
        // must NOT implement ArrayAccess — bridging the two shapes is where
        // subtle breakage hides. Assert the DECLARED
        // return type rather than the runtime value — that is what callers
        // and static analysis actually depend on, and a change to an object
        // type would be caught here even if it were array-like at runtime.
        $returnType = (new ReflectionClass(Analyzer::class))
            ->getMethod('getSentiment')
            ->getReturnType();

        $this->assertNotNull($returnType, 'getSentiment() must declare a return type');
        $this->assertSame('array', (string) $returnType);
    }

    public function testGetSentimentValuesAreNumeric(): void
    {
        foreach ((new Analyzer())->getSentiment('This is good.') as $key => $value) {
            $this->assertIsNumeric($value, "Score '{$key}' must stay numeric");
        }
    }

    public function testEmptyInputReturnsNeutralZeroes(): void
    {
        $this->assertSame(
            ['neg' => 0.0, 'neu' => 0.0, 'pos' => 0.0, 'compound' => 0.0],
            (new Analyzer())->getSentiment('')
        );
    }

    public function testUpdateLexiconLowercasesKeys(): void
    {
        $upper = new Analyzer();
        $upper->updateLexicon(['SNAPPY' => 1.8]);

        $lower = new Analyzer();
        $lower->updateLexicon(['snappy' => 1.8]);

        $this->assertSame(
            $lower->getSentiment('it was snappy'),
            $upper->getSentiment('it was snappy'),
            'updateLexicon() must keep lowercasing keys'
        );
    }

    public function testUpdateLexiconCoercesNonNumericToZero(): void
    {
        $analyzer = new Analyzer();
        $analyzer->updateLexicon(['clunky' => 'not-a-number']);

        // Quirk frozen by the BC contract: non-numeric values become 0 rather
        // than throwing. The v2 API throws instead; the legacy path must not.
        $this->assertSame(0.0, $analyzer->getSentiment('it was clunky')['compound']);
    }

    public function testUpdateLexiconIgnoresNonArrayInput(): void
    {
        $analyzer = new Analyzer();
        $before = $analyzer->getSentiment('it was good');

        $analyzer->updateLexicon('not an array');

        $this->assertSame($before, $analyzer->getSentiment('it was good'));
    }

    public function testUpdateLexiconMutatesInPlace(): void
    {
        // Legacy semantics are mutating. v2's withLexicon() is immutable, but
        // this method must not change behaviour.
        // NB: avoid any phrasing containing "so"/"this" — _never_check zeroes
        // those, which would mask the mutation entirely.
        $analyzer = new Analyzer();
        $before = $analyzer->getSentiment('it was good')['compound'];

        $analyzer->updateLexicon(['good' => -3.0]);

        $this->assertGreaterThan(0, $before);
        $this->assertLessThan(0, $analyzer->getSentiment('it was good')['compound']);
    }

    public function testConstructorAcceptsTwoLexiconPathArguments(): void
    {
        $constructor = (new ReflectionClass(Analyzer::class))->getConstructor();

        $this->assertSame(2, $constructor->getNumberOfParameters());
        $this->assertSame('lexicon_file', $constructor->getParameters()[0]->getName());
        $this->assertSame('emoji_lexicon', $constructor->getParameters()[1]->getName());
        $this->assertTrue($constructor->getParameters()[0]->isOptional());
        $this->assertTrue($constructor->getParameters()[1]->isOptional());
    }

    public function testConstructorResolvesPathsRelativeToSrcDirectory(): void
    {
        // Quirk frozen by the BC contract: paths are resolved against __DIR__ of
        // Analyzer.php, so callers pass paths relative to src/. Arguably a bug —
        // it rewrites absolute paths — but installations depend on it.
        $explicit = new Analyzer(
            'Lexicons/vader_sentiment_lexicon.txt',
            'Lexicons/emoji_utf8_lexicon.txt'
        );

        $this->assertSame(
            (new Analyzer())->getSentiment('This is good.'),
            $explicit->getSentiment('This is good.')
        );
    }

    public function testMissingLexiconThrowsInsteadOfKillingTheProcess(): void
    {
        // v1 called die() here, terminating the host application. v2 throws.
        // Accepted break, documented in MIGRATION.md.
        $this->expectException(InvalidLexiconException::class);

        new Analyzer('Lexicons/does-not-exist.txt');
    }

    public function testMissingEmojiLexiconThrows(): void
    {
        $this->expectException(InvalidLexiconException::class);

        new Analyzer('Lexicons/vader_sentiment_lexicon.txt', 'Lexicons/nope.txt');
    }

    public function testNoRuntimeCodePathPerformsNetworkIo(): void
    {
        // Inference must never require a network connection: the package is
        // local and deterministic by design.
        $forbidden = [
            'file_get_contents(http', 'curl_init', 'curl_exec', 'fsockopen',
            'stream_socket_client', 'fopen(http', 'http_get', 'socket_create',
        ];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src')
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = str_replace([' ', "\t"], '', file_get_contents($file->getPathname()));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    str_replace(' ', '', $needle),
                    $source,
                    "Network call '{$needle}' found in {$file->getFilename()}"
                );
            }
        }
    }

}
