<?php
namespace Verhaalhalen\Stdlib;

/**
 * One block of text: a leaf of the content's hasPart list.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class TextObject
{
    /**
     * @var string|null The fragment identifier, such as "#text-1", once assigned
     */
    public $id;

    /**
     * @var string The spec's subtype: Paragraph, Head, Opener, Closer, Quote, FloatingText or Caption
     */
    public $subtype;

    /**
     * @var string Plain text, inline whitespace collapsed, trimmed
     */
    public $text;

    /**
     * @var string|null Language tag when it differs from the document's, else null
     */
    public $language;

    /**
     * @var ImageObject[] Images placed with this block
     */
    public $images = [];

    /**
     * @var Annotation[] Annotations whose target is a span of this block
     */
    public $annotations = [];

    public function __construct($subtype, $text, $language = null)
    {
        $this->subtype = $subtype;
        $this->text = $text;
        $this->language = $language;
    }
}
