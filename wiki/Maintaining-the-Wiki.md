For maintainers: where these pages come from, the rules they follow, and how to publish a change.
Readers of the package do not need this page.

## Where the sources live

The pages are Markdown files in the [`wiki/`](https://github.com/boysfromthefactory/groundhog/tree/master/wiki)
directory of the package repository, one file per page. Edit them there, in the same pull request
as the code change they describe, never in the GitHub wiki editor: the wiki is overwritten from
`wiki/` on every publication.

The directory is listed in `.gitattributes` as `export-ignore`, so it is not part of the installed
package.

| File | Page |
|---|---|
| `Home.md` | the landing page |
| `<Title-With-Hyphens>.md` | one page; the file name is the page title with spaces replaced by `-` |
| `_Sidebar.md` | the navigation shown on every page |
| `_Footer.md` | the footer shown on every page, including the version the pages describe |

## Writing rules

- **One topic per page.** New pages are added to `_Sidebar.md` and to the contents list on
  [Home](Home), in reading order.
- **No `#` title.** GitHub shows the file name as the page title, so a page starts with its lead
  paragraph and uses `##` and deeper headings only.
- **Stable headings.** `##` headings are link targets for other pages; rename one only together
  with every link to it.
- **Links.** Link to another page by its file name without `.md`, for example `[Errors](Errors)`,
  or to one of its headings with `[horizon](Configuration#horizon)`. Link to repository files with
  absolute `https://github.com/boysfromthefactory/groundhog/blob/master/...` URLs.
- **One running example.** Use the `Meeting` model (title, location, capacity, room, start and
  end, soft deletes) and the weekly Monday "Standup" in "Room A" starting 2 March 2026 at
  09:00–10:00. Use `Shift` (start only) when a model without an end is needed. When a result
  depends on today's date, say "assuming today is 1 March 2026".
- **Every stated result is tested.** Show results as `// => ...` comments or a "Result:" sentence
  with concrete values. Each one must match an automated test in `tests/Feature`; the test suite
  uses the same data and a clock frozen at 1 March 2026. If you document behaviour no test pins
  yet, add the test in the same pull request.
- **No placeholders.** A page is published complete or not at all.

## Checking links before publishing

From the repository root, this lists every relative link whose page does not exist:

```bash
grep -ohE '\]\([A-Za-z0-9-]+(#[a-z0-9-]+)?\)' wiki/*.md \
  | sed -E 's/^\]\(([^#)]+).*/\1/' | sort -u \
  | while read -r page; do [ -f "wiki/$page.md" ] || echo "missing page: $page"; done
```

No output means every page link resolves. Check anchors by opening the target page and comparing
the heading: GitHub builds an anchor by lower-casing the heading, replacing spaces with `-` and
dropping punctuation, so `## Changing only the duration` becomes `#changing-only-the-duration`.

## First publication

GitHub creates a wiki's Git repository only after its first page has been saved. For this
repository that is done: the wiki is enabled and `groundhog.wiki.git` exists. For a fork or a new
repository:

1. In the repository settings, under **Features**, enable **Wikis**.
2. Open the **Wiki** tab, choose **Create the first page**, and save it with any text. Publishing
   replaces it.

## Publishing an update

Publishing copies `wiki/` over the wiki repository and pushes it. Pages that were removed from
`wiki/` are removed from the wiki too.

```bash
git clone git@github.com:boysfromthefactory/groundhog.wiki.git /tmp/groundhog.wiki
rsync -av --delete --exclude .git wiki/ /tmp/groundhog.wiki/
cd /tmp/groundhog.wiki
git add -A
git commit -m "docs: sync from groundhog@$(git -C "$OLDPWD" rev-parse --short HEAD)"
git push
cd - && rm -rf /tmp/groundhog.wiki
```

Run it from the repository root, on the commit you want to publish. If nothing changed, `git
commit` reports "nothing to commit" and there is nothing to push.

## Releasing a version

The footer says which package version the pages describe. When tagging a release:

1. Change `` `dev-master` (unreleased) `` in `wiki/_Footer.md` to the tag, for example `` `v1.0.0` ``.
2. Commit it with the release.
3. Publish the wiki from the tagged commit.

After the release, set the footer back to `` `dev-master` (unreleased) `` as soon as the pages
describe changes that are not released yet.
