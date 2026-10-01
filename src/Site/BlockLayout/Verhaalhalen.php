<?php
namespace Verhaalhalen\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Stdlib\ErrorStore;
use Verhaalhalen\Stdlib\PageMetadata;
use Verhaalhalen\Stdlib\RecordSerializer;
use Verhaalhalen\Stdlib\Urls;

/**
 * The page block that holds a story's metadata and marks the page as a story.
 *
 * Omeka pages have a title and blocks, nothing else; the record needs an
 * abstract, a licence, a period, subject terms. This block is where they live,
 * editable in the page editor and writable through the API like any block.
 * On the public page it renders nothing but a <link rel="alternate"> to the
 * record, so that a client can find the story from the page.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Verhaalhalen extends AbstractBlockLayout
{
    /**
     * @var Urls
     */
    protected $urls;

    public function __construct(Urls $urls)
    {
        $this->urls = $urls;
    }

    public function getLabel()
    {
        return 'Verhaalhalen (NDE Story)'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $data = $block->getData();
        $clean = [];
        foreach (PageMetadata::TEXT_FIELDS as $field) {
            $clean[$field] = trim((string) ($data[$field] ?? ''));
        }
        if ('' !== $clean['license'] && !preg_match('~^https?://\S+$~i', $clean['license'])) {
            $errorStore->addError('license', 'The licence must be a URL, such as https://creativecommons.org/licenses/by/4.0/.'); // @translate
        }

        // The JSON-LD is typed by hand, so a typo must be reported here rather
        // than silently stored and then missing from the record.
        $jsonld = trim((string) ($data['jsonld'] ?? ''));
        $clean['jsonld'] = '';
        if ('' !== $jsonld) {
            $decoded = json_decode($jsonld, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                $errorStore->addError('jsonld', sprintf(
                    'The JSON-LD is not valid JSON: %s', // @translate
                    json_last_error_msg()
                ));
            } elseif (!is_array($decoded) || array_keys($decoded) === range(0, count($decoded) - 1)) {
                $errorStore->addError('jsonld', 'The JSON-LD must be a JSON object whose keys are Schema.org properties, such as {"about": [...]}.'); // @translate
            } elseif ($reserved = array_intersect(array_keys($decoded), RecordSerializer::RESERVED)) {
                $errorStore->addError('jsonld', sprintf(
                    'The JSON-LD may not set %s: the module writes those itself.', // @translate
                    implode(', ', $reserved)
                ));
            } else {
                $clean['jsonld'] = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $block->setData($clean);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        return $view->partial('common/block-layout/verhaalhalen-form', [
            'data' => $block ? $block->data() : [],
            'reserved' => RecordSerializer::RESERVED,
        ]);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $page = $block->page();
        if ($page) {
            // Blocks render before the layout, so this reaches the <head>.
            $view->headLink()->appendAlternate(
                $this->urls->storyUrl($page->id()),
                'application/ld+json',
                'NDE Story'
            );
        }
        return '';
    }

    public function getFulltextText(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        return trim($block->dataValue('abstract', '') . ' ' . $block->dataValue('description', ''));
    }
}
