<?php
namespace Verhaalhalen\Stdlib;

/**
 * Assembles a Story from a page described as an ordered list of fragments.
 *
 * The fragments are plain arrays — "html" (a block's HTML) or "image" (a media
 * or asset attachment) — so this class needs no Omeka to run. BlockSource is
 * what turns a real site page into this form.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class StoryBuilder
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
     * @param array $input Keys: title, language, fragments, metadata
     * @return Story
     */
    public function build(array $input)
    {
        $metadata = $input['metadata'] ?? [];
        $language = $input['language'] ?? ($this->settings['language'] ?? 'nl');
        $subtitle = trim((string) ($metadata['subtitle'] ?? ''));
        $story = new Story((string) ($input['title'] ?? ''), $language, '' !== $subtitle ? $subtitle : null);

        $converter = new HtmlToStory($this->settings);
        foreach ($input['fragments'] ?? [] as $fragment) {
            switch ($fragment['type'] ?? null) {
                case 'html':
                    $result = $converter->convert($fragment['html'] ?? '', $fragment['language'] ?? $language);
                    // Images met before this fragment's first block belong to
                    // the block that precedes the fragment, if there is one.
                    foreach ($result['images'] as $image) {
                        $this->attach($story, $image);
                    }
                    foreach ($result['parts'] as $part) {
                        $story->parts[] = $part;
                    }
                    break;

                case 'image':
                    $image = $this->imageFromFragment($fragment);
                    if ($image) {
                        $this->attach($story, $image);
                    }
                    break;

                default:
                    break;
            }
        }

        $story->assignIds();
        return $story;
    }

    protected function attach(Story $story, ImageObject $image)
    {
        if ($story->parts) {
            $story->parts[count($story->parts) - 1]->images[] = $image;
        } else {
            $story->images[] = $image;
        }
    }

    /**
     * @return ImageObject|null
     */
    protected function imageFromFragment(array $fragment)
    {
        $url = trim((string) ($fragment['contentUrl'] ?? ''));
        if ('' === $url) {
            return null;
        }
        $image = new ImageObject($url);
        $image->encodingFormat = $fragment['encodingFormat'] ?? ImageObject::encodingFormatFromUrl($url);
        if ($image->encodingFormat && 0 !== strpos($image->encodingFormat, 'image/')) {
            $image->type = 'MediaObject';
        }
        foreach (['thumbnailUrl', 'caption', 'rend', 'license'] as $key) {
            $value = $fragment[$key] ?? null;
            if (null !== $value && '' !== trim((string) $value)) {
                $image->$key = 'caption' === $key
                    ? trim(preg_replace(RunBuilder::WHITESPACE, ' ', $value))
                    : $value;
            }
        }
        return $image;
    }
}
