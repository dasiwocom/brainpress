# The DOM Traversal Starting Point: When Your Loop Treats a `<ul>` as an `<li>`

## Symptom

A collapsible sidebar menu is rendered from Markdown nested lists. To support "force-expand these directories", a function walks the DOM and stamps each directory `<li>` with its relative path (`li.dataset.path`), so the collapse logic can match directories against a whitelist.

The whitelist is loaded correctly (`FRONT_EXPANDED_DIRS = ["draft"]`), the collapse logic checks `li.dataset.path` against it — yet **every directory collapses**, and a debug log shows `path=` (empty) for all of them. The whitelist never matches.

## Principle

The tree-walking function was called with the **container `<div>`** instead of the inner `<ul>`:

```js
function buildDirPaths(root) {
    function walkUl(ul, prefix) {
        var items = ul.children;          // expected: <li> elements
        for (var i = 0; i < items.length; i++) {
            var li = items[i];            // but if ul is really the <div>, items are <ul>s!
            li.dataset.path = ...;        // stamped onto the WRONG element
        }
    }
    walkUl(root, '');                     // root = the drawer <div>, not a <ul>
}
```

The drawer container's structure is `div > ul > li > ul > li ...`. Walking from the **div** means `ul.children` yields the **inner `<ul>`** — the loop treats a `<ul>` as an `<li>`, stamps `dataset.path` on the `<ul>` (and on nested `<ul>`s), and the **real `<li>` elements never get a path**. Downstream code reads `li.dataset.path` on the actual `<li>`s → always empty → whitelist never matches → everything collapses.

Nothing throws. The wrong elements silently receive the attributes; the right ones stay empty. This is why the bug survived code review: the walker "worked" (it set paths on *something*).

## How to reproduce

```html
<div id="drawer">          <!-- root passed here -->
  <ul>
    <li>draft<ul><li><a>#</a></li></ul></li>
  </ul>
</div>
```

```js
function walk(ul, prefix) {
  for (const el of ul.children) {          // div.children = [ul]  → el is a <ul>, not <li>
    el.dataset.path = 'WRONG';             // stamped on <ul>
    const sub = [...el.children].find(c => c.tagName === 'UL');
    if (sub) walk(sub, prefix);            // recursion also walks wrong
  }
}
walk(document.getElementById('drawer'), '');   // <li> never gets data-path
```

## Solution

Start the walk at the **first real `<ul>`** inside the container:

```js
function buildDirPaths(root) {
    function walkUl(ul, prefix) { /* ... walks <li> children, recurses into <ul> children ... */ }
    var rootUl = root.querySelector('ul');   // descend one level first
    if (rootUl) walkUl(rootUl, '');
}
```

General rule: **the container element and the list element are different nodes** — `div.children` are `<ul>`s, `ul.children` are `<li>`s. Pin down the exact element type your loop expects before iterating.

## Debugging technique

A one-line log inside the loop told the whole story immediately:

```
[drawer] path= dirs=["draft"] forceExpand=false
```

`dirs` was correct and the comparison logic was correct, so the only remaining variable was `li.dataset.path` — and it was empty for every node. Empty path + correct whitelist = the attribute was never set on the elements being read. Then inspect *which* elements carry `data-path` in DevTools (Elements panel): seeing `data-path` on `<ul>`s (not `<li>`s) confirmed the off-by-one-level traversal.

## Summary

- Verify the **starting node type** of a DOM walk: div → ul → li; starting one level too high stamps attributes on the wrong elements, silently.
- When a whitelist match always fails, log the compared values (`path`, whitelist) — the empty side pinpoints where the attribute was (not) written.
- In DevTools, check where the attribute actually landed before assuming the loop body is wrong.
