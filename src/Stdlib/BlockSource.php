<?php
namespace Verhaalhalen\Stdlib;

use Laminas\Log\LoggerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Module\Manager as ModuleManager;

/**
 * Reads a site page into the fragments StoryBuilder understands.
 *
 * This is the only class that knows Omeka's block layouts. Four of them carry
 * story content — html, markdown (module Markdown), media and asset — and one
 * carries metadata, the module's own verhaalhalen block. Everything else
 * (page title, maps, browse previews, ...) is not text and is skipped.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class BlockSource
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var \Markdown\Stdlib\MarkdownConverter|false|null
     */
    protected $markdownConverter;

    public function __construct(ServiceLocatorInterface $services, Settings $settings, LoggerInterface $logger)
    {
        $this->services = $services;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * @return array Keys: title, language, fragments, metadata, marked
     */
    public function input(SitePageRepresentation $page)
    {
        $metadata = PageMetadata::fromPage($page);
        return [
            'title' => $page->title(),
            'language' => $this->language($page),
            'fragments' => $this->fragments($page),
            'metadata' => $metadata ?: PageMetadata::fromBlockData([]),
            'marked' => null !== $metadata,
        ];
    }

    /**
     * The content's language: the site's locale, else the installation's,
     * else the configured fallback.
     *
     * @return string
     */
    public function language(SitePageRepresentation $page)
    {
        $site = $page->site();
        $settings = $this->settings->forSite($site ? $site->slug() : null);
        $fallback = Settings::languageTag(
            $this->services->get('Omeka\Settings')->get('locale'),
            $settings['language'] ?? 'nl'
        );
        if (!$site) {
            return $fallback;
        }
        // API requests are not scoped to a site, so the site settings have no
        // target; the third argument sets and restores one for this read.
        $locale = $this->services->get('Omeka\Settings\Site')->get('locale', null, $site->id());
        return Settings::languageTag($locale, $fallback);
    }

    /**
     * @return array
     */
    protected function fragments(SitePageRepresentation $page)
    {
        $fragments = [];
        foreach ($page->blocks() as $block) {
            switch ($block->layout()) {
                case 'html':
                    $fragments[] = ['type' => 'html', 'html' => (string) $block->dataValue('html', '')];
                    break;
                case 'markdown':
                    $fragments[] = ['type' => 'html', 'html' => $this->markdownHtml($block)];
                    break;
                case 'media':
                    foreach ($this->mediaFragments($block) as $fragment) {
                        $fragments[] = $fragment;
                    }
                    break;
                case 'asset':
                    foreach ($this->assetFragments($block) as $fragment) {
                        $fragments[] = $fragment;
                    }
                    break;
                default:
                    break;
            }
        }
        return $fragments;
    }

    /**
     * The HTML of a Markdown block, the way the Markdown module itself renders
     * it: the stored HTML while its fingerprint matches the converter, a fresh
     * conversion otherwise. Without the module, the stored HTML is all there is.
     *
     * @return string
     */
    protected function markdownHtml(SitePageBlockRepresentation $block)
    {
        $converter = $this->markdownConverter();
        $html = (string) $block->dataValue('html', '');
        if (!$converter) {
            return $html;
        }
        if ($block->dataValue('fingerprint') === $converter->fingerprint()) {
            return $html;
        }
        try {
            return (string) $converter->convert((string) $block->dataValue('markdown', ''));
        } catch (\Throwable $e) {
            $this->logger->err(sprintf('[Verhaalhalen] Markdown block %d could not be converted: %s', $block->id(), $e->getMessage()));
            return $html;
        }
    }

    /**
     * @return object|null
     */
    protected function markdownConverter()
    {
        if (null === $this->markdownConverter) {
            $this->markdownConverter = false;
            $module = $this->services->get('Omeka\ModuleManager')->getModule('Markdown');
            if ($module && ModuleManager::STATE_ACTIVE === $module->getState()
                && $this->services->has('Markdown\MarkdownConverter')
            ) {
                $this->markdownConverter = $this->services->get('Markdown\MarkdownConverter');
            }
        }
        return $this->markdownConverter ?: null;
    }

    /**
     * @return array
     */
    protected function mediaFragments(SitePageBlockRepresentation $block)
    {
        $fragments = [];
        foreach ($block->attachments() as $attachment) {
            $media = $attachment->media();
            if (!$media && $attachment->item()) {
                $media = $attachment->item()->primaryMedia();
            }
            if (!$media) {
                continue;
            }
            $fragment = $this->mediaFragment($media);
            if (!$fragment) {
                continue;
            }
            $caption = trim(html_entity_decode(strip_tags((string) $attachment->caption()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ('' !== $caption) {
                $fragment['caption'] = $caption;
            }
            $fragments[] = $fragment;
        }
        return $fragments;
    }

    /**
     * @return array|null
     */
    protected function mediaFragment(MediaRepresentation $media)
    {
        $thumbnail = $media->hasThumbnails() ? $media->thumbnailUrl('large') : null;
        $original = $media->hasOriginal() ? $media->originalUrl() : null;
        $contentUrl = $original ?: $thumbnail;
        if (!$contentUrl) {
            return null;
        }
        $fragment = [
            'type' => 'image',
            'contentUrl' => $contentUrl,
            'thumbnailUrl' => $thumbnail,
            'encodingFormat' => $original ? $media->mediaType() : 'image/jpeg',
            'license' => $this->licenseOf($media),
        ];
        $alt = trim((string) $media->altText());
        if ('' !== $alt) {
            $fragment['caption'] = $alt;
        }
        return $fragment;
    }

    /**
     * The licence a media carries itself, or its item does: dcterms:license
     * first, then dcterms:rights, as a URI value or a literal URL.
     *
     * @return string|null
     */
    protected function licenseOf(MediaRepresentation $media)
    {
        $resources = [$media];
        $item = $media->item();
        if ($item) {
            $resources[] = $item;
        }
        foreach ($resources as $resource) {
            foreach (['dcterms:license', 'dcterms:rights'] as $term) {
                foreach ($resource->value($term, ['all' => true]) as $value) {
                    $uri = $value->uri();
                    if ($uri) {
                        return $uri;
                    }
                    $literal = trim((string) $value->value());
                    if (preg_match('~^https?://\S+$~', $literal)) {
                        return $literal;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @return array
     */
    protected function assetFragments(SitePageBlockRepresentation $block)
    {
        $fragments = [];
        $api = $this->services->get('Omeka\ApiManager');
        foreach ((array) $block->dataValue('attachments', []) as $attachment) {
            if (empty($attachment['id'])) {
                continue;
            }
            try {
                $asset = $api->read('assets', $attachment['id'])->getContent();
            } catch (\Exception $e) {
                // A deleted asset: the block still names it, the page simply
                // has one image fewer.
                continue;
            }
            $fragment = [
                'type' => 'image',
                'contentUrl' => $asset->assetUrl(),
                'encodingFormat' => $asset->mediaType(),
            ];
            $caption = trim(html_entity_decode(strip_tags((string) ($attachment['caption'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                ?: trim((string) $asset->altText());
            if ('' !== $caption) {
                $fragment['caption'] = $caption;
            }
            $fragments[] = $fragment;
        }
        return $fragments;
    }
}
