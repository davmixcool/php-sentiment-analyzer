<?php

namespace Sentiment;

/**
 * An immutable sentiment score for a single piece of text.
 *
 * Returned by Analyzer::analyze(). The legacy Analyzer::getSentiment() keeps
 * returning a plain array and is unaffected by this class — see MIGRATION.md.
 */
final class SentimentResult
{
    /**
     * Classification boundaries, following the VADER reference implementation
     * so labels are comparable with other ports.
     *
     * Public so callers can reclassify against their own boundaries without
     * hardcoding these numbers.
     */
    public const POSITIVE_THRESHOLD = 0.05;
    public const NEGATIVE_THRESHOLD = -0.05;

    public const LABEL_POSITIVE = 'positive';
    public const LABEL_NEUTRAL = 'neutral';
    public const LABEL_NEGATIVE = 'negative';

    public function __construct(
        private readonly float $positive,
        private readonly float $negative,
        private readonly float $neutral,
        private readonly float $compound,
    ) {
    }

    /**
     * Build from the legacy getSentiment() shape.
     *
     * @param array{neg: float|int|string, neu: float|int|string, pos: float|int|string, compound: float|int|string} $scores
     */
    public static function fromScores(array $scores): self
    {
        return new self(
            (float) $scores['pos'],
            (float) $scores['neg'],
            (float) $scores['neu'],
            (float) $scores['compound'],
        );
    }

    public function positive(): float
    {
        return $this->positive;
    }

    public function negative(): float
    {
        return $this->negative;
    }

    public function neutral(): float
    {
        return $this->neutral;
    }

    public function compound(): float
    {
        return $this->compound;
    }

    public function label(): string
    {
        if ($this->compound >= self::POSITIVE_THRESHOLD) {
            return self::LABEL_POSITIVE;
        }

        if ($this->compound <= self::NEGATIVE_THRESHOLD) {
            return self::LABEL_NEGATIVE;
        }

        return self::LABEL_NEUTRAL;
    }

    public function isPositive(): bool
    {
        return $this->label() === self::LABEL_POSITIVE;
    }

    public function isNegative(): bool
    {
        return $this->label() === self::LABEL_NEGATIVE;
    }

    public function isNeutral(): bool
    {
        return $this->label() === self::LABEL_NEUTRAL;
    }

    /**
     * NOTE: these keys intentionally differ from getSentiment()'s legacy
     * neg/neu/pos. The legacy shape is frozen and cannot be renamed; the new
     * one should read clearly. See MIGRATION.md.
     *
     * @return array{positive: float, negative: float, neutral: float, compound: float, label: string}
     */
    public function toArray(): array
    {
        return [
            'positive' => $this->positive,
            'negative' => $this->negative,
            'neutral' => $this->neutral,
            'compound' => $this->compound,
            'label' => $this->label(),
        ];
    }
}
