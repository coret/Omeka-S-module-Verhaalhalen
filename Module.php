<?php
namespace Verhaalhalen;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Http\Header\GenericHeader;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Exception\RuntimeException;
use Omeka\View\Model\ApiJsonModel;
use Verhaalhalen\Mvc\Exception\BadRequestException;
use Verhaalhalen\Stdlib\CollectionSerializer;
use Verhaalhalen\Stdlib\ContentSerializer;
use Verhaalhalen\Stdlib\PageMetadata;
use Verhaalhalen\Stdlib\RecordSerializer;
use Verhaalhalen\Stdlib\StoryBuilder;

/**
 * Publishes site pages as NDE Story (Verhaalhalen) documents.
 *
 * Two API output formats are registered with core, so that content negotiation
 * and the Content-Type header are core's business:
 *
 *   /api/site_pages/{id}?format=verhaalhalen          the SCHEMA-AP-NDE record
 *   /api/site_pages/{id}?format=verhaalhalen-content  the text (NDE Story content)
 *   /api/site_pages?format=verhaalhalen               a Collection of the stories
 *
 * Unlike the GeoJson module there is no dispatch interception: a page is small,
 * so the default JSON core builds before firing api.output.serialize costs
 * nothing worth avoiding, and the listener simply replaces the output.
 *
 * @see https://verhaalhalen.ruimdetijd.nl/spec/
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Module extends AbstractModule
{
    const FORMAT_RECORD = 'verhaalhalen';
    const FORMAT_CONTENT = 'verhaalhalen-content';

    /**
     * The spec's canonical context, which identifies the profile regardless of
     * any local context_url rewrite.
     */
    const CONTEXT_URL = 'https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld';

    const MEDIA_TYPE_RECORD = 'application/ld+json';
    const MEDIA_TYPE_CONTENT = 'application/ld+json;profile="' . self::CONTEXT_URL . '"';

    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @var bool The current request matched its ETag and gets a 304 on finish
     */
    protected $notModified = false;

    /**
     * @var string|null Content-Type to restore on finish, see onFinish()
     */
    protected $contentType;

    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // The "EventManager" service is created without identifiers, so the
        // shared manager only ever consults its wildcard bucket for these.
        $sharedEventManager->attach('*', 'api.output.formats', [$this, 'registerFormats']);
        $sharedEventManager->attach('*', 'api.output.serialize', [$this, 'serialize']);
        // ApiJsonStrategy sets the status code after the renderer ran, so a 304
        // can only be applied once the whole response is assembled.
        $sharedEventManager->attach('*', MvcEvent::EVENT_FINISH, [$this, 'onFinish'], 100);
    }

    /**
     * Register both formats, so that core negotiates them and sets Content-Type.
     */
    public function registerFormats(Event $event)
    {
        $formats = $event->getParam('formats');
        $formats[self::FORMAT_RECORD] = self::MEDIA_TYPE_RECORD;
        $formats[self::FORMAT_CONTENT] = self::MEDIA_TYPE_CONTENT;
        $event->setParam('formats', $formats);
    }

    /**
     * Replace core's JSON-LD with the requested NDE Story document.
     */
    public function serialize(Event $event)
    {
        $format = $event->getParam('format');
        if (!in_array($format, [self::FORMAT_RECORD, self::FORMAT_CONTENT], true)) {
            return;
        }
        if (self::FORMAT_CONTENT === $format) {
            // Core builds the Content-Type header from the registered media type
            // through Laminas' ContentType class, which re-assembles the
            // parameters without their quotes and so turns the profile into an
            // invalid header value. onFinish() puts the exact string back.
            $this->contentType = self::MEDIA_TYPE_CONTENT;
        }
        $model = $event->getParam('model');
        if ($model instanceof ApiJsonModel && $model->getException()) {
            // Core already failed (403, 404, ...): its error document and its
            // status must survive.
            return;
        }
        $payload = $event->getParam('payload');

        try {
            if ($payload instanceof SitePageRepresentation) {
                $document = $this->renderPage($payload, $format);
            } elseif (is_array($payload) && self::FORMAT_RECORD === $format) {
                $document = $this->renderCollection($payload);
            } elseif (is_array($payload)) {
                throw new BadRequestException($this->translate(
                    'A collection has no single content document. Request one site page with format=verhaalhalen-content, or the collection with format=verhaalhalen.' // @translate
                ));
            } else {
                throw new BadRequestException($this->translate(
                    'The Verhaalhalen formats are only available for site pages (/api/site_pages).' // @translate
                ));
            }
            $event->setParam('output', $this->encode($document));
        } catch (NotFoundException | BadRequestException $e) {
            $this->fail($event, $e);
        } catch (\Throwable $e) {
            // An exception escaping here would be rendered as an HTML error
            // page on an API URL. Log it and answer as the API does.
            $this->getServiceLocator()->get('Omeka\Logger')->err((string) $e);
            $this->fail($event, new RuntimeException($this->translate(
                'The NDE Story document could not be built.' // @translate
            )));
        }
    }

    /**
     * Send the 304 that serialize() decided on.
     */
    public function onFinish(MvcEvent $event)
    {
        $response = $event->getResponse();
        if (!$response instanceof HttpResponse) {
            return;
        }
        if ($this->contentType) {
            $headers = $response->getHeaders();
            $stale = [];
            foreach ($headers as $header) {
                if ('content-type' === strtolower($header->getFieldName())) {
                    $stale[] = $header;
                }
            }
            foreach ($stale as $header) {
                $headers->removeHeader($header);
            }
            // A GenericHeader is sent verbatim; a ContentType header would be
            // re-assembled and lose the quotes again.
            $headers->addHeader(new GenericHeader('Content-Type', $this->contentType));
            $this->contentType = null;
        }
        if ($this->notModified) {
            $this->notModified = false;
            $response->setStatusCode(304);
            $response->setContent('');
        }
    }

    /**
     * @param string $format
     * @return array
     */
    protected function renderPage(SitePageRepresentation $page, $format)
    {
        $services = $this->getServiceLocator();
        $site = $page->site();
        $settings = $services->get('Verhaalhalen\Settings')->forSite($site ? $site->slug() : null);
        $urlBuilder = $services->get('Verhaalhalen\Urls');
        $urls = [
            'story' => $urlBuilder->storyUrl($page->id()),
            'content' => $urlBuilder->contentUrl($page->id()),
            'page' => $urlBuilder->pageUrl($page),
        ];

        $input = $services->get('Verhaalhalen\BlockSource')->input($page);
        $story = (new StoryBuilder($settings))->build($input);

        if (self::FORMAT_CONTENT === $format) {
            if (!$story->parts) {
                // The spec requires at least one TextObject; a page of maps or
                // browse previews simply has no story.
                throw new NotFoundException($this->translate(
                    'This page has no NDE Story content: none of its blocks holds text.' // @translate
                ));
            }
            $document = (new ContentSerializer($settings))->serialize($story, $urls);
        } else {
            $document = (new RecordSerializer($settings))->serialize($story, $input['metadata'], [
                'slug' => $page->slug(),
                'created' => $page->created()->format('c'),
                'modified' => $page->modified() ? $page->modified()->format('c') : null,
            ], $urls);
        }

        $this->addHeaders($page, $format, $settings, $urls);
        return $document;
    }

    /**
     * @param SitePageRepresentation[] $pages
     * @return array
     */
    protected function renderCollection(array $pages)
    {
        $services = $this->getServiceLocator();
        $settingsService = $services->get('Verhaalhalen\Settings');
        $urlBuilder = $services->get('Verhaalhalen\Urls');
        $source = $services->get('Verhaalhalen\BlockSource');

        $items = [];
        foreach ($pages as $page) {
            if (!$page instanceof SitePageRepresentation) {
                throw new BadRequestException($this->translate(
                    'The Verhaalhalen formats are only available for site pages (/api/site_pages).' // @translate
                ));
            }
            $site = $page->site();
            $settings = $settingsService->forSite($site ? $site->slug() : null);
            if (!empty($settings['only_marked']) && !PageMetadata::isMarked($page)) {
                continue;
            }
            $items[] = [
                'url' => $urlBuilder->storyUrl($page->id()),
                'name' => $page->title(),
                'language' => $source->language($page),
                'type' => $settings['record_type'] ?: 'Article',
            ];
        }

        $request = $services->get('Request');
        $collectionUrl = $request instanceof HttpRequest ? $urlBuilder->requestUrl($request) : '';
        $name = (string) $services->get('Omeka\Settings')->get('installation_title');

        $this->addCollectionHeaders($settingsService->forSite(null));
        return (new CollectionSerializer($settingsService->forSite(null)))->serialize($items, $collectionUrl, $name);
    }

    /**
     * Validators and caching hints. The status code cannot be set here (core
     * overwrites it afterwards), so a matching ETag only sets a flag that
     * onFinish() acts on.
     */
    protected function addHeaders(SitePageRepresentation $page, $format, array $settings, array $urls)
    {
        $services = $this->getServiceLocator();
        $response = $this->response();
        if (!$response) {
            return;
        }
        $isPrivate = (bool) $services->get('Omeka\AuthenticationService')->getIdentity();
        $modified = $page->modified() ?: $page->created();
        $etag = sprintf('"%s"', md5(implode('|', [
            $page->id(),
            $modified ? $modified->format('c') : '',
            $format,
            $services->get('Verhaalhalen\Settings')->fingerprint(),
            $urls['story'],
            \Omeka\Module::VERSION,
        ])));
        $alternate = self::FORMAT_CONTENT === $format
            ? sprintf('<%s>; rel="alternate"; type="%s"', $urls['story'], self::MEDIA_TYPE_RECORD)
            : sprintf('<%s>; rel="alternate"; type="%s"', $urls['content'], str_replace('"', '\\"', self::MEDIA_TYPE_CONTENT));

        $headers = [
            'ETag' => $etag,
            'Vary' => 'Accept',
            'Link' => $alternate,
            // A response built for a signed-in user can hold private pages or
            // private media; saying so keeps a shared cache from storing it.
            'Cache-Control' => $isPrivate
                ? 'private, no-store'
                : 'max-age=' . (int) ($settings['max_age'] ?? 3600),
        ];
        if ($modified) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $modified->getTimestamp()) . ' GMT';
        }
        $response->getHeaders()->addHeaders($headers);

        $request = $services->get('Request');
        if (!$isPrivate && $request instanceof HttpRequest) {
            $ifNoneMatch = $request->getHeader('If-None-Match');
            if ($ifNoneMatch && trim($ifNoneMatch->getFieldValue()) === $etag) {
                $this->notModified = true;
            }
        }
    }

    protected function addCollectionHeaders(array $settings)
    {
        $response = $this->response();
        if (!$response) {
            return;
        }
        $isPrivate = (bool) $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();
        $response->getHeaders()->addHeaders([
            'Vary' => 'Accept',
            'Cache-Control' => $isPrivate ? 'private, no-store' : 'max-age=' . (int) ($settings['max_age'] ?? 3600),
        ]);
    }

    /**
     * The HTTP response being built, or null outside an HTTP request.
     *
     * @return HttpResponse|null
     */
    protected function response()
    {
        $services = $this->getServiceLocator();
        if (!$services->has('Application')) {
            return null;
        }
        $response = $services->get('Application')->getMvcEvent()->getResponse();
        return $response instanceof HttpResponse ? $response : null;
    }

    /**
     * Turn the response into an error the way core's API does: the model's
     * exception decides the status code, the output is an errors document.
     */
    protected function fail(Event $event, \Exception $exception)
    {
        $model = $event->getParam('model');
        if ($model instanceof ApiJsonModel) {
            $model->setApiResponse(null);
            $model->setException($exception);
        }
        $event->setParam('output', json_encode(['errors' => ['error' => $exception->getMessage()]], self::JSON_FLAGS));
    }

    /**
     * @return string
     */
    protected function encode(array $document)
    {
        $flags = self::JSON_FLAGS;
        $services = $this->getServiceLocator();
        $request = $services->has('Request') ? $services->get('Request') : null;
        if ($request instanceof HttpRequest && null !== $request->getQuery('pretty_print')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($document, $flags);
        if (false === $json) {
            throw new \RuntimeException('The document cannot be encoded as JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * @param string $message
     * @return string
     */
    protected function translate($message)
    {
        $services = $this->getServiceLocator();
        return $services->has('MvcTranslator') ? $services->get('MvcTranslator')->translate($message) : $message;
    }
}
