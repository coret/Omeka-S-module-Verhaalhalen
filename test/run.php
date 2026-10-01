<?php
/**
 * Fixture-driven tests of the HTML-to-story mapping and the two serializers.
 *
 * Each fixture is either test/fixtures/<name>.html — one HTML fragment, treated
 * as the only block of a page titled "Fixture" in Dutch — or
 * test/fixtures/<name>.input.json, which describes a page in full (title,
 * language, block fragments, Verhaalhalen block metadata). The expected NDE
 * Story content document is <name>.json; when <name>.record.json exists (or the
 * input sets "record": true) the SCHEMA-AP-NDE record is checked as well.
 *
 * The fixtures double as the worked examples behind the mapping tables in the
 * README, so every rule in those tables should have one here.
 *
 *   php test/run.php              run every fixture
 *   php test/run.php links        run the fixtures whose name contains "links"
 *   php test/run.php --update     (re)write the expected files from the current output
 *
 * No Omeka installation is needed: everything under test is plain PHP.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

$modulePath = dirname(__DIR__);

spl_autoload_register(function ($class) use ($modulePath) {
    if (0 === strpos($class, 'Verhaalhalen\\')) {
        $file = $modulePath . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$config = require $modulePath . '/config/module.config.php';
$settings = $config['verhaalhalen'];

$update = in_array('--update', $argv, true);
$filter = array_values(array_filter(array_slice($argv, 1), fn ($a) => '--update' !== $a))[0] ?? null;

const JSON_OUT = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

/**
 * Object key order is irrelevant in JSON-LD, list order is not: sort the keys
 * of associative arrays so that a fixture does not fail on ordering alone.
 */
function canon($value)
{
    if (!is_array($value)) {
        return $value;
    }
    $isList = array_keys($value) === range(0, count($value) - 1);
    $value = array_map('canon', $value);
    if (!$isList) {
        ksort($value);
    }
    return $value;
}

function showDiff($expected, $actual)
{
    $e = tempnam(sys_get_temp_dir(), 'vh-exp-');
    $a = tempnam(sys_get_temp_dir(), 'vh-act-');
    file_put_contents($e, json_encode(canon($expected), JSON_OUT) . "\n");
    file_put_contents($a, json_encode(canon($actual), JSON_OUT) . "\n");
    passthru(sprintf('diff -u %s %s | sed -n 1,80p | sed "s/^/      /"', escapeshellarg($e), escapeshellarg($a)));
    unlink($e);
    unlink($a);
}

$fixtureDir = __DIR__ . '/fixtures';
$names = [];
foreach (glob($fixtureDir . '/*.html') as $file) {
    $names[] = basename($file, '.html');
}
foreach (glob($fixtureDir . '/*.input.json') as $file) {
    $names[] = basename($file, '.input.json');
}
sort($names);
if ($filter) {
    $names = array_values(array_filter($names, fn ($n) => false !== strpos($n, $filter)));
}
if (!$names) {
    fwrite(STDERR, "No fixtures found.\n");
    exit(2);
}

$urls = [
    'story' => 'https://example.org/api/site_pages/1?format=verhaalhalen',
    'content' => 'https://example.org/api/site_pages/1?format=verhaalhalen-content',
    'page' => 'https://example.org/s/demo/page/fixture',
];
$pageInfo = [
    'slug' => 'fixture',
    'created' => '2026-09-20T14:51:45+00:00',
    'modified' => '2026-09-25T12:40:35+00:00',
    'site_title' => 'Demo site',
];
$today = '2026-10-01';

$passed = 0;
$failed = 0;
foreach ($names as $name) {
    $inputFile = "$fixtureDir/$name.input.json";
    if (is_file($inputFile)) {
        $input = json_decode(file_get_contents($inputFile), true);
        if (!is_array($input)) {
            printf("  FAIL  %s  (input is not valid JSON)\n", $name);
            $failed++;
            continue;
        }
    } else {
        $input = [
            'title' => 'Fixture',
            'language' => 'nl',
            'fragments' => [['type' => 'html', 'html' => file_get_contents("$fixtureDir/$name.html")]],
            'metadata' => [],
        ];
    }
    $input += ['title' => 'Fixture', 'language' => 'nl', 'fragments' => [], 'metadata' => [], 'record' => false];

    try {
        $story = (new Verhaalhalen\Stdlib\StoryBuilder($settings))->build($input);
        $outputs = [
            "$name.json" => (new Verhaalhalen\Stdlib\ContentSerializer($settings))->serialize($story, $urls),
        ];
        if ($input['record'] || is_file("$fixtureDir/$name.record.json")) {
            $outputs["$name.record.json"] = (new Verhaalhalen\Stdlib\RecordSerializer($settings))
                ->serialize($story, $input['metadata'], $pageInfo, $urls, $today);
        }
    } catch (Throwable $e) {
        printf("  FAIL  %s  (%s: %s at %s:%d)\n", $name, get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
        $failed++;
        continue;
    }

    foreach ($outputs as $expectedFile => $actual) {
        $path = "$fixtureDir/$expectedFile";
        if ($update) {
            file_put_contents($path, json_encode($actual, JSON_OUT) . "\n");
            printf("  wrote %s\n", $expectedFile);
            continue;
        }
        if (!is_file($path)) {
            printf("  FAIL  %s  (no expected file; run with --update to create it)\n", $expectedFile);
            $failed++;
            continue;
        }
        $expected = json_decode(file_get_contents($path), true);
        if (canon($expected) === canon($actual)) {
            printf("  ok    %s\n", $expectedFile);
            $passed++;
        } else {
            printf("  FAIL  %s\n", $expectedFile);
            showDiff($expected, $actual);
            $failed++;
        }
    }
}

if (!$update) {
    printf("\n%d passed, %d failed\n", $passed, $failed);
}
exit($failed ? 1 : 0);
