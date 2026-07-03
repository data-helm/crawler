<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Pagination configuration: a {@see PaginationStrategy} plus the CSS selector
 * that points at the page links (or the single "next" link).
 */
final class PaginationSelector
{
    public function __construct(
        public readonly PaginationStrategy $strategy,
        public readonly string $css = '',
    ) {
    }

    public static function none(): self
    {
        return new self(PaginationStrategy::NONE);
    }

    public static function fromArray(array $data): self
    {
        $strategy = (string) ($data['strategy'] ?? 'none');
        if ($strategy === 'load_more') {
            $strategy = PaginationStrategy::INFINITE_SCROLL->value;
        }

        return new self(
            PaginationStrategy::from($strategy),
            (string) ($data['css'] ?? ''),
        );
    }

    /**
     * @return array{strategy:string,css:string}
     */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy->value,
            'css'      => $this->css,
        ];
    }
}
