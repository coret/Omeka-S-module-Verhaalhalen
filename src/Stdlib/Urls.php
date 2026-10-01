<?php
namespace Verhaalhalen\Stdlib;

use Laminas\Http\Request as HttpRequest;
use Laminas\Uri\Http as HttpUri;
use Omeka\Api\Representation\SitePageRepresentation;

/**
 * The URLs the documents are addressed by.
 *
 * The story URL — the record's @id and the base every fragment identifier
 * resolves against — is the page's own API URL with format=verhaalhalen, so it
 * dereferences to the record itself. Appending -content to the format gives
 * the content URL.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Urls
{
    const FORMAT_RECORD = 'verhaalhalen';
    const FORMAT_CONTENT = 'verhaalhalen-content';

    /**
     * @var callable The Url view helper, or anything with its signature
     */
    protected $url;

    /**
     * @var string|null
     */
    protected $baseUrl;

    public function __construct(callable $url, $baseUrl)
    {
        $this->url = $url;
        $this->baseUrl = $baseUrl;
    }

    /**
     * @param int $pageId
     * @return string
     */
    public function storyUrl($pageId)
    {
        return $this->apiUrl($pageId, self::FORMAT_RECORD);
    }

    /**
     * @param int $pageId
     * @return string
     */
    public function contentUrl($pageId)
    {
        return $this->apiUrl($pageId, self::FORMAT_CONTENT);
    }

    /**
     * The public, human-readable page.
     *
     * @return string
     */
    public function pageUrl(SitePageRepresentation $page)
    {
        return $this->applyBaseUrl($page->siteUrl(null, true));
    }

    /**
     * The URL the client asked for, as the collection's @id.
     *
     * @return string
     */
    public function requestUrl(HttpRequest $request)
    {
        return $this->applyBaseUrl($request->getUriString());
    }

    /**
     * @return string
     */
    protected function apiUrl($pageId, $format)
    {
        $url = ($this->url)(
            'api/default',
            ['resource' => 'site_pages', 'id' => (int) $pageId],
            ['force_canonical' => true, 'query' => ['format' => $format]]
        );
        return $this->applyBaseUrl($url);
    }

    /**
     * Pin the scheme and host of a canonical URL to the configured base URL.
     *
     * force_canonical takes them from the request, which means the client's
     * Host header decides what goes into the documents. Setting "base_url"
     * takes that decision away from the client entirely; leaving it unset
     * keeps the request-derived behaviour.
     *
     * @param string $url
     * @return string
     */
    public function applyBaseUrl($url)
    {
        if (!$this->baseUrl) {
            return $url;
        }
        // parse_url rather than Laminas\Uri\Http, whose getPort() answers with
        // the scheme's default port when the URL does not name one; reading it
        // back through setPort() would pin https://example.org:443/...
        $base = parse_url($this->baseUrl);
        if (empty($base['host'])) {
            return $url;
        }
        $uri = new HttpUri($url);
        $uri->setHost($base['host'])
            ->setPort($base['port'] ?? null);
        if (!empty($base['scheme'])) {
            $uri->setScheme($base['scheme']);
        }
        return $uri->toString();
    }
}
