# ci-summary

A composite action that writes a short [job summary](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-commands#adding-a-job-summary)
for a PHPUnit, phpcs or PHPStan report, so the result of a job can be read on the run page without opening the log.

```markdown
### ✅ PHPUnit — PHP 8.4 / mysql

4092 tests, 12345 assertions, 0 failures, 3 skipped · 1m 23s · coverage 89.1% lines, 80.2% methods, 61.0% classes
```

When something fails, a table follows with the location and the message of each problem
(at most 50 rows). Skipped tests and long failure messages, such as assertion diffs, are in collapsed sections.

## Usage

Run the action after the step that writes the report, with `if: ${{ !cancelled() }}` so it also runs when that step fails.
PHP must already be set up in the job.

```yaml
- name: Run Tests
  run: vendor/bin/phpunit --log-junit junit.xml --coverage-clover coverage.xml

- name: Test summary
  if: ${{ !cancelled() }}
  uses: pieceofcake2/.github/actions/ci-summary@master
  with:
    type: junit
    title: PHPUnit — PHP ${{ matrix.php-version }} / ${{ matrix.db-type }}
    file: junit.xml
    coverage: coverage.xml
```

```yaml
- name: Check PHP code style
  run: vendor/bin/phpcs --report-full --report-checkstyle=phpcs-report.xml

- name: PHPCS summary
  if: ${{ !cancelled() }}
  uses: pieceofcake2/.github/actions/ci-summary@master
  with:
    type: checkstyle
    title: PHPCS
    file: phpcs-report.xml
```

```yaml
- name: Run PHPStan
  run: vendor/bin/phpstan analyse --no-progress --error-format=checkstyle > phpstan-report.xml

- name: PHPStan summary
  if: ${{ !cancelled() }}
  uses: pieceofcake2/.github/actions/ci-summary@master
  with:
    type: phpstan
    title: PHPStan
    file: phpstan-report.xml
```

## Inputs

| Input | Required | Description |
| --- | --- | --- |
| `type` | yes | `junit` (PHPUnit `--log-junit`), `checkstyle` (phpcs `--report-checkstyle`) or `phpstan` (`--error-format=json` or `checkstyle`) |
| `file` | yes | Path to the report |
| `title` | no | Heading, e.g. `PHPUnit — PHP 8.4 / mysql` |
| `coverage` | no | Clover report (PHPUnit `--coverage-clover`), for `junit` |

Paths in the summary are relative to the repository root. If the report is missing or cut off,
because an earlier step failed, the summary says so and the step still succeeds.
The summary is also printed in a collapsed group of the step's log.

The action only formats the report. Annotations on the pull request diff, such as those from
[cs2pr](https://github.com/staabm/annotate-pull-request-from-checkstyle), stay in their own step.

## Development

`summary.php` has no dependencies besides SimpleXML and JSON and runs on PHP 8.0 and later.
It can be run directly:

```shell
php actions/ci-summary/summary.php junit "PHPUnit" junit.xml coverage.xml
```

The tests compare the summary of each report in `tests/fixtures/` with `tests/expected/`:

```shell
php actions/ci-summary/tests/run.php            # run the tests
php actions/ci-summary/tests/run.php --update   # rewrite the expected files after an intended change
```
