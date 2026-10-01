<?php
namespace Verhaalhalen\Stdlib;

/**
 * Everything the content document holds, before serialisation.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Story
{
    /**
     * @var string The title as rendered above the text
     */
    public $headline;

    /**
     * @var string|null The subtitle
     */
    public $subtitle;

    /**
     * @var string The document language, e.g. "nl"
     */
    public $language;

    /**
     * @var TextObject[] In reading order
     */
    public $parts = [];

    /**
     * @var ImageObject[] Images of the story as a whole
     */
    public $images = [];

    public function __construct($headline, $language, $subtitle = null)
    {
        $this->headline = $headline;
        $this->language = $language;
        $this->subtitle = $subtitle;
    }

    /**
     * Every image, story-level ones first, then per block in reading order.
     *
     * @return ImageObject[]
     */
    public function allImages()
    {
        $images = $this->images;
        foreach ($this->parts as $part) {
            foreach ($part->images as $image) {
                $images[] = $image;
            }
        }
        return $images;
    }

    /**
     * @return Annotation[]
     */
    public function allAnnotations()
    {
        $annotations = [];
        foreach ($this->parts as $part) {
            foreach ($part->annotations as $annotation) {
                $annotations[] = $annotation;
            }
        }
        return $annotations;
    }

    /**
     * The plain full text, as the record's "text" wants it.
     *
     * @return string
     */
    public function plainText()
    {
        return implode(' ', array_map(fn ($part) => $part->text, $this->parts));
    }

    /**
     * Give every node its fragment identifier, numbered per kind in reading order.
     */
    public function assignIds()
    {
        $counters = ['text' => 0, 'head' => 0, 'image' => 0, 'annotation' => 0];
        foreach ($this->images as $image) {
            $image->id = '#image-' . ++$counters['image'];
        }
        foreach ($this->parts as $part) {
            $prefix = 'Head' === $part->subtype ? 'head' : 'text';
            $part->id = sprintf('#%s-%d', $prefix, ++$counters[$prefix]);
            foreach ($part->images as $image) {
                $image->id = '#image-' . ++$counters['image'];
            }
        }
        foreach ($this->parts as $part) {
            foreach ($part->annotations as $annotation) {
                $annotation->id = '#annotation-' . ++$counters['annotation'];
            }
        }
    }
}
