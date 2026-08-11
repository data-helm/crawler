# Contributing to DataHelm Crawler

Thanks for considering a contribution! This package is a Scrapy-style web crawler for
Laravel, and contributions of all sizes are welcome — from a doc typo fix to a new
field detector.

## Getting started

1. Fork the repo and clone your fork:

   ```bash
   git clone git@github.com:<your-username>/crawler.git
   cd crawler
   ```

2. Install dependencies:

   ```bash
   composer install
   ```

3. Run the test suite to confirm your environment is set up correctly:

   ```bash
   composer test
   ```

   This runs PHPUnit against `phpunit.xml`. All tests should pass before you start
   making changes.

## Making a change

1. Create a branch off `main` with a short, descriptive name
   (e.g. `fix/pagination-detection`, `docs/endpoint-hints`).
2. Make your change, keeping it focused — one logical change per PR is easier to
   review and merge.
3. Add or update tests under `tests/` for any behavior change. Detectors, output
   sinks, and pipeline processors should all have direct unit test coverage; see
   `tests/Detection/ImageFieldDetectorTest.php` for the expected shape of a
   detector test.
4. Update the README if you're adding a new config key, CLI flag, or public
   behavior — undocumented features are hard for users (and future contributors)
   to discover.
5. Run `composer test` again and make sure everything still passes.

## Coding style

- Match the style already used in the surrounding file — this codebase favors small,
  well-documented, single-purpose classes (see `src/Detection/` for the pattern).
- Public methods and non-obvious logic get a docblock explaining *why*, not just
  *what* — especially for heuristics (regexes, thresholds, marker lists) where the
  reasoning isn't obvious from the code alone.
- Prefer `final` classes and constructor-promoted readonly properties where the
  rest of the file already does.

## Submitting your PR

- Push your branch to your fork and open a pull request against `main`.
- Describe what changed and why in the PR description. If it fixes an open issue,
  reference it (e.g. `Fixes #123`).
- CI (PHPUnit on PHP 8.3 and 8.4) must pass before a PR can be merged.
- Be responsive to review feedback — small, iterative fixes are easier for everyone
  than one big rewrite.

## Reporting bugs / requesting features

Please use the issue templates when opening a new issue — they help us get the
information needed to act on it quickly. See `.github/ISSUE_TEMPLATE/`.

## Questions

If anything here is unclear, open an issue — clarifying this guide is itself a
welcome contribution.
