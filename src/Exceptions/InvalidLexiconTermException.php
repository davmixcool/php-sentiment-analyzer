<?php

namespace Sentiment\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a term passed to Analyzer::withLexicon() cannot be used.
 *
 * The legacy Analyzer::updateLexicon() never throws — it lowercases keys and
 * coerces non-numeric values to 0. That behaviour is frozen by the backward
 * compatibility contract. The new API is allowed to be strict; the old one is
 * not allowed to change.
 */
class InvalidLexiconTermException extends InvalidArgumentException
{
    public static function multiWord(string $term): self
    {
        return new self(sprintf(
            'Multi-word term "%s" is not supported. Sentiment terms must be single '
            . 'words. Idioms are matched against a separate table whose matcher has '
            . 'known defects (see KNOWN-DIVERGENCES.md section 2), so routing '
            . 'multi-word terms there would silently work only in some positions.',
            $term
        ));
    }

    public static function nonNumeric(string $term, mixed $value): self
    {
        return new self(sprintf(
            'Valence for term "%s" must be numeric, %s given.',
            $term,
            get_debug_type($value)
        ));
    }
}
