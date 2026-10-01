<?php
namespace Verhaalhalen\Stdlib;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Turns an HTML fragment into the spec's flat list of TextObjects.
 *
 * This is the heart of the module and deliberately knows nothing about Omeka:
 * HTML in, TextObjects, ImageObjects and Annotations out. The README's mapping
 * tables describe what it does; test/fixtures/ shows it.
 *
 * The walk is depth-first. Block elements become TextObjects of the subtype
 * the element implies (a heading is a Head, a blockquote's paragraphs are
 * Quotes, ...). Containers are walked into; stretches of inline content inside
 * a container become a TextObject of their own. Inline markup is reduced to
 * plain text, and the few inline constructs the spec can express — hyperlinks,
 * <mark>, entity spans — become Web Annotations with character offsets.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class HtmlToStory
{
    /**
     * Elements whose content is not text: dropped, including their children.
     */
    const SKIP = [
        'script', 'style', 'svg', 'math', 'iframe', 'nav', 'form', 'button', 'input', 'select',
        'textarea', 'template', 'noscript', 'canvas', 'video', 'audio', 'object', 'embed',
        'map', 'area', 'link', 'meta', 'head', 'title', 'hr',
    ];

    /**
     * Elements that start a new block, as opposed to inline content.
     */
    const BLOCK = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'blockquote', 'aside', 'figure',
        'figcaption', 'pre', 'div', 'section', 'article', 'main', 'header', 'footer', 'details',
        'summary', 'address', 'fieldset', 'legend', 'body', 'html',
    ];

    /**
     * Inline elements whose text must not appear: CommonMark's heading
     * permalink symbol and footnote back-references.
     */
    const DROP_CLASSES = ['heading-permalink', 'footnote-backref'];

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var TextObject[]
     */
    protected $parts;

    /**
     * @var ImageObject[] Images met before the first block of this fragment
     */
    protected $looseImages;

    /**
     * @var TextObject|null
     */
    protected $lastPart;

    /**
     * @var string
     */
    protected $documentLanguage;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param string $html
     * @param string $documentLanguage The content's @language
     * @return array Keys: parts (TextObject[]), images (ImageObject[] not belonging to any block)
     */
    public function convert($html, $documentLanguage)
    {
        $this->parts = [];
        $this->looseImages = [];
        $this->lastPart = null;
        $this->documentLanguage = $documentLanguage;

        $html = trim((string) $html);
        if ('' !== $html) {
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            // The XML declaration is what makes libxml read the bytes as UTF-8.
            // The wrapper gives NOIMPLIED a single root; several top-level
            // siblings would otherwise be nested into each other.
            $document->loadHTML(
                '<?xml encoding="utf-8"?><div>' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $root = $document->documentElement;
            if ($root) {
                $this->walkChildren($root, null, $documentLanguage);
            }
        }

        return ['parts' => $this->parts, 'images' => $this->looseImages];
    }

    /**
     * Walk the children of a container, turning runs of inline content into
     * blocks of their own and descending into block children.
     *
     * @param string|null $override Subtype forced by an ancestor (Quote, FloatingText)
     * @param string $language Language in effect
     */
    protected function walkChildren(DOMNode $parent, $override, $language)
    {
        $run = [];
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($this->isBlock($child)) {
                $this->emitRun($run, $override ?: 'Paragraph', $language);
                $run = [];
                $this->walkBlock($child, $override, $language);
            } else {
                $run[] = $child;
            }
        }
        $this->emitRun($run, $override ?: 'Paragraph', $language);
    }

    /**
     * @param string|null $override
     * @param string $language
     */
    protected function walkBlock(DOMElement $element, $override, $language)
    {
        $tag = strtolower($element->tagName);
        $language = $this->languageOf($element, $language);

        if (in_array($tag, self::SKIP, true) || $this->isHidden($element)) {
            return;
        }

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $this->emitElement($element, 'Head', $language);
                return;

            case 'p':
                $subtype = $override ?: $this->paragraphSubtype($element);
                $this->emitMixed($element, $subtype, $override, $language);
                return;

            case 'blockquote':
                $this->emitMixed($element, 'Quote', 'Quote', $language);
                return;

            case 'aside':
                $this->emitMixed($element, 'FloatingText', 'FloatingText', $language);
                return;

            case 'figure':
                $this->walkFigure($element, $override, $language);
                return;

            case 'figcaption':
            case 'caption':
                $this->emitMixed($element, 'Caption', $override, $language);
                return;

            case 'tr':
                $this->emitRow($element, $override ?: 'Paragraph', $language);
                return;

            case 'pre':
            case 'li':
            case 'dt':
            case 'dd':
            case 'summary':
            case 'legend':
                $this->emitMixed($element, $override ?: 'Paragraph', $override, $language);
                return;

            default:
                // ul, ol, dl, table, thead, tbody, tfoot, div, section, article,
                // main, header, footer, details, address, fieldset, and anything
                // unknown: a container to walk into.
                $this->walkChildren($element, $override, $language);
                return;
        }
    }

    /**
     * An element that holds either inline content or further blocks.
     *
     * A <li> may be "text" or "<p>text</p><ul>...</ul>"; the first is one
     * block, the second is walked.
     */
    protected function emitMixed(DOMElement $element, $subtype, $override, $language)
    {
        foreach ($element->childNodes as $child) {
            if ($this->isBlock($child)) {
                $this->walkChildren($element, $override ?: ('Paragraph' === $subtype ? null : $subtype), $language);
                return;
            }
        }
        $this->emitElement($element, $subtype, $language);
    }

    /**
     * A figure is its images with their caption; without images it is an
     * ordinary container whose figcaption becomes a Caption block.
     */
    protected function walkFigure(DOMElement $figure, $override, $language)
    {
        $images = [];
        foreach ($figure->getElementsByTagName('img') as $img) {
            $images[] = $img;
        }
        if (!$images) {
            $this->walkChildren($figure, $override, $language);
            return;
        }

        $caption = null;
        foreach ($figure->childNodes as $child) {
            if ($child instanceof DOMElement && 'figcaption' === strtolower($child->tagName)) {
                $caption = $this->plainText($child);
                break;
            }
        }
        foreach ($images as $index => $img) {
            $image = $this->image($img, 0 === $index ? $caption : null, $figure);
            if ($image) {
                $this->attachImage($image);
            }
        }
    }

    /**
     * A table row: its cells joined with " | ", so that a column stays
     * recognisable in the plain text.
     */
    protected function emitRow(DOMElement $row, $subtype, $language)
    {
        $builder = new RunBuilder();
        $first = true;
        foreach ($row->childNodes as $cell) {
            if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                continue;
            }
            if (!$first) {
                $builder->appendSeparator(' | ');
            }
            $first = false;
            $this->walkInline($cell, $builder, $language);
        }
        $this->emitBuilder($builder, $subtype, $language);
    }

    /**
     * Emit a block from an element's inline content.
     */
    protected function emitElement(DOMElement $element, $subtype, $language)
    {
        $builder = new RunBuilder();
        foreach ($element->childNodes as $child) {
            $this->walkInline($child, $builder, $language);
        }
        $this->emitBuilder($builder, $subtype, $language);
    }

    /**
     * Emit a block from loose inline nodes found between block siblings.
     *
     * @param DOMNode[] $nodes
     */
    protected function emitRun(array $nodes, $subtype, $language)
    {
        if (!$nodes) {
            return;
        }
        $builder = new RunBuilder();
        foreach ($nodes as $node) {
            $this->walkInline($node, $builder, $language);
        }
        $this->emitBuilder($builder, $subtype, $language);
    }

    protected function emitBuilder(RunBuilder $builder, $subtype, $language)
    {
        $text = $builder->text();
        if ('' === $text) {
            // A paragraph holding only an image: the image stays, the block
            // does not.
            foreach ($builder->images as $image) {
                $this->attachImage($image);
            }
            return;
        }
        $part = new TextObject($subtype, $text, $this->tagFor($language));
        $part->images = $builder->images;
        foreach ($builder->annotations as $annotation) {
            $annotation->target = $part;
            $part->annotations[] = $annotation;
        }
        $this->parts[] = $part;
        $this->lastPart = $part;
    }

    /**
     * Reduce inline content to text, collecting annotations and images.
     */
    protected function walkInline(DOMNode $node, RunBuilder $builder, $language)
    {
        if ($node instanceof DOMText) {
            $builder->appendText($node->nodeValue);
            return;
        }
        if (!$node instanceof DOMElement) {
            return;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::SKIP, true) || $this->isHidden($node) || $this->hasClass($node, self::DROP_CLASSES)) {
            return;
        }

        switch ($tag) {
            case 'br':
                $builder->appendText(' ');
                return;

            case 'img':
                $image = $this->image($node, null, null);
                if ($image) {
                    $builder->images[] = $image;
                }
                return;

            case 'a':
                $start = $builder->mark();
                $this->walkInlineChildren($node, $builder, $language);
                $end = $builder->length();
                $body = $this->linkBody($node);
                if ($end > $start && $body) {
                    $builder->annotations[] = new Annotation('linking', null, $start, $end, $body);
                }
                return;

            case 'mark':
                $start = $builder->mark();
                $this->walkInlineChildren($node, $builder, $language);
                $end = $builder->length();
                if ($end > $start) {
                    $builder->annotations[] = new Annotation('oa:highlighting', null, $start, $end);
                }
                return;

            case 'span':
                if ($this->hasClass($node, ['entity']) && '' !== $node->getAttribute('data-type')) {
                    $start = $builder->mark();
                    $this->walkInlineChildren($node, $builder, $language);
                    $end = $builder->length();
                    if ($end > $start) {
                        $body = ['@type' => $node->getAttribute('data-type')];
                        $name = trim($node->getAttribute('title'));
                        if ('' !== $name) {
                            $body['name'] = $name;
                        }
                        $builder->annotations[] = new Annotation('identifying', null, $start, $end, $body);
                    }
                    return;
                }
                $this->walkInlineChildren($node, $builder, $language);
                return;

            default:
                // A block element inside inline content is invalid HTML; its
                // text is kept as part of the run rather than lost.
                $this->walkInlineChildren($node, $builder, $language);
                return;
        }
    }

    protected function walkInlineChildren(DOMNode $node, RunBuilder $builder, $language)
    {
        foreach ($node->childNodes as $child) {
            $this->walkInline($child, $builder, $language);
        }
    }

    /**
     * The body of a linking annotation, or null for links that are not one.
     *
     * @return array|null
     */
    protected function linkBody(DOMElement $anchor)
    {
        $href = trim($anchor->getAttribute('href'));
        if ('' === $href || '#' === $href[0]) {
            return null;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ('' !== $scheme && !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $body = [];
        $id = trim($anchor->getAttribute('data-id'));
        if ('' === $id) {
            foreach ($this->settings['authority_prefixes'] ?? [] as $prefix) {
                if ($prefix && 0 === strpos($href, $prefix)) {
                    $id = $href;
                    break;
                }
            }
        }
        if ('' !== $id) {
            $body['@id'] = $id;
        }
        $type = trim($anchor->getAttribute('data-type'));
        $body['@type'] = '' !== $type ? $type : 'Thing';
        $body['url'] = $href;
        return $body;
    }

    /**
     * @param DOMElement|null $figure The figure the image sits in, if any
     * @return ImageObject|null
     */
    protected function image(DOMElement $img, $caption, ?DOMElement $figure)
    {
        $src = trim($img->getAttribute('src'));
        if ('' === $src || 0 === strpos($src, 'data:')) {
            return null;
        }
        $image = new ImageObject($src);
        $image->encodingFormat = ImageObject::encodingFormatFromUrl($src);

        if (null === $caption || '' === $caption) {
            $caption = trim($img->getAttribute('alt'));
        }
        if ('' === $caption) {
            $caption = trim($img->getAttribute('title'));
        }
        if ('' !== $caption) {
            $image->caption = $this->collapse($caption);
        }

        if ($this->floatsRight($img) || ($figure && $this->floatsRight($figure))) {
            $image->rend = 'right';
        }
        return $image;
    }

    protected function attachImage(ImageObject $image)
    {
        if ($this->lastPart) {
            $this->lastPart->images[] = $image;
        } else {
            $this->looseImages[] = $image;
        }
    }

    /**
     * @return bool
     */
    protected function floatsRight(DOMElement $element)
    {
        if ($this->hasClass($element, $this->settings['float_right_classes'] ?? [])) {
            return true;
        }
        $style = strtolower(str_replace(' ', '', $element->getAttribute('style')));
        return false !== strpos($style, 'float:right');
    }

    /**
     * @return string
     */
    protected function paragraphSubtype(DOMElement $p)
    {
        if ($this->hasClass($p, $this->settings['opener_classes'] ?? [])) {
            return 'Opener';
        }
        if ($this->hasClass($p, $this->settings['closer_classes'] ?? [])) {
            return 'Closer';
        }
        return 'Paragraph';
    }

    /**
     * @return bool
     */
    protected function hasClass(DOMElement $element, array $classes)
    {
        if (!$classes || !$element->hasAttribute('class')) {
            return false;
        }
        $own = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        return (bool) array_intersect(array_map('strtolower', $own), array_map('strtolower', $classes));
    }

    /**
     * @return bool
     */
    protected function isHidden(DOMElement $element)
    {
        return 'true' === strtolower($element->getAttribute('aria-hidden'))
            || $element->hasAttribute('hidden');
    }

    /**
     * @return bool
     */
    protected function isBlock(DOMNode $node)
    {
        if (!$node instanceof DOMElement) {
            return false;
        }
        $tag = strtolower($node->tagName);
        return in_array($tag, self::BLOCK, true) || in_array($tag, self::SKIP, true);
    }

    /**
     * @return string
     */
    protected function languageOf(DOMElement $element, $inherited)
    {
        $lang = trim($element->getAttribute('lang'));
        return '' !== $lang ? $lang : $inherited;
    }

    /**
     * The language to tag a block with: null when it is the document's own.
     *
     * @return string|null
     */
    protected function tagFor($language)
    {
        return strtolower($language) === strtolower($this->documentLanguage) ? null : $language;
    }

    /**
     * @return string
     */
    protected function plainText(DOMNode $node)
    {
        return $this->collapse($node->textContent);
    }

    /**
     * @return string
     */
    protected function collapse($text)
    {
        return trim(preg_replace(RunBuilder::WHITESPACE, ' ', $text));
    }
}
