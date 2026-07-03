<?php

namespace DataHelm\Crawler\Console;

use DataHelm\Crawler\Blueprint\HttpConfig;
use DataHelm\Crawler\Dom\Page;
use DataHelm\Crawler\Http\GuzzleHttpClient;
use DataHelm\Crawler\Console\Concerns\UsesCrawlerPrefix;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Interactive selector REPL — test CSS and XPath expressions against a live page.
 *
 * Usage:
 *   php artisan datahelm:scrap:shell https://www.megaleiloes.com.br/imoveis/apartamentos
 *
 * Once the page is loaded you can type:
 *   css div.card         → run CSS selector
 *   xpath //div[@id="x"] → run XPath expression
 *   attr a href          → extract an attribute from all matches
 *   html div.card        → show raw outer-HTML of first match
 *   links                → list all <a href> on the page
 *   images               → list all <img src> on the page
 *   info                 → show page title and byte size
 *   help                 → show this help
 *   quit / exit          → leave the shell
 *
 * The shell understands readline if the extension is available, giving history
 * via the UP arrow key. Falls back to plain fgets(STDIN) otherwise.
 */
class ShellCommand extends Command
{
    use UsesCrawlerPrefix;

    protected $signature = 'datahelm:scrap:shell
                            {url : Page URL to load and inspect}
                            {--timeout=15 : HTTP request timeout in seconds}
                            {--user-agent= : Custom User-Agent header}';

    protected $description = 'Open an interactive CSS/XPath selector shell against a live URL.';

    public function handle(): int
    {
        $url     = (string) $this->argument('url');
        $timeout = max(1, (int) ($this->option('timeout') ?? 15));
        $ua      = $this->option('user-agent');

        $this->line('');
        $this->line("<fg=cyan>DataHelm Selector Shell</>");
        $this->line('Fetching ' . $url . ' …');

        try {
            $config = is_string($ua) && $ua !== ''
                ? new HttpConfig(timeout: $timeout, userAgent: $ua)
                : new HttpConfig(timeout: $timeout);
            $http = new GuzzleHttpClient($config);
            $html = $http->get($url);
            $page = Page::fromHtml($url, $html);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch page: ' . $e->getMessage());

            return self::FAILURE;
        }

        $bytes   = strlen($html);
        $title   = $this->pageTitle($page->crawler());
        $this->line(sprintf('<fg=green>✓</> Loaded %d bytes — "%s"', $bytes, $title));
        $this->line('');
        $this->line('Commands: <fg=yellow>css</> <selector>  <fg=yellow>xpath</> <expr>  <fg=yellow>attr</> <selector> <attribute>');
        $this->line('          <fg=yellow>html</> <selector>  <fg=yellow>links</>  <fg=yellow>images</>  <fg=yellow>info</>  <fg=yellow>help</>  <fg=yellow>quit</>');
        $this->line('');

        $hasReadline = function_exists('readline');

        while (true) {
            $input = $this->readInput($hasReadline);
            if ($input === null) {
                break;
            }

            $input = trim($input);
            if ($input === '') {
                continue;
            }

            if ($hasReadline) {
                readline_add_history($input);
            }

            [$cmd, $rest] = $this->parseInput($input);

            match ($cmd) {
                'quit', 'exit', 'q' => $this->exitShell(),
                'css'               => $this->runSelector($page->crawler(), $rest, 'css'),
                'xpath'             => $this->runSelector($page->crawler(), $rest, 'xpath'),
                'attr'              => $this->runAttr($page->crawler(), $rest),
                'html'              => $this->runHtml($page->crawler(), $rest),
                'links'             => $this->runLinks($page->crawler()),
                'images'            => $this->runImages($page->crawler()),
                'info'              => $this->runInfo($url, $title, $bytes, $page->crawler()),
                'help', '?'         => $this->runHelp(),
                default             => $this->line('<fg=red>Unknown command.</> Type <fg=yellow>help</> for available commands.'),
            };
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function exitShell(): never
    {
        $this->line('Goodbye.');
        exit(0);
    }

    private function readInput(bool $hasReadline): ?string
    {
        if ($hasReadline) {
            $line = readline('<fg=cyan>datahelm></> ');

            return $line === false ? null : $line;
        }

        fwrite(STDOUT, 'datahelm> ');
        $line = fgets(STDIN);

        return $line === false ? null : $line;
    }

    /**
     * @return array{string, string}
     */
    private function parseInput(string $input): array
    {
        $parts = preg_split('/\s+/', $input, 2);
        $cmd   = strtolower(trim((string) ($parts[0] ?? '')));
        $rest  = trim((string) ($parts[1] ?? ''));

        return [$cmd, $rest];
    }

    private function runSelector(Crawler $crawler, string $selector, string $type): void
    {
        if ($selector === '') {
            $this->line('<fg=red>Usage:</> css <selector>  or  xpath <expr>');

            return;
        }

        try {
            $nodes = $type === 'xpath'
                ? $crawler->filterXPath($selector)
                : $crawler->filter($selector);

            $count = $nodes->count();
            $this->line(sprintf('<fg=green>→ %d match%s</>', $count, $count === 1 ? '' : 'es'));

            $nodes->each(function (Crawler $node, int $i): void {
                if ($i >= 5) {
                    return;
                }

                $tag  = $node->getNode(0)?->nodeName ?? '?';
                $text = $this->truncate($node->text('', true));
                $this->line(sprintf('  <fg=yellow>%d.</> [%s] %s', $i + 1, strtoupper($tag), $text));
            });

            if ($count > 5) {
                $this->line(sprintf('  … and %d more', $count - 5));
            }
        } catch (\Throwable $e) {
            $this->line('<fg=red>Error:</> ' . $e->getMessage());
        }
    }

    private function runAttr(Crawler $crawler, string $args): void
    {
        $parts    = preg_split('/\s+/', $args, 2);
        $selector = trim((string) ($parts[0] ?? ''));
        $attr     = trim((string) ($parts[1] ?? ''));

        if ($selector === '' || $attr === '') {
            $this->line('<fg=red>Usage:</> attr <selector> <attribute>');

            return;
        }

        try {
            $nodes = $crawler->filter($selector);
            $count = $nodes->count();
            $this->line(sprintf('<fg=green>→ %d match%s (attribute: %s)</>', $count, $count === 1 ? '' : 'es', $attr));

            $nodes->each(function (Crawler $node, int $i) use ($attr): void {
                if ($i >= 10) {
                    return;
                }

                $value = $node->attr($attr) ?? '<em>null</em>';
                $this->line(sprintf('  <fg=yellow>%d.</> %s', $i + 1, $this->truncate($value)));
            });

            if ($count > 10) {
                $this->line(sprintf('  … and %d more', $count - 10));
            }
        } catch (\Throwable $e) {
            $this->line('<fg=red>Error:</> ' . $e->getMessage());
        }
    }

    private function runHtml(Crawler $crawler, string $selector): void
    {
        if ($selector === '') {
            $this->line('<fg=red>Usage:</> html <selector>');

            return;
        }

        try {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                $this->line('<fg=yellow>No match.</>');

                return;
            }

            $outerHtml = $node->outerHtml();
            $lines     = explode("\n", $outerHtml);
            foreach (array_slice($lines, 0, 20) as $line) {
                $this->line('  ' . htmlspecialchars($line));
            }
            if (count($lines) > 20) {
                $this->line(sprintf('  … (%d more lines)', count($lines) - 20));
            }
        } catch (\Throwable $e) {
            $this->line('<fg=red>Error:</> ' . $e->getMessage());
        }
    }

    private function runLinks(Crawler $crawler): void
    {
        $links = [];
        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links): void {
                $href = $node->attr('href');
                if (is_string($href) && $href !== '' && $href !== '#') {
                    $links[] = $href;
                }
            });
        } catch (\Throwable) {
        }

        $links = array_values(array_unique($links));
        $this->line(sprintf('<fg=green>→ %d unique link%s</>', count($links), count($links) === 1 ? '' : 's'));
        foreach (array_slice($links, 0, 15) as $i => $link) {
            $this->line(sprintf('  <fg=yellow>%d.</> %s', $i + 1, $this->truncate($link, 120)));
        }
        if (count($links) > 15) {
            $this->line(sprintf('  … and %d more', count($links) - 15));
        }
    }

    private function runImages(Crawler $crawler): void
    {
        $srcs = [];
        try {
            $crawler->filter('img[src]')->each(function (Crawler $node) use (&$srcs): void {
                $src = $node->attr('src');
                if (is_string($src) && $src !== '') {
                    $srcs[] = $src;
                }
            });
        } catch (\Throwable) {
        }

        $srcs = array_values(array_unique($srcs));
        $this->line(sprintf('<fg=green>→ %d unique image%s</>', count($srcs), count($srcs) === 1 ? '' : 's'));
        foreach (array_slice($srcs, 0, 10) as $i => $src) {
            $this->line(sprintf('  <fg=yellow>%d.</> %s', $i + 1, $this->truncate($src, 120)));
        }
        if (count($srcs) > 10) {
            $this->line(sprintf('  … and %d more', count($srcs) - 10));
        }
    }

    private function runInfo(string $url, string $title, int $bytes, Crawler $crawler): void
    {
        $h1 = '';
        try {
            $h1 = trim($crawler->filter('h1')->first()->text('', true));
        } catch (\Throwable) {
        }

        $this->line('<fg=cyan>Page info</>');
        $this->line('  URL   : ' . $url);
        $this->line('  Title : ' . $title);
        if ($h1 !== '') {
            $this->line('  H1    : ' . $h1);
        }
        $this->line(sprintf('  Size  : %s KB (%d bytes)', number_format($bytes / 1024, 1), $bytes));
    }

    private function runHelp(): void
    {
        $this->line('<fg=cyan>Available commands:</>');
        $this->line('  <fg=yellow>css</> <selector>           Run a CSS selector');
        $this->line('  <fg=yellow>xpath</> <expression>       Run an XPath expression');
        $this->line('  <fg=yellow>attr</> <selector> <attr>   Extract an attribute from matches');
        $this->line('  <fg=yellow>html</> <selector>          Show outer HTML of first match');
        $this->line('  <fg=yellow>links</>                    List all unique hrefs on the page');
        $this->line('  <fg=yellow>images</>                   List all unique image srcs');
        $this->line('  <fg=yellow>info</>                     Show page title and size');
        $this->line('  <fg=yellow>help</>  or  <fg=yellow>?</>              Show this help');
        $this->line('  <fg=yellow>quit</>  or  <fg=yellow>exit</>           Exit the shell');
    }

    private function pageTitle(Crawler $crawler): string
    {
        try {
            return trim($crawler->filter('title')->first()->text('', true));
        } catch (\Throwable) {
            return '(no title)';
        }
    }

    private function truncate(string $value, int $max = 80): string
    {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 1) . '…';
    }
}
