# Translations

Shipped catalogues:

| File | Locale | Notes |
|---|---|---|
| `template.pot` | — | Extracted source strings (3). |
| `en.po` / `en.mo` | `en` | Identity catalogue. English is the source language, but an explicit `en` locale then resolves instead of falling through to the raw msgid. |
| `nl_NL.po` / `nl_NL.mo` | `nl_NL` | Dutch. Matches the filename Omeka core uses (`application/language/nl_NL.po`). |
| `nl.po` / `nl.mo` | `nl` | Same Dutch content, so a plain `nl` locale resolves too. |

There is no configuration UI, so the module has very few strings: the name and
description from `config/module.ini` (Omeka runs these through the translator in
the module list) and the one error message the module can return.

## Regenerating after changing a string

The catalogues are small enough to maintain by hand. After adding or changing a
translatable string:

1. Add the new `msgid` to `template.pot`, with a `#:` reference to its source.
2. Add the matching entry to `en.po` and `nl_NL.po`.
3. Regenerate `nl.po` from the Dutch catalogue and recompile:

```sh
cd modules/Verhaalhalen/language
sed 's/^"Language: nl_NL/"Language: nl/' nl_NL.po > nl.po
for f in en nl nl_NL; do msgfmt --check -o "$f.mo" "$f.po"; done
```

4. Confirm every catalogue still covers the template:

```sh
msgcmp nl_NL.po template.pot
msgcmp en.po template.pot
```

Register and terminology follow Omeka S's own Dutch catalogue.
