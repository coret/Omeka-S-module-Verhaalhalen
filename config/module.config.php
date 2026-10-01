<?php
namespace Verhaalhalen;

return [
    'service_manager' => [
        'factories' => [
            'Verhaalhalen\Settings' => Service\Stdlib\SettingsFactory::class,
            'Verhaalhalen\Urls' => Service\Stdlib\UrlsFactory::class,
            'Verhaalhalen\BlockSource' => Service\Stdlib\BlockSourceFactory::class,
        ],
    ],
    'block_layouts' => [
        'factories' => [
            'verhaalhalen' => Service\BlockLayout\VerhaalhalenFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],

    'verhaalhalen' => [
        // Scheme and host of the story URLs (the record's @id, the @base the
        // fragment identifiers resolve against, the content URL). Null derives
        // them from the request, which means the client's Host header decides
        // what ends up in the documents. Set this to your canonical URL on any
        // site reachable under more than one name, or fronted by a proxy.
        //
        //   'base_url' => 'https://example.org',
        'base_url' => null,

        // The NDE Story JSON-LD context. The spec allows a server to rewrite
        // this to its own host; every other IRI is canonical.
        'context_url' => 'https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld',

        // Language of the content when the page's site has no locale setting.
        // A site locale such as nl_NL is reduced to its language tag, nl.
        'language' => 'nl',

        // The record's @type is ["CreativeWork", <record_type>]. The spec
        // suggests ShortStory or Article.
        'record_type' => 'Article',

        // Record properties that are the same for every story on this
        // installation. Names are plain strings here and are language-tagged
        // on output. Set an entry to null to omit it.
        'creator' => [
            '@type' => 'Organization',
            'name' => 'Gouda Tijdmachine',
            'url' => 'https://www.goudatijdmachine.nl/',
        ],
        'publisher' => null,

        // The dataset the records belong to (schema:isPartOf), as registered
        // in the NDE dataset register.
        'dataset' => [
            '@id' => 'https://n2t.net/ark:/60537/bD64Hu',
            'name' => 'Gouda Tijdmachine 🕓 Kennisgraaf',
        ],

        // Licence of the text, unless the page's Verhaalhalen block says
        // otherwise, and of images that carry no licence of their own.
        'license' => 'https://creativecommons.org/licenses/by/4.0/',
        'image_license' => 'https://creativecommons.org/licenses/by/4.0/',

        // DefinedTerms the profile asks for. Either may be null, or a list of
        // several terms. A "sameAs" IRI is optional.
        'additional_type' => [
            '@type' => 'DefinedTerm',
            'name' => 'verhaal',
        ],
        'genre' => null,

        // When the Verhaalhalen block gives no description: "first_paragraph"
        // uses the first text block of the story, null omits the property.
        'description_fallback' => 'first_paragraph',

        // Only pages carrying a Verhaalhalen block are listed in the
        // collection (/api/site_pages?format=verhaalhalen). A single page is
        // always rendered, block or no block; the block then merely adds
        // metadata.
        'only_marked' => true,

        // Cache-Control max-age for anonymous responses, in seconds.
        'max_age' => 3600,

        // A hyperlink whose target starts with one of these becomes a linking
        // annotation whose body @id is that target (an authority IRI). Any
        // other hyperlink only gets the target as its body url.
        'authority_prefixes' => [
            'http://www.wikidata.org/entity/',
            'https://www.wikidata.org/wiki/',
            'https://sws.geonames.org/',
            'http://vocab.getty.edu/aat/',
            'https://www.goudatijdmachine.nl/omeka/api/items/',
        ],

        // Class names (on an <img>, a <figure> or a <p>) that map to the
        // spec's rendering hints and TextObject subtypes.
        'float_right_classes' => ['float-right', 'right', 'align-right', 'img-right', 'pull-right'],
        'opener_classes' => ['lead', 'opener', 'intro'],
        'closer_classes' => ['closer'],

        // Overrides per site slug, for installations whose sites publish on
        // behalf of different organisations. Any key above may appear here.
        //
        //   'sites' => [
        //       'archief' => ['creator' => ['@type' => 'Organization', 'name' => 'Streekarchief']],
        //   ],
        'sites' => [],
    ],
];
