<?php
namespace Verhaalhalen\Stdlib;

/**
 * A W3C Web Annotation on a span of the text.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Annotation
{
    /**
     * @var string|null The fragment identifier, such as "#annotation-1", once assigned
     */
    public $id;

    /**
     * @var string A Web Annotation motivation: linking, identifying, oa:highlighting, ...
     */
    public $motivation;

    /**
     * @var TextObject|null The block the span is in; null targets the whole content
     */
    public $target;

    /**
     * @var int|null TextPositionSelector start, in characters of the block's text
     */
    public $start;

    /**
     * @var int|null TextPositionSelector end
     */
    public $end;

    /**
     * @var string|null TextQuoteSelector exact match, for whole-content targets
     */
    public $exact;

    /**
     * @var array|null The body, in the form the spec fixes for the motivation
     */
    public $body;

    public function __construct($motivation, ?TextObject $target, $start, $end, ?array $body = null)
    {
        $this->motivation = $motivation;
        $this->target = $target;
        $this->start = $start;
        $this->end = $end;
        $this->body = $body;
    }
}
