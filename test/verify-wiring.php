<?php
/**
 * Checks that this module's Omeka-facing parts are wired the way the rest of
 * it assumes.
 *
 * Not a unit test suite: the mapping itself is covered by test/run.php and its
 * fixtures. This script exercises the real classes against the real Omeka S
 * the module is installed into — format registration, the error paths of the
 * serialize listener, URL building, settings, the block's validation — and
 * needs no database and no HTTP server, so it can run as a release gate.
 *
 *   php test/verify-wiring.php
 *   OMEKA_PATH=/path/to/omeka-s php test/verify-wiring.php
 *
 * Exits non-zero when anything failed.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

// ---------------------------------------------------------------- locating Omeka

$modulePath = dirname(__DIR__);
$omekaPath = getenv('OMEKA_PATH') ?: null;
if (!$omekaPath) {
    $candidate = dirname($modulePath, 2);
    if (is_file($candidate . '/application/Module.php')) {
        $omekaPath = $candidate;
    }
}
if (!$omekaPath || !is_file($omekaPath . '/application/Module.php')) {
    fwrite(STDERR, "Cannot find the Omeka S installation this module belongs to.\n"
        . "Run it as: OMEKA_PATH=/path/to/omeka-s php test/verify-wiring.php\n");
    exit(2);
}

define('OMEKA_PATH', $omekaPath);
require OMEKA_PATH . '/vendor/autoload.php';
require OMEKA_PATH . '/application/Module.php';

spl_autoload_register(function ($class) use ($modulePath) {
    if (0 === strpos($class, 'Verhaalhalen\\')) {
        $file = $modulePath . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require $modulePath . '/Module.php';

// ------------------------------------------------------------------- harness

class Verify
{
    public $passed = 0;
    public $failed = 0;

    public function section($title)
    {
        printf("\n%s\n", $title);
    }

    public function check($label, $ok, $detail = '')
    {
        $ok ? $this->passed++ : $this->failed++;
        printf("  %-5s %s%s\n", $ok ? 'ok' : 'FAIL', $label, '' === $detail ? '' : "  ($detail)");
    }

    public function summary()
    {
        printf("\n%d passed, %d failed\n", $this->passed, $this->failed);
        return $this->failed ? 1 : 0;
    }
}

function makeLogger(?Laminas\Log\Writer\Mock &$writer = null)
{
    $writer = new Laminas\Log\Writer\Mock();
    $logger = new Laminas\Log\Logger();
    $logger->addWriter($writer);
    return $logger;
}

function makeServices(array $config, array $extra = [])
{
    return new Laminas\ServiceManager\ServiceManager([
        'services' => $extra + ['Config' => ['verhaalhalen' => $config]],
    ]);
}

/**
 * A stand-in for the Url view helper: enough to see what the module asks for.
 */
function fakeUrlHelper()
{
    return function ($route, array $params = [], array $options = []) {
        $url = 'https://request.example/omeka/api/' . $params['resource'];
        if (isset($params['id'])) {
            $url .= '/' . $params['id'];
        }
        if (!empty($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }
        return $url;
    };
}

$v = new Verify();
$config = require $modulePath . '/config/module.config.php';
$settings = $config['verhaalhalen'];

// ------------------------------------------------------------------ formats

$v->section('Two output formats are registered with core');

$module = new Verhaalhalen\Module();
$module->setServiceLocator(makeServices($settings));
$event = new Laminas\EventManager\Event('api.output.formats', null, ['formats' => ['jsonld' => 'application/ld+json']]);
$module->registerFormats($event);
$formats = $event->getParam('formats');

$v->check('the core formats are kept', 'application/ld+json' === ($formats['jsonld'] ?? null));
$v->check('verhaalhalen is the record, served as JSON-LD', 'application/ld+json' === ($formats['verhaalhalen'] ?? null));
$v->check(
    'verhaalhalen-content carries the NDE Story profile',
    'application/ld+json;profile="https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld"' === ($formats['verhaalhalen-content'] ?? null),
    $formats['verhaalhalen-content'] ?? 'missing'
);

// ------------------------------------------------------------ serialize paths

$v->section('The serialize listener leaves alone what is not its business');

$model = new Omeka\View\Model\ApiJsonModel(new Omeka\Api\Response(['x' => 1]));
$event = new Laminas\EventManager\Event('api.output.serialize', null, [
    'model' => $model, 'payload' => ['x' => 1], 'format' => 'jsonld', 'output' => 'ORIGINAL',
]);
$module->serialize($event);
$v->check('another format passes through untouched', 'ORIGINAL' === $event->getParam('output'));

$failed = new Omeka\View\Model\ApiJsonModel(null);
$failed->setException(new Omeka\Api\Exception\NotFoundException('gone'));
$event = new Laminas\EventManager\Event('api.output.serialize', null, [
    'model' => $failed, 'payload' => ['errors' => ['error' => 'gone']], 'format' => 'verhaalhalen', 'output' => 'CORE ERROR',
]);
$module->serialize($event);
$v->check('a response core already failed is passed through, so its 404 survives', 'CORE ERROR' === $event->getParam('output'));
$v->check('and its exception is still the core one', $failed->getException() instanceof Omeka\Api\Exception\NotFoundException);

$v->section('A request for something that is not a site page is refused');

$model = new Omeka\View\Model\ApiJsonModel(new Omeka\Api\Response(new stdClass()));
$event = new Laminas\EventManager\Event('api.output.serialize', null, [
    'model' => $model, 'payload' => new stdClass(), 'format' => 'verhaalhalen', 'output' => '{"o:id":1}',
]);
$module->serialize($event);
$decoded = json_decode((string) $event->getParam('output'), true);
$v->check('the output is a JSON error document', isset($decoded['errors']['error']), (string) $event->getParam('output'));
$v->check(
    'the model carries an exception core maps to 400',
    $model->getException() instanceof Omeka\Mvc\Exception\InvalidJsonException,
    $model->getException() ? get_class($model->getException()) : 'none'
);
$v->check('the API response is cleared, so the status is taken from the exception', null === $model->getApiResponse());

$model = new Omeka\View\Model\ApiJsonModel(new Omeka\Api\Response([]));
$event = new Laminas\EventManager\Event('api.output.serialize', null, [
    'model' => $model, 'payload' => [], 'format' => 'verhaalhalen-content', 'output' => '[]',
]);
$module->serialize($event);
$v->check('a collection has no single content document: 400', $model->getException() instanceof Omeka\Mvc\Exception\InvalidJsonException);

// ------------------------------------------------------------- content type

$v->section('The content document keeps its quoted profile parameter');

// Laminas re-assembles Content-Type from its parsed parts and drops the quotes
// around the profile, which leaves an invalid header; onFinish() restores it.
$response = new Laminas\Http\Response();
$response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
$response->getHeaders()->addHeaderLine('Content-Type', 'application/ld+json; profile=https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld');
$mvcEvent = new Laminas\Mvc\MvcEvent();
$mvcEvent->setResponse($response);

$module->serialize(new Laminas\EventManager\Event('api.output.serialize', null, [
    'model' => new Omeka\View\Model\ApiJsonModel(new Omeka\Api\Response([])),
    'payload' => [], 'format' => 'verhaalhalen-content', 'output' => '[]',
]));
$module->onFinish($mvcEvent);
$contentTypes = [];
foreach ($response->getHeaders() as $header) {
    if ('Content-Type' === $header->getFieldName()) {
        $contentTypes[] = $header->toString();
    }
}
$v->check('exactly one Content-Type is left', 1 === count($contentTypes), implode(' | ', $contentTypes));
$v->check(
    'and it is the registered media type, quotes included',
    ['Content-Type: ' . Verhaalhalen\Module::MEDIA_TYPE_CONTENT] === $contentTypes,
    implode(' | ', $contentTypes)
);

$untouched = new Laminas\Http\Response();
$untouched->getHeaders()->addHeaderLine('Content-Type', 'application/ld+json');
$plain = new Laminas\Mvc\MvcEvent();
$plain->setResponse($untouched);
$module->onFinish($plain);
$v->check('a response of another format is left alone', 'application/ld+json' === $untouched->getHeaders()->get('Content-Type')->getFieldValue());

// ----------------------------------------------------------------- settings

$v->section('Settings');

$merged = new Verhaalhalen\Stdlib\Settings(array_replace($settings, ['sites' => ['archief' => ['license' => 'https://l.example/']]]));
$v->check('a site override replaces the default', 'https://l.example/' === $merged->forSite('archief')['license']);
$v->check('other sites keep the default', $settings['license'] === $merged->forSite('data')['license']);
$v->check('no site at all keeps the default', $settings['license'] === $merged->forSite(null)['license']);
$v->check(
    'the fingerprint changes with the configuration',
    (new Verhaalhalen\Stdlib\Settings($settings))->fingerprint() !== (new Verhaalhalen\Stdlib\Settings(array_replace($settings, ['language' => 'fy'])))->fingerprint()
);
$v->check('a site locale is reduced to its language', 'nl' === Verhaalhalen\Stdlib\Settings::languageTag('nl_NL', 'en'));
$v->check('a bare language tag is kept', 'fy' === Verhaalhalen\Stdlib\Settings::languageTag('fy', 'en'));
$v->check('a missing locale falls back', 'en' === Verhaalhalen\Stdlib\Settings::languageTag(null, 'en'));

// --------------------------------------------------------------------- urls

$v->section('Story URLs are built from the API route and pinned to base_url');

$urls = new Verhaalhalen\Stdlib\Urls(fakeUrlHelper(), 'https://canonical.example');
$v->check(
    'the story URL is the API URL with format=verhaalhalen',
    'https://canonical.example/omeka/api/site_pages/88?format=verhaalhalen' === $urls->storyUrl(88),
    $urls->storyUrl(88)
);
$v->check(
    'the content URL is the same with format=verhaalhalen-content',
    'https://canonical.example/omeka/api/site_pages/88?format=verhaalhalen-content' === $urls->contentUrl(88),
    $urls->contentUrl(88)
);

$ported = new Verhaalhalen\Stdlib\Urls(fakeUrlHelper(), 'http://inside.example:8080');
$v->check(
    'an explicit scheme and port are honoured',
    'http://inside.example:8080/omeka/api/items' === $ported->applyBaseUrl('https://x.example/omeka/api/items'),
    $ported->applyBaseUrl('https://x.example/omeka/api/items')
);
foreach (['an unset' => null, 'a nonsense' => 'not a url'] as $case => $baseUrl) {
    $fallback = new Verhaalhalen\Stdlib\Urls(fakeUrlHelper(), $baseUrl);
    $v->check(
        sprintf('%s base_url leaves the request-derived URL alone', $case),
        'https://x.example/api/items' === $fallback->applyBaseUrl('https://x.example/api/items')
    );
}

// ------------------------------------------------------------- page metadata

$v->section('The Verhaalhalen block data is read defensively');

$metadata = Verhaalhalen\Stdlib\PageMetadata::fromBlockData([
    'subtitle' => '  Sub  ', 'jsonld' => '{"about":[{"@type":"DefinedTerm","name":"Gouda"}]}',
]);
$v->check('fields are trimmed', 'Sub' === $metadata['subtitle']);
$v->check('the JSON-LD string is decoded', 'Gouda' === ($metadata['jsonld']['about'][0]['name'] ?? null));
$v->check('absent fields are empty strings', '' === $metadata['abstract']);
$v->check('invalid JSON-LD yields nothing rather than an error', [] === Verhaalhalen\Stdlib\PageMetadata::fromBlockData(['jsonld' => '{oops'])['jsonld']);
$v->check('JSON-LD that is not an object yields nothing', [] === Verhaalhalen\Stdlib\PageMetadata::fromBlockData(['jsonld' => '[1,2]'])['jsonld']);

// ------------------------------------------------------------- block layout

$v->section('The block validates what it is given');

$layout = new Verhaalhalen\Site\BlockLayout\Verhaalhalen($urls);
$v->check('the block has a label', '' !== (string) $layout->getLabel());

function hydrate($layout, array $data)
{
    $block = new Omeka\Entity\SitePageBlock();
    $block->setData($data);
    $errors = new Omeka\Stdlib\ErrorStore();
    $layout->onHydrate($block, $errors);
    return [$block->getData(), $errors->getErrors()];
}

[$data, $errors] = hydrate($layout, ['jsonld' => '{"about": [1]']);
$v->check('invalid JSON is reported on the jsonld field', isset($errors['jsonld']));
[$data, $errors] = hydrate($layout, ['jsonld' => '[1, 2]']);
$v->check('JSON that is not an object is reported', isset($errors['jsonld']));
[$data, $errors] = hydrate($layout, ['jsonld' => '{"@id": "https://evil.example/"}']);
$v->check('a reserved key is reported rather than silently dropped later', isset($errors['jsonld']), json_encode($errors));
[$data, $errors] = hydrate($layout, ['license' => 'CC BY']);
$v->check('a licence that is not a URL is reported', isset($errors['license']));
[$data, $errors] = hydrate($layout, [
    'subtitle' => ' Sub ', 'license' => 'https://creativecommons.org/licenses/by/4.0/',
    'jsonld' => "{\"about\":\n[{\"@type\":\"DefinedTerm\",\"name\":\"Gouda\"}]}",
]);
$v->check('valid data passes', [] === $errors, json_encode($errors));
$v->check('and is stored normalised', "{\n    \"about\": [\n        {\n            \"@type\": \"DefinedTerm\",\n            \"name\": \"Gouda\"\n        }\n    ]\n}" === $data['jsonld'], $data['jsonld']);
$v->check('fields are trimmed on save', 'Sub' === $data['subtitle']);
[$data, $errors] = hydrate($layout, []);
$v->check('an empty block is fine: it is the marker that matters', [] === $errors);

// ----------------------------------------------------------------- collection

$v->section('The collection lists stories the way Verhaalhalen itself does');

$collection = (new Verhaalhalen\Stdlib\CollectionSerializer($settings))->serialize(
    [['url' => 'https://c.example/1?format=verhaalhalen', 'name' => 'Een', 'language' => 'nl']],
    'https://c.example/api/site_pages?format=verhaalhalen',
    'Demo'
);
$v->check('it is a Collection under the NDE Story context', 'Collection' === $collection['@type'] && $settings['context_url'] === $collection['@context']);
$v->check('its @id is the request URL', 'https://c.example/api/site_pages?format=verhaalhalen' === $collection['@id']);
$v->check('each entry carries @id, @type and a tagged name',
    ['@id' => 'https://c.example/1?format=verhaalhalen', '@type' => ['CreativeWork', 'Article'], 'name' => ['@language' => 'nl', '@value' => 'Een']] === $collection['hasPart'][0],
    json_encode($collection['hasPart'][0] ?? null));

exit($v->summary());
