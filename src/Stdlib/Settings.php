<?php
namespace Verhaalhalen\Stdlib;

/**
 * The module's configuration, with per-site overrides applied.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Settings
{
    /**
     * @var array
     */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * The settings in force for a site: the defaults, with any entry the
     * site's own block in "sites" replaces.
     *
     * @param string|null $siteSlug
     * @return array
     */
    public function forSite($siteSlug)
    {
        $settings = $this->config;
        unset($settings['sites']);
        if ($siteSlug && !empty($this->config['sites'][$siteSlug]) && is_array($this->config['sites'][$siteSlug])) {
            $settings = array_replace($settings, $this->config['sites'][$siteSlug]);
        }
        return $settings;
    }

    /**
     * Changes whenever the configuration does, so that cached responses built
     * under an older configuration stop validating.
     *
     * @return string
     */
    public function fingerprint()
    {
        return md5(json_encode($this->config));
    }

    /**
     * Reduce a locale such as nl_NL to the language tag the content uses.
     *
     * The spec tags text with BCP 47 language tags; Omeka's locales are the
     * underscore form with a region. The region says nothing a text consumer
     * needs, so only the language is kept.
     *
     * @param string|null $locale
     * @param string $fallback
     * @return string
     */
    public static function languageTag($locale, $fallback)
    {
        $locale = trim((string) $locale);
        if ('' === $locale) {
            return $fallback;
        }
        $primary = strtolower(preg_split('/[-_]/', $locale)[0]);
        return '' !== $primary ? $primary : $fallback;
    }
}
