<?php
namespace Verhaalhalen\Stdlib;

use Omeka\Api\Representation\SitePageRepresentation;

/**
 * What a page's Verhaalhalen block says about the story.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class PageMetadata
{
    const LAYOUT = 'verhaalhalen';

    const TEXT_FIELDS = ['subtitle', 'abstract', 'description', 'license', 'temporal_coverage'];

    /**
     * The block's data in a predictable shape: every text field present and
     * trimmed, the JSON-LD decoded into an array (empty when absent or broken).
     *
     * @return array
     */
    public static function fromBlockData(array $data)
    {
        $metadata = [];
        foreach (self::TEXT_FIELDS as $field) {
            $metadata[$field] = trim((string) ($data[$field] ?? ''));
        }
        $jsonld = $data['jsonld'] ?? null;
        if (is_string($jsonld)) {
            $jsonld = json_decode($jsonld, true);
        }
        $isObject = is_array($jsonld) && array_keys($jsonld) !== range(0, count($jsonld) - 1);
        $metadata['jsonld'] = $isObject ? $jsonld : [];
        return $metadata;
    }

    /**
     * The first Verhaalhalen block of the page, or null when it has none.
     *
     * @return array|null
     */
    public static function fromPage(SitePageRepresentation $page)
    {
        foreach ($page->blocks() as $block) {
            if (self::LAYOUT === $block->layout()) {
                return self::fromBlockData($block->data());
            }
        }
        return null;
    }

    /**
     * @return bool
     */
    public static function isMarked(SitePageRepresentation $page)
    {
        return null !== self::fromPage($page);
    }
}
