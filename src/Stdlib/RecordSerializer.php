<?php
namespace Verhaalhalen\Stdlib;

/**
 * Writes the SCHEMA-AP-NDE record of a story (spec §3).
 *
 * The record carries only Schema.org terms under the plain Schema.org context,
 * so URLs are written as {"@id"} and dates as typed values, which is what the
 * profile's SHACL shapes expect. Text values are language-tagged one by one.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class RecordSerializer
{
    /**
     * Keys of the Verhaalhalen block's JSON-LD that may not reach the record:
     * they are the module's to write, or belong to the content document.
     */
    const RESERVED = [
        '@context', '@id', '@type', 'name', 'text', 'hasPart', 'headline', 'alternativeHeadline',
        'associatedMedia', 'annotations', 'encodesCreativeWork',
    ];

    const CONTENT_ENCODING_FORMAT = "application/ld+json;profile='%s'";

    /**
     * @var array
     */
    protected $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param array $metadata The Verhaalhalen block's data, if the page has one
     * @param array $pageInfo Keys: slug, created, modified
     * @param array $urls Keys: story, content, page
     * @param string|null $today sdDatePublished; null for the current date
     * @return array
     */
    public function serialize(Story $story, array $metadata, array $pageInfo, array $urls, $today = null)
    {
        $lang = $story->language;
        $tag = fn ($value) => ['@language' => $lang, '@value' => $value];

        $record = [
            '@context' => 'https://schema.org',
            '@type' => ['CreativeWork', $this->settings['record_type'] ?: 'Article'],
            '@id' => $urls['story'],
            'name' => $tag($story->headline),
        ];
        if (!empty($pageInfo['slug'])) {
            $record['identifier'] = $pageInfo['slug'];
        }
        if (!empty($urls['page'])) {
            $record['url'] = ['@id' => $urls['page']];
        }
        $record['inLanguage'] = $lang;
        if (!empty($pageInfo['created'])) {
            $record['dateCreated'] = ['@type' => 'Date', '@value' => substr($pageInfo['created'], 0, 10)];
        }
        if (!empty($pageInfo['modified'])) {
            $record['dateModified'] = ['@type' => 'Date', '@value' => substr($pageInfo['modified'], 0, 10)];
        }
        // The profile's shapes want xsd:date here, where the other dates are
        // schema:Date; the spec's own example record does the same.
        $record['sdDatePublished'] = ['@type' => 'http://www.w3.org/2001/XMLSchema#date', '@value' => $today ?: date('Y-m-d')];

        $abstract = trim((string) ($metadata['abstract'] ?? ''));
        if ('' !== $abstract) {
            $record['abstract'] = $tag($abstract);
        }
        $description = trim((string) ($metadata['description'] ?? ''));
        if ('' === $description && 'first_paragraph' === ($this->settings['description_fallback'] ?? null)) {
            $description = $this->firstParagraph($story);
        }
        if ('' !== $description) {
            $record['description'] = $tag($description);
        }
        $text = $story->plainText();
        if ('' !== $text) {
            $record['text'] = $tag($text);
        }
        $temporal = trim((string) ($metadata['temporal_coverage'] ?? ''));
        if ('' !== $temporal) {
            $record['temporalCoverage'] = $temporal;
        }

        foreach (['creator', 'publisher'] as $key) {
            if (!empty($this->settings[$key])) {
                $record[$key] = $this->tagNames($this->settings[$key], $lang);
            }
        }
        $license = trim((string) ($metadata['license'] ?? '')) ?: ($this->settings['license'] ?? '');
        if ('' !== $license) {
            $record['license'] = ['@id' => $license];
        }
        $dataset = $this->settings['dataset'] ?? null;
        if (!empty($dataset['@id'])) {
            $record['isPartOf'] = $this->tagNames(['@type' => 'Dataset'] + $dataset, $lang);
        }
        if (!empty($this->settings['additional_type'])) {
            $record['additionalType'] = $this->tagNames($this->settings['additional_type'], $lang);
        }
        if (!empty($this->settings['genre'])) {
            $record['genre'] = $this->tagNames($this->settings['genre'], $lang);
        }

        // Whatever the block adds wins over the configuration, except for the
        // keys the module owns.
        foreach ($this->extraJsonLd($metadata) as $key => $value) {
            if (!in_array($key, self::RESERVED, true)) {
                $record[$key] = $value;
            }
        }

        $media = [];
        foreach ($story->allImages() as $image) {
            $media[] = $this->image($image, $urls['story']);
        }
        // The spec's content entry, plus a thumbnailUrl the spec does not ask
        // for: the profile's MediaObject shape requires one, and without it the
        // entry fails the shape and the record no longer validates.
        $media[] = [
            '@id' => $urls['content'],
            '@type' => ['MediaObject', 'TextObject'],
            'contentUrl' => ['@id' => $urls['content']],
            'thumbnailUrl' => ['@id' => $urls['content']],
            'encodingFormat' => sprintf(self::CONTENT_ENCODING_FORMAT, $this->settings['context_url']),
            'license' => ['@id' => $license],
        ];
        $record['associatedMedia'] = $media;

        return $record;
    }

    /**
     * The block's free-form JSON-LD, whether stored decoded or as a string.
     *
     * @return array
     */
    protected function extraJsonLd(array $metadata)
    {
        $extra = $metadata['jsonld'] ?? null;
        if (is_string($extra)) {
            $extra = json_decode($extra, true);
        }
        return is_array($extra) ? $extra : [];
    }

    /**
     * @return string
     */
    protected function firstParagraph(Story $story)
    {
        foreach ($story->parts as $part) {
            if ('Head' !== $part->subtype) {
                return $part->text;
            }
        }
        return '';
    }

    /**
     * The profile's view of an image: rights and retrievability.
     *
     * @return array
     */
    protected function image(ImageObject $image, $storyUrl)
    {
        $node = [
            '@type' => $image->type,
            '@id' => $storyUrl . $image->id,
            'contentUrl' => ['@id' => $image->contentUrl],
            'thumbnailUrl' => ['@id' => $image->thumbnailUrl ?: $image->contentUrl],
        ];
        if ($image->encodingFormat) {
            $node['encodingFormat'] = $image->encodingFormat;
        }
        $license = $image->license ?: ($this->settings['image_license'] ?? ($this->settings['license'] ?? null));
        if ($license) {
            $node['license'] = ['@id' => $license];
        }
        return $node;
    }

    /**
     * Prepare a configured node (creator, dataset, a DefinedTerm, or a list of
     * them) for the record: plain "name" strings get the language tag, "url"
     * and "sameAs" strings become IRIs.
     *
     * @param array $node
     * @param string $lang
     * @return array
     */
    protected function tagNames(array $node, $lang)
    {
        if (array_keys($node) === range(0, count($node) - 1)) {
            return array_map(fn ($item) => is_array($item) ? $this->tagNames($item, $lang) : $item, $node);
        }
        foreach ($node as $key => $value) {
            if ('name' === $key && is_string($value)) {
                $node[$key] = ['@language' => $lang, '@value' => $value];
            } elseif (in_array($key, ['url', 'sameAs'], true) && is_string($value)) {
                $node[$key] = ['@id' => $value];
            } elseif (is_array($value) && '@type' !== $key) {
                $node[$key] = $this->tagNames($value, $lang);
            }
        }
        return $node;
    }
}
