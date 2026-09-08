# Markdown Loose Lists Wrap Items in `<p>` and Silently Break Your `li > a` CSS

## Symptom

You render a nested Markdown list into a page and add CSS like:

```css
li.has-children > a::before { content: '▸'; }
```

Nothing shows up. The styles for list items work, the links work, but selectors that target **direct children** of the `<li>` (arrows, padding tweaks, icons) silently do nothing. Firefox DevTools shows the element is there, the class is applied — but the CSS never matches.

Then you inspect the generated DOM and see the trap:

```html
<!-- expected -->
<li><a href="#">General</a><ul>...</ul></li>

<!-- actual -->
<li><p><a href="#">General</a></p><ul>...</ul></li>
```

The `<a>` is wrapped in a `<p>`. `li > a` matches nothing. Everything downstream that assumed `a` is a direct child breaks: CSS selectors, `querySelector(':scope > a')`, padding offsets, you name it.

## Principle: Loose vs. Tight Markdown Lists

Markdown (CommonMark / GFM) distinguishes two kinds of lists:

- **Tight list** — list items are separated by *no blank lines* between them. The item content is rendered directly inside the `<li>`.
- **Loose list** — list items (or the list as a whole) are separated by *blank lines*. The item's block content is wrapped in `<p>` tags.

The exact rule: if there is a blank line between any two items, the **entire list** becomes loose, and *every* item's content gets wrapped:

```markdown
- [General](#)
- [Storage](#)
```
renders as:
```html
<li><a href="#">General</a></li>
<li><a href="#">Storage</a></li>
```

But:
```markdown
- [General](#)

- [Storage](#)
```
renders as:
```html
<li><p><a href="#">General</a></p></li>
<li><p><a href="#">Storage</a></p></li>
```

One blank line anywhere in the list → every item wraps in `<p>`. This is subtle because visually the Markdown source looks almost identical.

In nested lists this is especially nasty: you write a parent item, a blank line, then its children — which is a very natural way to write — and the parent's `<a>` ends up inside a `<p>`, breaking `li > a` selectors and `li.querySelector(':scope > a')` code.

## Reproduction

```markdown
# Menu

- [General](#)
  - [Categories](#)
  - [Notes](#)
```
`marked.parse(..., { gfm: true })` gives:
```html
<li><a href="#">General</a><ul><li>Categories</li><li>Notes</li></ul></li>
```
No `<p>` — tight list.

Add one blank line after `- [General](#)`:
```markdown
- [General](#)

  - [Categories](#)
```
Output:
```html
<li><p><a href="#">General</a></p>
<ul><li>Categories</li></ul>
</li>
```
Loose list — `<p>` appears.

## Solutions (defense in depth)

### 1. Source level: keep lists tight
Do not leave blank lines between list items. Blank lines *before* the first item or after the last are fine; blank lines *between* items are what triggers loosening.

### 2. Selector level: tolerate the `<p>` wrapper
If content may be loose, make selectors match both shapes:

```css
li.has-children > a,
li.has-children > p > a { padding-left: 30px; }
```

Same for JS: instead of `li.querySelector(':scope > ul')`, iterate `li.children` and look for a `UL` — that works for both tight and loose lists, and also avoids `:scope` compatibility issues in older WebViews.

### 3. Layout level: neutralize `<p>` margins
Loose items get default `<p>` margins which blow up row spacing:

```css
.md li p { margin: 0; }
```

### 4. Verification level: check the rendered DOM, not the source
When styles "mysteriously" fail on list items, dump the rendered HTML once:

```bash
node -e "console.log(require('marked').parse(md, {gfm:true}))"
```
A 5-second check that shows the exact DOM structure beats debugging selectors blind.

## Summary

- Markdown blank lines between list items switch the list from *tight* to *loose*; loose items get their content wrapped in `<p>`.
- Any CSS/JS assuming `li > a` (direct child) silently stops working.
- Fix at three levels: keep the source tight, make selectors tolerant (`> a, > p > a`), and neutralize `<p>` margins.
- When a style stops working for no obvious reason, inspect the generated HTML first — the Markdown you write is not necessarily the DOM you get.
