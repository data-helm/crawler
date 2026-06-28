<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * How the next pages of a listing are discovered.
 */
enum PaginationStrategy: string
{
    /** Single page, no pagination. */
    case NONE = 'none';

    /** A block of numbered page links is present (e.g. ul.pagination a). */
    case LINK_LIST = 'link_list';

    /** A single "next" link is followed iteratively until it disappears. */
    case NEXT_LINK = 'next_link';

    /**
     * Infinite scroll: the next batch is fetched from an endpoint (usually POST)
     * that returns an HTML fragment, paginated by an incrementing offset/page
     * parameter. Configured via the blueprint's infinite_scroll block.
     */
    case INFINITE_SCROLL = 'infinite_scroll';
}
