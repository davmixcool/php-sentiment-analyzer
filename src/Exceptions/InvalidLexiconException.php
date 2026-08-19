<?php

namespace Sentiment\Exceptions;

use RuntimeException;

/**
 * Thrown when a lexicon file cannot be read.
 *
 * Replaces the die() calls in v1, which terminated the host process — behaviour
 * a library should never impose on the application embedding it.
 */
class InvalidLexiconException extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self(sprintf('Cannot read lexicon file: %s', $path));
    }
}
