# Verhaalhalen

Omeka S module that publishes site pages as **NDE Story** documents, the
[*NDE Story: Text Content API*](https://verhaalhalen.ruimdetijd.nl/spec/)
drafted for Verhaalhalen (Netwerk Digitaal Erfgoed / Ruimdetijd, v1.0 draft,
1 October 2026). A story becomes two JSON-LD documents:

| Document | `@type` | What it is |
|---|---|---|
| **Record** | `CreativeWork` | Descriptive metadata, a plain [SCHEMA-AP-NDE](https://docs.nde.nl/schema-profile/) record. Links to the content through `associatedMedia`. |
| **Content** | `["MediaObject", "TextObject"]` | The text itself: an ordered list of TextObjects, inline images and Web Annotations. |

Both are produced from the page's blocks on request, through Omeka's own API
output-format mechanism, in the way the
[GeoJson module](https://github.com/coret/Omeka-S-module-GeoJson) renders item
queries as GeoJSON.

The module was written for [Gouda Tijdmachine](https://www.goudatijdmachine.nl/),
whose long-form pages (Markdown and HTML blocks) are the stories, and doubles as
a test of the NDE Story model against a real CMS: what maps cleanly, what has to
be decided, and where the model and the SCHEMA-AP-NDE profile pull in different
directions. Those findings are in [Design decisions](#design-decisions) and
[Observations on the NDE Story model](#observations-on-the-nde-story-model).

## Requesting a story

There is no new route. Every URL is the page's API URL plus a `format`:

```bash
# the record (SCHEMA-AP-NDE CreativeWork)
curl 'https://example.org/api/site_pages/88?format=verhaalhalen'

# the content (NDE Story TextObject)
curl 'https://example.org/api/site_pages/88?format=verhaalhalen-content'

# a Collection listing the stories, with every API query argument still working
curl 'https://example.org/api/site_pages?site_id=2&format=verhaalhalen'
```

Add `pretty_print=1` for indented output, as with any Omeka API request.

`?format=` is the primary selector. Content negotiation works for the content
document when the `Accept` header is **exactly**
`application/ld+json;profile="https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld"`
(Omeka matches registered media types as strings, so a space after the `;`
falls back to ordinary JSON-LD). The record is served as `application/ld+json`,
the same media type as Omeka's default output, and is therefore reachable only
through `?format=verhaalhalen`.

Permissions are Omeka's: anonymous requests see public pages of public sites
and nothing else; a private page is a 404, as it is for the regular API. A
request for anything that is not a site page (`/api/items/1?format=verhaalhalen`)
is a 400 with an `errors` document. A page without a single block of text is a
404 for the content document, because the spec requires at least one TextObject.

Responses carry `ETag`, `Last-Modified`, `Vary: Accept`, a `Link` to the other
document and `Cache-Control: max-age=…` (configurable); a signed-in user gets
`private, no-store` instead, since the page may be private. A matching
`If-None-Match` is answered with `304`.

## What comes out

### Record (`format=verhaalhalen`)

```jsonc
{
  "@context": "https://schema.org",
  "@type": ["CreativeWork", "Article"],
  "@id": "https://example.org/api/site_pages/88?format=verhaalhalen",
  "name": {"@language": "nl", "@value": "Aanpak Goudse locatiepunten"},
  "identifier": "aanpak-goudse-locatiepunten",
  "url": {"@id": "https://example.org/s/data/page/aanpak-goudse-locatiepunten"},
  "inLanguage": "nl",
  "dateCreated": {"@type": "Date", "@value": "2026-09-20"},
  "dateModified": {"@type": "Date", "@value": "2026-09-25"},
  "sdDatePublished": {"@type": "http://www.w3.org/2001/XMLSchema#date", "@value": "2026-10-01"},
  "abstract": {"@language": "nl", "@value": "…"},          // from the Verhaalhalen block
  "description": {"@language": "nl", "@value": "…"},       // block, or the first paragraph
  "text": {"@language": "nl", "@value": "…"},              // the plain full text, derived
  "temporalCoverage": "1300/2026",                         // block
  "creator": {"@type": "Organization", "name": {…}, "url": {"@id": "…"}},   // configuration
  "license": {"@id": "https://creativecommons.org/licenses/by/4.0/"},        // block or configuration
  "isPartOf": {"@id": "https://n2t.net/ark:/60537/bD64Hu", "@type": "Dataset", "name": {…}},
  "additionalType": {"@type": "DefinedTerm", "name": {…}},
  "about": [ … ],                                          // block JSON-LD
  "associatedMedia": [
    {"@type": "ImageObject", "@id": "…?format=verhaalhalen#image-1",
     "contentUrl": {"@id": "…"}, "thumbnailUrl": {"@id": "…"}, "encodingFormat": "image/png",
     "license": {"@id": "…"}},
    {"@id": "…?format=verhaalhalen-content", "@type": ["MediaObject", "TextObject"],
     "contentUrl": {"@id": "…?format=verhaalhalen-content"},
     "thumbnailUrl": {"@id": "…?format=verhaalhalen-content"},
     "encodingFormat": "application/ld+json;profile='https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld'",
     "license": {"@id": "…"}}
  ]
}
```

### Content (`format=verhaalhalen-content`)

```jsonc
{
  "@context": [
    "https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld",
    {"@base": "https://example.org/api/site_pages/88?format=verhaalhalen", "@language": "nl"}
  ],
  "@type": ["MediaObject", "TextObject"],
  "@id": "https://example.org/api/site_pages/88?format=verhaalhalen-content",
  "encodesCreativeWork": {"@id": "https://example.org/api/site_pages/88?format=verhaalhalen"},
  "headline": "Aanpak Goudse locatiepunten",
  "alternativeHeadline": "…",                               // block subtitle, if any
  "hasPart": [
    {"@type": ["TextObject", "Quote"],     "@id": "#text-1", "text": "Leestijd: …"},
    {"@type": ["TextObject", "Head"],      "@id": "#head-1", "text": "Samenvatting"},
    {"@type": ["TextObject", "Paragraph"], "@id": "#text-2", "text": "Een locatiepunt is …",
     "associatedMedia": [{"@type": "ImageObject", "@id": "#image-1", "contentUrl": "…",
                          "encodingFormat": "image/svg+xml", "caption": "…", "rend": "right"}]}
  ],
  "annotations": [
    {"@id": "#annotation-1", "@type": "Annotation", "motivation": "linking",
     "target": {"source": "#text-2", "selector": {"@type": "TextPositionSelector", "start": 70, "end": 87}},
     "body": {"@id": "http://www.wikidata.org/entity/Q13395", "@type": "Thing", "url": "http://www.wikidata.org/entity/Q13395"}}
  ]
}
```

### Collection (`/api/site_pages?format=verhaalhalen`)

The spec leaves listing to the implementation. This mirrors Verhaalhalen's own
`/api/stories`, so a client written against the reference server reads it:

```json
{
  "@context": "https://verhaalhalen.ruimdetijd.nl/api/1/context.jsonld",
  "@type": "Collection",
  "@id": "https://example.org/api/site_pages?site_id=2&format=verhaalhalen",
  "name": "Gouda Tijdmachine",
  "hasPart": [
    {"@id": "https://example.org/api/site_pages/88?format=verhaalhalen",
     "@type": ["CreativeWork", "Article"],
     "name": {"@language": "nl", "@value": "Aanpak Goudse locatiepunten"}}
  ]
}
```

Only pages carrying a Verhaalhalen block are listed (see below); Omeka's
pagination (`per_page`, `page`, the `Link` and `Omeka-S-Total-Results` headers)
applies to the pages *before* that filter, so a page of results can hold fewer
stories than `per_page`, and the total counts all pages of the query.

## The Verhaalhalen page block

An Omeka page has a title and blocks, nothing else. The record wants an
abstract, a licence, a period, subject terms. The module adds a page block,
**Verhaalhalen (NDE Story)**, that holds them and renders nothing on the public
page except a `<link rel="alternate" type="application/ld+json">` to the record,
so that a client can find the story from the page.

| Field | Record property | Notes |
|---|---|---|
| Subtitle | `alternativeHeadline` (content) | rendered below the title |
| Abstract | `abstract` | |
| Description | `description` | when empty, the first `Opener` or `Paragraph` of the text is used (configurable) |
| Licence | `license` | a URL; also becomes the licence of the content entry |
| Period | `temporalCoverage` | an ISO 8601 interval such as `1572/1795` |
| Additional record properties | anything | a JSON object of Schema.org properties, merged into the record |

The JSON-LD field is where the subject access goes: `about`, `contentLocation`,
`genre`, … as `DefinedTerm`s with a `sameAs` to an authority, for example what the
[Network of Terms](https://termennetwerk.netwerkdigitaalerfgoed.nl/) returns.
It is written in the plain Schema.org context, exactly as it will appear in the
record. Its values win over the module's configuration. It may not set the keys
the module writes itself (`@context`, `@id`, `@type`, `name`, `text`, `hasPart`,
`headline`, `alternativeHeadline`, `associatedMedia`, `annotations`,
`encodesCreativeWork`); the editor refuses to save those.

The block is also the **marker**: with `only_marked` (the default) only pages
that have one appear in the collection. A single page is always rendered,
block or no block — the block then merely adds metadata and the configuration
supplies the rest.

Like any block it can be written through the API. A `PUT` on
`/api/site_pages/{id}` with the page's full `o:block` list plus

```json
{"o:layout": "verhaalhalen", "o:data": {
  "abstract": "Hoe Gouda Tijdmachine locatiepunten als vaste haak gebruikt.",
  "temporal_coverage": "1300/2026",
  "jsonld": "{\"about\": [{\"@type\": [\"Place\", \"DefinedTerm\"], \"name\": {\"@language\": \"nl\", \"@value\": \"Gouda\"}, \"sameAs\": {\"@id\": \"http://www.wikidata.org/entity/Q13395\"}}]}"
}}
```

marks the page and fills its record. (Use `PUT` with the complete block list,
not `PATCH`: a `PATCH` with `o:block` appends rather than replaces.)

## Configuration

Everything lives in the `verhaalhalen` array in `config/module.config.php`.
There is no admin UI (`configurable = false`); the file is the interface. The
shipped values are Gouda Tijdmachine's — a worked example, to be replaced.

| Key | Meaning |
|---|---|
| `base_url` | Scheme and host for every URL in the documents. `null` derives them from the request, which means the client's `Host` header decides what goes into the `@id`s; set the canonical URL on any site reachable under more than one name or behind a proxy. |
| `context_url` | The NDE Story context. The spec allows rewriting it to your own host. |
| `language` | Content language when the page's site has no `locale` setting. |
| `record_type` | Second `@type` of the record, next to `CreativeWork`: `Article`, `ShortStory`, … |
| `creator`, `publisher` | Organization nodes for the record; `null` omits. |
| `dataset` | `@id` and `name` of the dataset the stories belong to (`isPartOf`). |
| `license` | Licence of the text when the block gives none. |
| `image_license` | Licence of images that carry none of their own. |
| `additional_type`, `genre` | DefinedTerms, one or a list; `null` omits. |
| `description_fallback` | `first_paragraph` (the first `Opener` or `Paragraph`, so that a story opening with a block quote is not described by it) or `null`. |
| `only_marked` | List only pages with a Verhaalhalen block in the collection. |
| `max_age` | `Cache-Control: max-age` for anonymous responses. |
| `authority_prefixes` | Hyperlinks starting with one of these become `linking` annotations with the target as body `@id` (an authority IRI). |
| `float_right_classes` | Class names on an `<img>`, `<figure>` or `<p>` that mean `rend: "right"`. |
| `opener_classes`, `closer_classes` | Class names on a `<p>` that make it an `Opener` or a `Closer`. |
| `sites` | Overrides per site slug for any key above. |

Names in `creator`, `dataset` and the DefinedTerms are plain strings here and
are language-tagged on output with the document's language.

## Mapping tables

These tables are the module's reading of the spec. Every rule has a fixture in
[`test/fixtures/`](test/fixtures/) showing the HTML in and the JSON out.

### Omeka block → story fragment

| Block layout | Becomes | Notes |
|---|---|---|
| `html` (core) | TextObjects, images, annotations | the stored HTML, walked as below |
| `markdown` (module [Markdown](https://github.com/coret/Omeka-S-module-Markdown)) | the same | the block's rendered HTML: the stored copy while its fingerprint matches the converter, else a fresh conversion; without the module, the stored copy |
| `media` (core) | one ImageObject per attachment | `contentUrl` = original file, `thumbnailUrl` = large derivative, `encodingFormat` = media type, caption = attachment caption, else alt text; licence from the media's `dcterms:license`/`dcterms:rights` (URI or literal URL), else the item's, else `image_license`; non-images (a PDF) become a `MediaObject` |
| `asset` (core) | one ImageObject per asset | `assetUrl()`, media type, caption from the attachment, else alt text |
| `verhaalhalen` (this module) | record metadata; marker | renders nothing |
| `pageTitle`, `lineBreak`, `blockGroup`, `browsePreview`, `tableOfContents`, `listOfPages`, `listOfSites`, `oembed`, `iiifImage`, `iiifPresentation`, maps, search forms, … | nothing | not text |

An image from a `media`/`asset` block, or one standing between paragraphs,
belongs to the **preceding** TextObject (its `associatedMedia`); before the
first TextObject it belongs to the story as a whole.

### HTML block element → TextObject subtype

| HTML | Subtype | TEI element (spec §4.2.4) | Notes |
|---|---|---|---|
| `<h1>`…`<h6>` | `Head` | `<head>` | the level is not kept; the spec has one Head |
| `<p>` | `Paragraph` | `<p>` | |
| `<p class="lead">` (`opener_classes`) | `Opener` | `<opener>` | |
| `<p class="closer">` (`closer_classes`) | `Closer` | `<closer>` | |
| `<blockquote>` | `Quote` | `<quote>` | one Quote per `<p>` inside; one Quote when it holds bare text |
| `<aside>` | `FloatingText` | `<floatingText>` | one per `<p>` inside |
| `<figcaption>` without an `<img>` in its `<figure>`; `<caption>` of a table | `Caption` | `<figDesc>` | a figcaption *with* an image becomes that image's `caption` |
| `<li>` | `Paragraph` | — | nested lists are flattened; a `<li>` holding `<p>`s yields one block per `<p>` |
| `<dt>`, `<dd>` | `Paragraph` | — | |
| `<tr>` | `Paragraph` | — | cells joined with ` \| `, empty cells kept, so columns stay recognisable |
| `<pre>` | `Paragraph` | — | whitespace collapsed, as the spec requires of every `text` |
| `<summary>` | `Paragraph` | — | |
| `<div>`, `<section>`, `<article>`, `<details>`, `<ul>`, `<ol>`, `<dl>`, `<table>`, … | walked into | — | loose text directly inside a container becomes a Paragraph of its own |
| `<figure>`, `<img>` | ImageObject | — | see images below |
| `<script>`, `<style>`, `<svg>`, `<iframe>`, `<nav>`, `<form>`, `<hr>`, `<video>`, `<audio>`, `[aria-hidden=true]`, `[hidden]` | dropped | — | not text |
| empty blocks | dropped | — | not numbered either |

Text is the element's plain text with inline markup removed, every run of
whitespace (including no-break and typographic spaces) folded to one space, and
trimmed. A block whose `lang` attribute (own or inherited) differs from the
document language gets a language-tagged `text`.

### Inline element → Web Annotation

| Inline | Motivation | Body | Selector |
|---|---|---|---|
| `<a href="…">` with text, http(s) or relative, not `#…` | `linking` | `{"@id": href}` only when href starts with an `authority_prefixes` entry (or `data-id` is set); `"@type": data-type ?? "Thing"`; `"url": href` | `TextPositionSelector` on the block |
| `<mark>` | `oa:highlighting` | none | `TextPositionSelector` |
| `<span class="entity" data-type="…" title="…">` | `identifying` | `{"@type": data-type, "name": title}` | `TextPositionSelector` |
| `<a class="heading-permalink">`, `<a class="footnote-backref">`, `[aria-hidden=true]` | — | the CommonMark permalink `¶` and footnote `↩` are removed from the text | |
| `<sup class="footnote-ref">` | — | the footnote number stays in the text; the footnote itself becomes a Paragraph | |
| `<strong>`, `<em>`, `<code>`, `<del>`, other inline | — | text only | |

Offsets are in **Unicode code points** of the block's finished text. The
annotation `@id`s are numbered across the whole story.

### Image → ImageObject

| Source | `contentUrl` | `thumbnailUrl` (record) | `encodingFormat` | `caption` | `rend` | `license` (record) |
|---|---|---|---|---|---|---|
| `<img>` in HTML | `src` | = `contentUrl` | from the file extension (`jpg`, `png`, `gif`, `webp`, `svg`, `avif`, `tiff`, …), else omitted | `<figcaption>`, else `alt`, else `title` | `right` when the `<img>`, its `<figure>` or a `style="float: right"` says so | `image_license` |
| Omeka media | original file | large derivative | media type | attachment caption, else alt text | — | `dcterms:license`/`rights` of the media or its item, else `image_license` |
| Omeka asset | asset URL | = `contentUrl` | media type | attachment caption, else alt text | — | `image_license` |

The record lists every image with `@id`, `contentUrl`, `thumbnailUrl`,
`encodingFormat` and `license` (what the profile needs); the content lists the
same `@id` with `contentUrl`, `encodingFormat`, `caption` and `rend` (what a
renderer needs). `data:` URIs are ignored.

### Page, site and configuration → record

| Record property | Source |
|---|---|
| `@id` | the page's API URL with `?format=verhaalhalen` (`base_url` applied) |
| `@type` | `["CreativeWork", record_type]` |
| `name`, content `headline` | the page title |
| `identifier` | the page slug |
| `url` | the public page URL |
| `inLanguage`, content `@language` | the site's `locale` setting reduced to its language (`nl_NL` → `nl`), else the installation's locale, else `language` |
| `dateCreated`, `dateModified` | the page's created and modified dates, as `schema:Date` |
| `sdDatePublished` | the date of the request, as `xsd:date` |
| `abstract`, `description`, `temporalCoverage`, `license`, content `alternativeHeadline` | the Verhaalhalen block |
| `text` | all TextObject texts joined with a space (derived, as the spec requires) |
| `creator`, `publisher`, `isPartOf`, `additionalType`, `genre` | configuration |
| anything else (`about`, `contentLocation`, …) | the block's JSON-LD field |
| `associatedMedia` | every image, then the content entry |

### Identifiers

Fragments are numbered per kind in reading order: `#text-1`, `#head-1`,
`#image-1`, `#annotation-1`. Story-level images are numbered first, then the
images of each block as it is met.

## Design decisions

**Two formats rather than one.** The spec needs two documents at two URLs. Two
registered API output formats keep both inside Omeka's format mechanism (so
`Content-Type` and `Accept` are core's business) and need no new route. The
alternative, `?format=verhaalhalen&part=content`, would hide the second document
behind a parameter core knows nothing about.

**The API URL is the story URL.** The record's `@id` must be the story URL and
fragments resolve against it, so it has to dereference to the record. The API
URL with `?format=verhaalhalen` does; the public page URL would dereference to
HTML. The public page is in `schema:url`. The downside is an `@id` with a query
string, which JSON-LD handles (`#text-1` resolves to `…?format=verhaalhalen#text-1`)
but which no one would call pretty. `base_url` pins its host.

**A block, not a setting, holds the metadata.** Omeka has no per-page settings.
A block is editable in the page editor, writable through the API, versioned
with the page, and it doubles as the opt-in marker for the collection. Embedding
a `<script type="application/ld+json">` in the text was rejected (no editor,
fragile under HTML purification); configuration alone would give every story
the same subject terms.

**No file cache.** Unlike GeoJson, which streams tens of thousands of items, a
page is small: 400 KB of Markdown becomes a 220 KB content document in about
20 ms. Validators (`ETag`, `Last-Modified`, `304`) are enough.

**Images join the preceding block.** The spec distinguishes images of a block
from images of the story but says nothing about an image standing on its own
between two paragraphs, which is how Omeka's media blocks and Markdown's
`![…](…)` paragraphs present them. Attaching such an image to the block before
it keeps its position in the reading order; an image before any text is
story-level.

**Heads lose their level.** The spec has one `Head` subtype. Keeping `<h2>` and
`<h3>` apart would need a minted subtype; the module stays within the spec's
vocabulary.

**Sequential fragment ids.** Unique within the story is all the spec asks for.
Ids from the HTML (`<h2 id="samenvatting">`) would be stabler across edits but
are not guaranteed to exist or be unique, so they are not used (see the roadmap).

**Plain hyperlinks are `Thing`s.** A `linking` annotation must have a `@type`,
and an ordinary `<a href>` carries none. `Thing` is the honest answer; an author
can say more with `data-type`, and a target under `authority_prefixes` becomes the
body `@id`.

## Observations on the NDE Story model

Mapping a real CMS onto the model surfaced the following. They are offered as
input for the spec, each with what this module does in the meantime.

1. **The content entry does not pass the profile's shapes** (spec §3.1 says the
   record "validates against SCHEMA-AP-NDE as it stands"). The profile's
   `_:MediaObjectShape` requires `thumbnailUrl`, the content entry has none, and
   because `associatedMedia` is an `sh:or` of MediaObject and IIIF-manifest
   shapes the entry fails the whole property and the record does not conform.
   The spec's own example record fails the same way. The module adds a
   `thumbnailUrl` equal to the content URL, which is meaningless for a text but
   makes the record validate. Either the profile should exempt non-visual
   MediaObjects or the spec should require the thumbnail.
2. **`sdDatePublished` wants `xsd:date`** where the spec types every other date
   as `schema:Date`; the spec's example quietly does this too. Worth a sentence
   in §3.
3. **No subtype for lists, tables or code** (§4.2.4). Every `<li>` and `<tr>`
   becomes a Paragraph, which reads fine as text but loses the structure a
   renderer could use. The subtype list is open to full IRIs; a shared
   `story:ListItem` / `story:Row` would avoid every implementation minting its own.
4. **`Head` has no level.** A two-hour document with 69 headings on two levels
   becomes a flat list of Heads. `<head>` in TEI is level-free too, but HTML
   renderers of the content (§6, `<h3>` for every Head) lose the hierarchy.
5. **Standalone images between blocks are underdetermined** (§4.1.5 "Images"):
   neither "belongs to a block" nor "for the story as a whole" describes an
   image set between two paragraphs. The module attaches it to the preceding
   block; the spec could say so, or allow an ImageObject as an entry of `hasPart`.
6. **`linking` bodies require a `@type`** (§4.4.1) that an ordinary hyperlink
   does not carry. `Thing` is always true and rarely useful; making `@type`
   optional for `linking` would match what HTML gives.
7. **The unit of `TextPositionSelector`** is "characters" (§4.4.2). The module
   counts Unicode code points; a JavaScript client counting UTF-16 code units
   disagrees as soon as an emoji or a rare character precedes the span. The Web
   Annotation model says code points; the spec might say so explicitly.
8. **Fragment identifiers are not stable** across edits: inserting a paragraph
   renumbers everything after it, so an annotation made against yesterday's
   `#text-7` targets a different paragraph today. The spec could recommend
   author-supplied ids where the source has them.
9. **`url` versus `isBasedOn`** (§3.3): `isBasedOn` is "the upstream web page
   the story was harvested from", which does not fit a publisher serving its own
   stories. The module uses `schema:url` for the public page.
10. **`name` versus `headline`** (§2.3) are always equal here; a CMS has one
    title. The distinction costs nothing but a reader may wonder.
11. **The Collection is not specified.** The module copies the reference
    server's `/api/stories` shape. A sentence in §7 would make it normative.
12. **Pagination versus filtering.** Listing only marked pages inside a paginated
    API query means a page of results can be shorter than `per_page`; the
    alternative, a dedicated route, would leave Omeka's query arguments behind.

## Validating the output

The content document is defined by SHACL shapes; the record by SCHEMA-AP-NDE's.
With [Apache Jena](https://jena.apache.org/) (`riot`, `shacl`):

```bash
curl -s 'https://example.org/api/site_pages/88?format=verhaalhalen-content' -o content.json
curl -s 'https://example.org/api/site_pages/88?format=verhaalhalen' -o record.json
curl -s https://verhaalhalen.ruimdetijd.nl/api/1/shapes -o story-shapes.ttl
curl -sL https://raw.githubusercontent.com/netwerk-digitaal-erfgoed/schema-profile/main/shacl.ttl -o profile.ttl

riot --syntax=jsonld --output=nt content.json > content.nt
riot --syntax=jsonld --output=nt record.json \
  | sed 's#http://schema.org/#https://schema.org/#g' > record.nt   # the profile targets https://schema.org/

shacl validate --shapes story-shapes.ttl --data content.nt
shacl validate --shapes profile.ttl --data record.nt
```

Two things to know. The Schema.org context maps terms to `http://schema.org/`
while both shape files target `https://schema.org/`; without the `sed` the record
matches no shape and "conforms" vacuously. And Jena fetches the remote contexts
(`anno.jsonld`, the Schema.org context) at run time, which can be slow; pointing
`@context` at local copies avoids that.

Gouda Tijdmachine's pages 83 and 88 (253 and 710 TextObjects, 5 images, 66
annotations between them) conform to both sets of shapes with the module as
shipped, both as generated offline from the fixtures runner and as served live
by <https://www.goudatijdmachine.nl/omeka/api/site_pages/88?format=verhaalhalen-content>.

## Roadmap

- [ ] `text/html` form of the content (spec §6), for `Accept: text/html` on the content URL.
- [ ] `text/turtle` form of record and content (spec §7), through core's EasyRdf path.
- [ ] `?format=verhaalhalen` on the public page route (`/s/{site}/page/{slug}`), so the story URL could be the pretty one.
- [ ] Stable fragment identifiers from the HTML's own `id` attributes where present and unique.
- [ ] Annotations for motivations other than `linking`, `identifying` and `oa:highlighting` (e.g. `commenting` from footnotes).
- [ ] Minted subtypes for list items, table rows and code blocks, once the spec or a shared vocabulary provides them.
- [ ] A `<link rel="alternate">` for pages without a Verhaalhalen block (a layout listener), if unmarked stories turn out to matter.
- [ ] An admin configuration form, if the config file proves too much of a hurdle for other installations.

## Requirements

| | |
|---|---|
| Omeka S | `^4.0.0` |
| PHP | 8.1 or later, with ext-dom and ext-mbstring (both in Omeka's own requirements) |

No Composer packages, no other modules. The Markdown module is used when it is
active and ignored when it is not.

Developed and verified against Omeka S 4.2.1 on PHP 8.5.

## Installation

```bash
cd /path/to/omeka-s/modules
git clone https://github.com/coret/Omeka-S-module-Verhaalhalen.git Verhaalhalen
```

Then install it from **Modules** in the admin. The directory name **must** be
`Verhaalhalen`, matching the namespace; the repository name is not. Edit
`config/module.config.php` before anyone harvests: the shipped `creator`,
`dataset` and licences are Gouda Tijdmachine's.

## Tests

```bash
php test/run.php                 # the mapping, against the fixtures; --update rewrites them
OMEKA_PATH=/path/to/omeka-s php test/verify-wiring.php   # formats, error paths, URLs, block validation
```

Neither needs a database or a web server; `run.php` does not even need Omeka.
The fixtures are the executable form of the mapping tables above: add one for
every rule you change.

## Code style

Omeka S's own: PSR-2 plus core's additions, in `.php-cs-fixer.dist.php` copied
from core. `php vendor/bin/php-cs-fixer fix --dry-run --diff` with any PHP CS
Fixer 3.x.

## License

GPL-3.0-or-later, like Omeka S itself. The full text is in [`LICENSE`](LICENSE).
