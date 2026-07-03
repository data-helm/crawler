<?php

namespace DataHelm\Crawler\Console\Concerns;

/**
 * Rewrites the leading "datahelm" token of a command's signature with the
 * configured command prefix (config('crawler.command_prefix')), so the whole
 * package can be re-branded without editing every command.
 *
 * The class must extend Illuminate\Console\Command and declare its $signature
 * using the literal default prefix "datahelm".
 *
 * @mixin \Illuminate\Console\Command
 */
trait UsesCrawlerPrefix
{
    public function __construct()
    {
        $prefix = (string) config('crawler.command_prefix', 'datahelm');

        if ($prefix !== '' && $prefix !== 'datahelm' && is_string($this->signature)) {
            $this->signature = preg_replace('/^datahelm\b/', $prefix, $this->signature, 1) ?? $this->signature;
        }

        parent::__construct();
    }
}
