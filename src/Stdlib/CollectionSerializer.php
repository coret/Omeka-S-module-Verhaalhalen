<?php
namespace Verhaalhalen\Stdlib;

/**
 * Lists stories the way Verhaalhalen's own /api/stories does.
 *
 * The spec leaves listing to the implementation ("how records are grouped and
 * listed is defined outside this specification"); this mirrors the reference
 * server so that a client written against it can read this one.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class CollectionSerializer
{
    /**
     * @var array
     */
    protected $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param array $items Each: url, name, language (optional), type (optional)
     * @param string $collectionUrl
     * @param string $name
     * @return array
     */
    public function serialize(array $items, $collectionUrl, $name)
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = [
                '@id' => $item['url'],
                '@type' => ['CreativeWork', $item['type'] ?? ($this->settings['record_type'] ?: 'Article')],
                'name' => [
                    '@language' => $item['language'] ?? $this->settings['language'],
                    '@value' => (string) $item['name'],
                ],
            ];
        }
        return [
            '@context' => $this->settings['context_url'],
            '@type' => 'Collection',
            '@id' => $collectionUrl,
            'name' => (string) $name,
            'hasPart' => $parts,
        ];
    }
}
