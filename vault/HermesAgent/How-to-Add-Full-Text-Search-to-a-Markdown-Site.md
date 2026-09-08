# How to Add Full-Text Search to a Markdown Site

## What You Want

Your Markdown-based documentation site only searches file names. You type a keyword and it matches titles only — but the word you care about might be deep inside an article. You want **full-text search**: type a word, get every article whose *content* contains it, ranked by relevance.

## The Four Ways to Search

Searching is fundamentally about one question: **where do you look when the user types a keyword?**

### 1. Scan on Demand (Most Naive)

```js
// Every keystroke: fetch EVERY article, read it, check for the keyword
files.forEach(f => {
  api('/api/file?path=' + f.path).then(d => {
    if (d.content.includes(keyword)) results.push(f);
  });
});
```

- Searches from scratch every time.
- Works with a handful of notes; dies at scale (100 notes = 100 HTTP requests per keystroke).

### 2. Preload Everything into Memory

```js
// First load: download ALL article content, cache it
var cache = {};
async function buildCache() {
  for (const f of files) cache[f.path] = (await api('/api/file?path=' + f.path)).content;
}
// Then: search is instant, pure in-memory
function search(kw) {
  return Object.keys(cache).filter(p => cache[p].includes(kw));
}
```

- Fast after the first load, but the first load downloads everything.
- Memory grows with the library — fine for 100 notes, heavy for 10,000.

### 3. Build Your Own Index

Precompute a mapping of *word → articles*:

```
"glass"    → [article-3, article-7]
"obsidian" → [article-1, article-3]
```

Searching becomes a dictionary lookup — instant regardless of library size. But you must write tokenization (splitting text into words), stemming (running → run), and keep the index in sync when articles change.

### 4. Use an Existing Search Library

Libraries like [lunr.js](https://lunrjs.com/) or [FlexSearch](https://github.com/nextapps-de/flexsearch) do all of the above for you: tokenization, stemming, indexing, relevance ranking, fuzzy matching.

```js
// Feed it documents once
var idx = lunr(function () {
  this.ref('id');
  this.field('name', { boost: 10 });   // titles weigh more
  this.field('content');
  docs.forEach(d => this.add(d));
});
// Search is one call, results come back ranked
var hits = idx.search('installing');    // finds "install", "installed" too
```

## Which One to Choose

| Approach | Speed | Effort | Scales to |
| --- | --- | --- | --- |
| Scan on demand | Slow | Trivial | ~50 notes |
| Preload cache | Fast | Easy | ~500 notes |
| Own index | Fastest | Hard | Any size |
| **Library (lunr)** | **Fast** | **Easy** | **Any size** |

**For an English-language site that will grow, use a library.** English is lunr's native language — spaces separate words naturally, and stemming works out of the box. (Chinese is harder for lunr because there are no spaces to split on — that is the main reason to hesitate.)

## The Implementation Pattern

The clean pattern used in practice:

1. **Include the library locally** — download `lunr.min.js` (29 KB) into your `assets/` folder. No CDN dependency.
2. **Build the index lazily** — the first time the user searches, fetch all article contents, feed them to lunr, cache the index in a global.
3. **Search through the index** — `lunr.search(query)` returns references ranked by relevance; map them back to documents.
4. **Show a snippet** — find the keyword in the content, slice ~100 characters around it, display it under the result title so the user can see *why* the article matched.
5. **Fall back gracefully** — if the query contains special characters that break lunr's query syntax, catch the error and fall back to plain title matching.

## Pitfalls

- **Auto-linking breaks Obsidian syntax**: if your renderer uses a Markdown parser, `![[www.example.png]]` may be mis-parsed as a link because of the `www.` prefix. Protect Obsidian syntax with placeholders *before* rendering, restore *after*.
- **First-search latency**: building the index requires fetching every article once. Show a "Building index…" message; it only happens once per page load.
- **Memory**: the index + cached content live in browser memory. Fine for thousands of notes; consider a server-side index (Meilisearch, Typesense) for truly large libraries.
- **Relevance**: boost the title field (e.g. `boost: 10`) so a title match outranks a passing mention in content.

## Summary

Full-text search = decide *where to look* ahead of time. For a small English site that will grow, the best effort-to-result ratio is **a client-side search library like lunr.js**: lazy-build the index on first search, rank results by relevance, show a context snippet, and fall back to title matching on query errors.
