<?php
namespace Verhaalhalen\Stdlib;

/**
 * An image (or other media file) used in the story.
 *
 * The same node appears in both documents: the record lists what the profile
 * needs (rights and retrievability), the content what a renderer needs
 * (placement and caption). Both are kept here.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class ImageObject
{
    /**
     * @var string|null The fragment identifier, such as "#image-1", once assigned
     */
    public $id;

    /**
     * @var string Schema.org type: ImageObject, or MediaObject for non-images
     */
    public $type = 'ImageObject';

    /**
     * @var string
     */
    public $contentUrl;

    /**
     * @var string|null
     */
    public $thumbnailUrl;

    /**
     * @var string|null Media type, such as image/jpeg
     */
    public $encodingFormat;

    /**
     * @var string|null
     */
    public $caption;

    /**
     * @var string|null The spec's rendering hint; only "right" is defined
     */
    public $rend;

    /**
     * @var string|null Licence IRI, when the image carries one of its own
     */
    public $license;

    public function __construct($contentUrl)
    {
        $this->contentUrl = $contentUrl;
    }

    /**
     * Guess the media type of a URL from its extension.
     *
     * @param string $url
     * @return string|null
     */
    public static function encodingFormatFromUrl($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return null;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'avif' => 'image/avif', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
            'bmp' => 'image/bmp', 'jp2' => 'image/jp2',
        ];
        return $types[$extension] ?? null;
    }
}
