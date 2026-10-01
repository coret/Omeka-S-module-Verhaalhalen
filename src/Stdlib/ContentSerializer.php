<?php
namespace Verhaalhalen\Stdlib;

/**
 * Writes a Story as the spec's content document (§4).
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class ContentSerializer
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
     * @param array $urls Keys: story, content
     * @return array
     */
    public function serialize(Story $story, array $urls)
    {
        $document = [
            '@context' => [
                $this->settings['context_url'],
                ['@base' => $urls['story'], '@language' => $story->language],
            ],
            '@type' => ['MediaObject', 'TextObject'],
            '@id' => $urls['content'],
            'encodesCreativeWork' => ['@id' => $urls['story']],
            'headline' => $story->headline,
        ];
        if (null !== $story->subtitle) {
            $document['alternativeHeadline'] = $story->subtitle;
        }

        $document['hasPart'] = [];
        foreach ($story->parts as $part) {
            $document['hasPart'][] = $this->textObject($part);
        }
        if ($story->images) {
            $document['associatedMedia'] = array_map([$this, 'image'], $story->images);
        }
        $annotations = $story->allAnnotations();
        if ($annotations) {
            $document['annotations'] = array_map(fn ($a) => $this->annotation($a, $urls), $annotations);
        }
        return $document;
    }

    /**
     * @return array
     */
    protected function textObject(TextObject $part)
    {
        $node = [
            '@type' => ['TextObject', $part->subtype],
            '@id' => $part->id,
            'text' => null === $part->language
                ? $part->text
                : ['@value' => $part->text, '@language' => $part->language],
        ];
        if ($part->images) {
            $node['associatedMedia'] = array_map([$this, 'image'], $part->images);
        }
        return $node;
    }

    /**
     * The renderer's view of an image: placement and caption.
     *
     * @return array
     */
    protected function image(ImageObject $image)
    {
        $node = [
            '@type' => $image->type,
            '@id' => $image->id,
            'contentUrl' => $image->contentUrl,
        ];
        if ($image->encodingFormat) {
            $node['encodingFormat'] = $image->encodingFormat;
        }
        if (null !== $image->caption) {
            $node['caption'] = $image->caption;
        }
        if (null !== $image->rend) {
            $node['rend'] = $image->rend;
        }
        return $node;
    }

    /**
     * @return array
     */
    protected function annotation(Annotation $annotation, array $urls)
    {
        $node = [
            '@id' => $annotation->id,
            '@type' => 'Annotation',
            'motivation' => $annotation->motivation,
        ];
        if ($annotation->target) {
            $node['target'] = [
                'source' => $annotation->target->id,
                'selector' => [
                    '@type' => 'TextPositionSelector',
                    'start' => $annotation->start,
                    'end' => $annotation->end,
                ],
            ];
        } else {
            $node['target'] = [
                'source' => $urls['content'],
                'selector' => ['@type' => 'TextQuoteSelector', 'exact' => $annotation->exact],
            ];
        }
        if (null !== $annotation->body) {
            $node['body'] = $annotation->body;
        }
        return $node;
    }
}
