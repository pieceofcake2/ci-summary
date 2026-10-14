<?php
/**
 * Compares the summary of each fixture with tests/expected/<case>.md.
 *
 *   php tests/run.php            run the tests
 *   php tests/run.php --update   rewrite the expected files after an intended change
 */

require dirname(__DIR__) . '/summary.php';

chdir(dirname(__DIR__));
putenv('GITHUB_WORKSPACE=/home/runner/work/demo/demo');

$fixtures = 'tests/fixtures/';
$cases = [
    'junit-pass' => ['junit', 'PHPUnit — PHP 8.0 / mysql', $fixtures . 'phpunit9-pass.xml', $fixtures . 'clover.xml'],
    'junit-failures' => ['junit', 'PHPUnit — PHP 8.4 / pgsql', $fixtures . 'phpunit9-failures.xml'],
    'junit-generic' => ['junit', '', $fixtures . 'junit-generic.xml', $fixtures . 'missing-clover.xml'],
    'junit-missing' => ['junit', 'PHPUnit', $fixtures . 'missing.xml'],
    'junit-truncated' => ['junit', 'PHPUnit', $fixtures . 'junit-truncated.xml'],
    'checkstyle-clean' => ['checkstyle', 'PHPCS', $fixtures . 'phpcs-clean.xml'],
    'checkstyle-errors' => ['checkstyle', 'PHPCS', $fixtures . 'phpcs-errors.xml'],
    'checkstyle-warnings' => ['checkstyle', 'PHPCS', $fixtures . 'phpcs-warnings.xml'],
    'phpstan-clean' => ['phpstan', 'PHPStan', $fixtures . 'phpstan-clean.json'],
    'phpstan-errors-json' => ['phpstan', 'PHPStan', $fixtures . 'phpstan-errors.json'],
    'phpstan-errors-checkstyle' => ['phpstan', 'PHPStan', $fixtures . 'phpstan-errors.xml'],
    'phpstan-general-error' => ['phpstan', 'PHPStan', $fixtures . 'phpstan-general-error.json'],
    'phpstan-missing' => ['phpstan', 'PHPStan', $fixtures . 'missing.json'],
];

$update = in_array('--update', $argv, true);
$failed = 0;
foreach ($cases as $name => $args) {
    $expectedFile = "tests/expected/{$name}.md";
    $actual = summary($args);
    if ($update) {
        file_put_contents($expectedFile, $actual);
    }
    $expected = is_file($expectedFile) ? file_get_contents($expectedFile) : '';
    if ($actual === $expected) {
        echo "ok   {$name}\n";
        continue;
    }
    $failed++;
    echo "FAIL {$name}\n--- expected\n{$expected}\n+++ actual\n{$actual}\n";
}

// Long values are cut and Markdown cannot break out of a table cell.
$checks = [
    'cell trims long text' => cell(str_repeat('é', 300)) === str_repeat('é', MAX_CELL - 1) . '…',
    'cell keeps short text' => cell("a\n  b") === 'a b',
    'cell escapes pipes and code' => cell('a|b `c`') === 'a\\|b \\`c\\`',
    'code escapes pipes' => code('a|b`c') === "`a\\|b'c`",
    'fence is longer than the content' => str_starts_with(codeBlock("````\nx"), "`````\n"),
    'duration' => [duration(5.04), duration(83.4), duration(3725)] === ['5.0s', '1m 23s', '1h 02m'],
];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $failed += $ok ? 0 : 1;
}

exit($failed > 0 ? 1 : 0);
