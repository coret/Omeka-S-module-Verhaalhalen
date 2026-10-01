<?php
namespace Verhaalhalen\Stdlib;

/**
 * Accumulates the plain text of one block while inline markup is walked,
 * collapsing whitespace as it goes and keeping track of character offsets so
 * that annotations can point into the finished text.
 *
 * Whitespace is folded the way a browser renders it: any run of whitespace is
 * one space, and the text neither starts nor ends with one. Offsets are in
 * Unicode code points, which is what the spec's TextPositionSelector counts.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class RunBuilder
{
    /**
     * Everything that folds to a space, including the no-break and the
     * typographic spaces that \s alone would leave standing.
     */
    const WHITESPACE = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u';

    /**
     * @var Annotation[]
     */
    public $annotations = [];

    /**
     * @var ImageObject[]
     */
    public $images = [];

    /**
     * @var string
     */
    protected $text = '';

    /**
     * @var int Length of $text in code points
     */
    protected $length = 0;

    /**
     * @var bool A space is owed before the next non-space character
     */
    protected $pendingSpace = false;

    /**
     * @param string $text
     */
    public function appendText($text)
    {
        $text = preg_replace(self::WHITESPACE, ' ', (string) $text);
        if ('' === $text) {
            return;
        }
        $trimmed = trim($text, ' ');
        if ('' === $trimmed) {
            $this->pendingSpace = $this->pendingSpace || '' !== $this->text;
            return;
        }
        if (($this->pendingSpace || ' ' === $text[0]) && '' !== $this->text) {
            $this->text .= ' ';
            $this->length++;
        }
        $this->text .= $trimmed;
        $this->length += mb_strlen($trimmed, 'UTF-8');
        $this->pendingSpace = ' ' === substr($text, -1);
    }

    /**
     * A separator that is wanted literally, such as between table cells.
     *
     * @param string $separator
     */
    public function appendSeparator($separator)
    {
        $this->text .= $separator;
        $this->length += mb_strlen($separator, 'UTF-8');
        $this->pendingSpace = false;
    }

    /**
     * Where an annotated span starts: settles any owed space first, so that
     * the span never begins with one.
     *
     * @return int
     */
    public function mark()
    {
        if ($this->pendingSpace && '' !== $this->text) {
            $this->text .= ' ';
            $this->length++;
            $this->pendingSpace = false;
        }
        return $this->length;
    }

    /**
     * @return int
     */
    public function length()
    {
        return $this->length;
    }

    /**
     * The finished text, trimmed.
     *
     * @return string
     */
    public function text()
    {
        return trim($this->text, ' ');
    }
}
