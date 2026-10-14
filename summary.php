<?php
/**
 * Turns a CI report into a short Markdown job summary for GitHub Actions.
 *
 *   php summary.php junit      <title> <junit.xml> [<clover.xml>]
 *   php summary.php checkstyle <title> <phpcs-checkstyle.xml>
 *   php summary.php phpstan    <title> <phpstan.json | phpstan-checkstyle.xml>
 *
 * For junit, SUMMARY_ARTIFACT_NAME (and optionally SUMMARY_ARTIFACT_NOTE) adds a line
 * that points to an artifact of the run when tests failed, e.g. E2E screenshots.
 *
 * The Markdown is written to stdout. A missing or unreadable report gives a
 * short note instead of an error, so this script never fails a job by itself.
 *
 * No dependencies besides SimpleXML and JSON; runs on PHP 8.0 and later.
 */

const MAX_ROWS = 50;
const MAX_CELL = 200;
const MAX_DETAILS = 10;
const MAX_DETAIL_LINES = 60;
const ARTIFACT_NOTE = 'Screenshots, logs and other files of the failed tests are in the {artifact} artifact.';

/**
 * @param list<string> $args Command line arguments without the script name.
 */
function summary(array $args): string
{
    [$type, $title, $report, $coverage] = array_pad($args, 4, '');

    switch ($type) {
        case 'junit':
            return junitSummary($title ?: 'PHPUnit', $report, $coverage);
        case 'checkstyle':
            return checkstyleSummary($title ?: 'PHPCS', $report);
        case 'phpstan':
            return phpstanSummary($title ?: 'PHPStan', $report);
    }

    return "Usage: php summary.php <junit|checkstyle|phpstan> <title> <report> [<clover>]\n";
}

// ---------------------------------------------------------------------------
// PHPUnit (JUnit XML, optionally with a Clover coverage report)
// ---------------------------------------------------------------------------

function junitSummary(string $title, string $report, string $coverage): string
{
    $xml = loadXml($report);
    if ($xml === null) {
        return missingReport($title, $report) . artifactNote();
    }

    // PHPUnit nests suites (directory > class > data provider); the outermost ones hold the totals.
    $suites = $xml->getName() === 'testsuite' ? [$xml] : $xml->testsuite;
    $totals = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'warnings' => 0, 'skipped' => 0, 'time' => 0.0];
    $hasAssertions = false;
    foreach ($suites as $suite) {
        foreach ($totals as $key => $value) {
            $totals[$key] = $value + ($key === 'time' ? (float)$suite[$key] : (int)$suite[$key]);
        }
        // Only PHPUnit counts assertions; other JUnit producers (pytest, Jest) leave the attribute out.
        $hasAssertions = $hasAssertions || isset($suite['assertions']);
    }

    $problems = [];
    $skipped = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        foreach (['failure', 'error', 'warning'] as $kind) {
            foreach ($case->{$kind} as $node) {
                $problems[] = junitProblem($case, $node, $kind);
            }
        }
        if (isset($case->skipped)) {
            $skipped[] = [testName($case), trim((string)$case->skipped['message'] ?: (string)$case->skipped)];
        }
    }

    $failed = $totals['failures'] + $totals['errors'] + $totals['warnings'];
    $mark = $failed > 0 || $problems ? '❌' : ($totals['tests'] === 0 ? '⚠️' : '✅');

    $parts = [plural($totals['tests'], 'test')];
    if ($hasAssertions) {
        $parts[] = plural($totals['assertions'], 'assertion');
    }
    $parts[] = plural($totals['failures'], 'failure');
    if ($totals['errors'] > 0) {
        $parts[] = plural($totals['errors'], 'error');
    }
    if ($totals['warnings'] > 0) {
        $parts[] = plural($totals['warnings'], 'warning');
    }
    $parts[] = $totals['skipped'] . ' skipped';
    $line = implode(', ', $parts) . ' · ' . duration($totals['time']);
    $percentages = $coverage !== '' ? coverage($coverage) : null;
    if ($percentages !== null) {
        $line .= ' · coverage ' . $percentages;
    }

    $out = heading($mark, $title) . $line . "\n";
    if ($mark === '❌') {
        $out .= artifactNote();
    }

    if ($problems) {
        $rows = array_map(fn(array $p): array => [cell($p['test']) . secondLine(htmlCode($p['location'])), cell($p['short'])], $problems);
        $out .= "\n" . table(['Test', 'Message'], $rows);

        $long = array_filter($problems, fn(array $p): bool => $p['long']);
        if ($long) {
            $out .= "\n<details><summary>Full messages</summary>\n";
            foreach (array_slice($long, 0, MAX_DETAILS) as $p) {
                $out .= "\n**" . cell($p['test']) . "**\n\n" . codeBlock($p['full']);
            }
            $out .= more(count($long), MAX_DETAILS) . "\n</details>\n";
        }
    }

    if ($skipped) {
        $out .= "\n<details><summary>" . $totals['skipped'] . " skipped</summary>\n\n";
        foreach (array_slice($skipped, 0, MAX_ROWS) as [$test, $reason]) {
            $out .= '- ' . cell($test) . ($reason !== '' ? ' — ' . cell($reason) : '') . "\n";
        }
        $out .= more(count($skipped), MAX_ROWS) . "\n</details>\n";
    }

    return $out;
}

/**
 * PHPUnit writes "Class::test", the message, a blank line and the stack trace
 * into the element body; pytest and Jest write the message and a traceback.
 * Pick the message and the frame in the test file.
 *
 * @return array{test: string, location: string, short: string, full: string, long: bool}
 */
function junitProblem(SimpleXMLElement $case, SimpleXMLElement $node, string $kind): array
{
    $lines = explode("\n", str_replace("\r", '', trim(relativeText((string)$node))));
    if ($lines && preg_match('/^\S+::\S/', $lines[0]) && str_contains($lines[0], (string)$case['name'])) {
        array_shift($lines);
    }
    $trace = [];
    while ($lines && ($frame = stackFrame(end($lines))) !== null) {
        array_pop($lines);
        if ($frame !== '') {
            array_unshift($trace, $frame);
        }
    }

    $full = trim(implode("\n", $lines));
    $short = oneLine(relativeText((string)$node['message']));
    $head = $full;
    if ($short === '') {
        // Stop before an expected/actual diff; keep the lines that explain the failure.
        $head = preg_split('/\n--- Expected/', $full)[0];
        $short = oneLine($head);
    }
    // The full text is worth showing when the table cell cannot hold all of it (a diff, a long message).
    $long = $full !== '' && (oneLine($full) !== $short || strlen($short) > MAX_CELL);
    if (strlen($short) > MAX_CELL && str_contains($head, "\n")) {
        // A long message, e.g. the whole text of a page: the first line usually says what went wrong.
        $short = oneLine(strtok($head, "\n"));
    }
    // PHPUnit exports a string with "\n" before each line break; the cell is one line anyway.
    $short = str_replace('\\n ', ' ', $short);
    // "Facebook\WebDriver\Exception\NoSuchElementException: ..." is clear enough without the namespace.
    // An exception without a message, e.g. a WebDriver TimeoutException, keeps only its class name.
    $short = (string)preg_replace(['/^(?:[A-Za-z_]\w*\\\\)+(?=\w+:(?: |$))/', '/^(\w+):$/'], ['', '$1'], $short);
    if ($kind === 'warning') {
        $short = 'Warning: ' . $short;
    }

    $file = relativePath((string)$case['file']);
    $location = $file !== '' && (int)$case['line'] > 0 ? $file . ':' . (int)$case['line'] : $file;
    foreach ($trace as $frame) {
        if ($file !== '' && str_starts_with($frame, $file . ':')) {
            $location = $frame;
            break;
        }
    }
    if ($location === '') {
        // No file attribute (pytest, Jest): the first frame outside of dependencies.
        foreach ($trace as $frame) {
            if (!preg_match('~^node:|(^|/)(vendor|node_modules)/~', $frame)) {
                $location = $frame;
                break;
            }
        }
    }
    if ($location === '' && $trace) {
        $location = $trace[0];
    }

    return ['test' => testName($case), 'location' => $location, 'short' => $short, 'full' => $full, 'long' => $long];
}

/**
 * A stack frame at the end of a failure message as "path:line", '' for a blank line
 * or a frame without a path, or null for a line of the message itself.
 */
function stackFrame(string $line): ?string
{
    $line = trim($line);
    if ($line === '') {
        return '';
    }
    // PHPUnit: /path/to/FooTest.php:12
    if (preg_match('/^(\S*[\/\\\\]\S*:\d+)$/', $line, $m)) {
        return $m[1];
    }
    // pytest: tests/test_foo.py:12: AssertionError
    if (preg_match('/^(\S+\.\w+:\d+): \w[\w.]*$/', $line, $m)) {
        return $m[1];
    }
    // JavaScript: at fn (/path/to/foo.test.js:12:5), at /path/to/foo.test.js:12:5, at new Promise (<anonymous>)
    if (preg_match('/^at (?:.+ \((?:(.+?:\d+):\d+|<anonymous>|native)\)|(.+?:\d+):\d+)$/', $line, $m)) {
        return ($m[1] ?? '') . ($m[2] ?? '');
    }

    return null;
}

function testName(SimpleXMLElement $case): string
{
    $class = (string)($case['class'] ?: $case['classname']);
    $name = (string)$case['name'];
    if ($class === $name) {
        // Jest writes the full name into both attributes.
        return $name;
    }
    $class = (string)preg_replace('/^.*[\\\\.]/', '', $class);

    return ($class !== '' ? $class . '::' : '') . $name;
}

/**
 * Overall line, method and class coverage from a Clover report, e.g. "89.1% lines, 80.0% methods, 70.0% classes".
 */
function coverage(string $report): ?string
{
    $xml = loadXml($report);
    if ($xml === null || !isset($xml->project->metrics)) {
        return null;
    }
    $metrics = $xml->project->metrics;

    $classes = 0;
    $coveredClasses = 0;
    foreach ($xml->xpath('//class/metrics') ?: [] as $class) {
        if ((int)$class['methods'] > 0) {
            $classes++;
            $coveredClasses += (int)$class['coveredmethods'] === (int)$class['methods'] ? 1 : 0;
        }
    }

    return implode(', ', [
        percent((int)$metrics['coveredstatements'], (int)$metrics['statements']) . ' lines',
        percent((int)$metrics['coveredmethods'], (int)$metrics['methods']) . ' methods',
        percent($coveredClasses, $classes) . ' classes',
    ]);
}

// ---------------------------------------------------------------------------
// phpcs (Checkstyle XML)
// ---------------------------------------------------------------------------

function checkstyleSummary(string $title, string $report): string
{
    $xml = loadXml($report);
    if ($xml === null) {
        return missingReport($title, $report);
    }

    $rows = [];
    $errors = 0;
    $warnings = 0;
    $files = 0;
    foreach ($xml->file as $file) {
        $files += count($file->error) > 0 ? 1 : 0;
        foreach ($file->error as $error) {
            $isWarning = (string)$error['severity'] === 'warning';
            $isWarning ? $warnings++ : $errors++;
            $rows[] = [
                htmlCode(relativePath((string)$file['name']) . ':' . (int)$error['line']) . secondLine(rule((string)$error['source'])),
                cell(($isWarning ? 'Warning: ' : '') . (string)$error['message']),
            ];
        }
    }

    if (!$rows) {
        return heading('✅', $title) . "No coding standard violations.\n";
    }

    $line = plural($errors, 'error') . ', ' . plural($warnings, 'warning') . ' in ' . plural($files, 'file') . '.';

    return heading($errors > 0 ? '❌' : '⚠️', $title)
        . $line . "\n\n"
        . table(['Location', 'Message'], $rows)
        . "\nRun `vendor/bin/phpcbf` to fix the fixable ones.\n";
}

// ---------------------------------------------------------------------------
// PHPStan (--error-format=json, or --error-format=checkstyle)
// ---------------------------------------------------------------------------

function phpstanSummary(string $title, string $report): string
{
    $content = is_file($report) ? trim((string)file_get_contents($report)) : '';
    $rows = null;
    if (str_starts_with($content, '{')) {
        $rows = phpstanJsonRows($content);
    } elseif (str_starts_with($content, '<')) {
        $rows = phpstanCheckstyleRows($report);
    }
    if ($rows === null) {
        return missingReport($title, $report);
    }

    if (!$rows) {
        return heading('✅', $title) . "No errors.\n";
    }

    $files = count(array_unique(array_filter(array_column($rows, 'file'))));

    return heading('❌', $title)
        . plural(count($rows), 'error') . ' in ' . plural($files, 'file') . ".\n\n"
        . table(
            ['Location', 'Message'],
            array_map(fn(array $r): array => [
                htmlCode($r['file'] !== '' ? $r['file'] . ($r['line'] > 0 ? ':' . $r['line'] : '') : '') . secondLine(rule($r['identifier'])),
                cell($r['message']),
            ], $rows),
        );
}

/**
 * @return list<array{file: string, line: int, message: string, identifier: string}>|null
 */
function phpstanJsonRows(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    $rows = [];
    foreach ($data['files'] ?? [] as $path => $result) {
        foreach ($result['messages'] ?? [] as $message) {
            $rows[] = [
                'file' => relativePath((string)$path),
                'line' => (int)($message['line'] ?? 0),
                'message' => (string)($message['message'] ?? ''),
                'identifier' => (string)($message['identifier'] ?? ''),
            ];
        }
    }
    foreach ($data['errors'] ?? [] as $error) {
        $rows[] = ['file' => '', 'line' => 0, 'message' => (string)$error, 'identifier' => ''];
    }

    return $rows;
}

/**
 * @return list<array{file: string, line: int, message: string, identifier: string}>|null
 */
function phpstanCheckstyleRows(string $report): ?array
{
    $xml = loadXml($report);
    if ($xml === null) {
        return null;
    }
    $rows = [];
    foreach ($xml->file as $file) {
        foreach ($file->error as $error) {
            $rows[] = [
                'file' => relativePath((string)$file['name']),
                'line' => (int)$error['line'],
                'message' => (string)$error['message'],
                'identifier' => (string)$error['source'],
            ];
        }
    }

    return $rows;
}

// ---------------------------------------------------------------------------
// Markdown helpers
// ---------------------------------------------------------------------------

function heading(string $mark, string $title): string
{
    return '### ' . $mark . ' ' . cell($title) . "\n\n";
}

function missingReport(string $title, string $report): string
{
    return heading('⚠️', $title)
        . 'No readable report at ' . code(relativePath($report)) . '. An earlier step probably failed; see the job log.' . "\n";
}

/**
 * A line pointing to an artifact of the run, e.g. E2E screenshots, from SUMMARY_ARTIFACT_NAME and
 * SUMMARY_ARTIFACT_NOTE. Links to the run page when the GITHUB_* variables of a workflow run are set.
 */
function artifactNote(): string
{
    $name = trim((string)getenv('SUMMARY_ARTIFACT_NAME'));
    if ($name === '') {
        return '';
    }
    $artifact = code($name);
    $server = (string)getenv('GITHUB_SERVER_URL');
    $repository = (string)getenv('GITHUB_REPOSITORY');
    $runId = (string)getenv('GITHUB_RUN_ID');
    if ($server !== '' && $repository !== '' && preg_match('/^\d+$/', $runId)) {
        $url = rtrim($server, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $repository)))
            . '/actions/runs/' . $runId . '#artifacts';
        $artifact = '[' . $artifact . '](' . $url . ')';
    }
    $note = cell(trim((string)getenv('SUMMARY_ARTIFACT_NOTE')) ?: ARTIFACT_NOTE, 500);

    return "\n" . (str_contains($note, '{artifact}') ? str_replace('{artifact}', $artifact, $note) : $note . ' ' . $artifact) . "\n";
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows Cells that are already escaped.
 */
function table(array $headers, array $rows): string
{
    $out = '| ' . implode(' | ', $headers) . " |\n|" . str_repeat(' --- |', count($headers)) . "\n";
    foreach (array_slice($rows, 0, MAX_ROWS) as $row) {
        $out .= '| ' . implode(' | ', $row) . " |\n";
    }

    return $out . more(count($rows), MAX_ROWS);
}

function more(int $count, int $shown): string
{
    return $count > $shown ? "\n…and " . ($count - $shown) . " more. See the job log.\n" : '';
}

/**
 * Plain text for a table cell or a list item: one line, trimmed, Markdown and HTML escaped.
 */
function cell(string $text, int $max = MAX_CELL): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if (preg_match('/^.{' . ($max - 1) . '}(?=.{2})/su', $text, $match)) {
        $text = rtrim($match[0]) . '…';
    }

    return strtr($text, [
        '\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_', '~' => '\\~',
        '[' => '\\[', ']' => '\\]', '|' => '\\|', '<' => '&lt;', '>' => '&gt;', '&' => '&amp;',
    ]);
}

/**
 * Inline code, safe inside a table cell.
 */
function code(string $text): string
{
    $text = trim(str_replace(['`', "\n", "\r"], ["'", ' ', ''], $text));

    return $text === '' ? '' : '`' . str_replace('|', '\\|', $text) . '`';
}

/**
 * Inline code as HTML, for a cell that holds more than one line.
 */
function htmlCode(string $text): string
{
    $text = cell($text);

    return $text === '' ? '' : '<code>' . $text . '</code>';
}

/**
 * A rule or identifier under the location, in small type.
 */
function rule(string $text): string
{
    $text = cell($text);

    return $text === '' ? '' : '<sub>' . $text . '</sub>';
}

/**
 * Appends a second line to a table cell.
 */
function secondLine(string $html): string
{
    return $html === '' ? '' : '<br>' . $html;
}

function oneLine(string $text): string
{
    return trim((string)preg_replace('/\s+/u', ' ', $text));
}

function codeBlock(string $text): string
{
    $lines = explode("\n", $text);
    if (count($lines) > MAX_DETAIL_LINES) {
        $lines = array_slice($lines, 0, MAX_DETAIL_LINES);
        $lines[] = '…';
    }
    // The fence must be longer than any run of backticks inside the block.
    preg_match_all('/`+/', $text, $runs);
    $fence = str_repeat('`', max(3, ...array_map('strlen', $runs[0] ?: [''])) + 1);

    return $fence . "\n" . implode("\n", $lines) . "\n" . $fence . "\n";
}

function plural(int $count, string $word): string
{
    return $count . ' ' . $word . ($count === 1 ? '' : 's');
}

function percent(int $covered, int $total): string
{
    return $total > 0 ? sprintf('%.1f%%', floor($covered / $total * 1000) / 10) : 'n/a';
}

function duration(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf('%.1fs', $seconds);
    }
    $seconds = (int)round($seconds);
    if ($seconds < 3600) {
        return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

/**
 * A path relative to the repository root ($GITHUB_WORKSPACE, or the current directory).
 */
function relativePath(string $path): string
{
    $root = workspace();

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

/**
 * Text with the paths in it relative to the repository root, e.g. a stack trace or a screenshot path.
 */
function relativeText(string $text): string
{
    return str_replace(workspace(), '', $text);
}

function workspace(): string
{
    return rtrim((string)(getenv('GITHUB_WORKSPACE') ?: getcwd()), '/') . '/';
}

function loadXml(string $file): ?SimpleXMLElement
{
    if ($file === '' || !is_file($file) || filesize($file) === 0) {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $xml === false ? null : $xml;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    echo summary(array_slice($argv, 1));
}
