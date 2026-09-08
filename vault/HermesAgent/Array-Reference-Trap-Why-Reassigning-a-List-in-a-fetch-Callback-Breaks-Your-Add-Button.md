# The Array Reference Trap: Why Reassigning a List in a fetch Callback Breaks Your Add Button

## Symptom

An admin panel has an "Add to list" feature: type a path into an input, click **Add**, the item is pushed into an array and rendered below. The server receives the POST (HTTP 200, `{ok:true}`), the save feedback shows "Saved" — but:

- The rendered list stays empty.
- Inspecting the array in the console shows `[]` (length 0).
- After a page refresh the item is gone from the server config too — because the POST actually saved an **empty array**.

The Add handler ran (save happened), the array is empty (rendering read nothing) — the two facts are contradictory until you realize they were reading **two different arrays**.

## Principle

```js
var pinnedArticles = [];                 // array A

function bindAddList(list) {             // closure captures a REFERENCE to A
    btn.onclick = function () {
        list.push(input.value);           // pushes into A
        render();                         // reads the GLOBAL pinnedArticles
        save();                           // serializes the GLOBAL pinnedArticles
    };
}
bindAddList(pinnedArticles);              // bound with A

fetch('/api/admin/config').then(function (d) {
    pinnedArticles = mergeList(d.pinned_articles, pinnedArticles);  // REASSIGN: global now points to B
    render();
});
```

JavaScript closures capture **references to arrays, not copies**. The sequence:

1. Page loads: `pinnedArticles = []` → array **A**. `bindAddList` closes over **A**.
2. The async `fetch` callback resolves: `pinnedArticles = mergeList(...)` — this **reassigns the global variable** to a brand-new array **B** (even if B has identical contents).
3. User clicks Add: `list.push(v)` writes into the **old array A** — but `render()` and `save()` read the **global `pinnedArticles`**, which is now **B**. Nothing renders; the POST payload is `B` (empty).
4. Result: save succeeds (200), list is empty, server stores nothing.

This is silent — no error is thrown, because pushing into A is perfectly legal. Only the observable behavior is wrong.

## How to reproduce

```js
let items = [];
const bound = items;                       // closure reference
[].forEach(x => items.push(x));            // simulate fetch restore WITHOUT reassign → works
items = items.slice();                     // simulate restore WITH reassign → bound still points to the OLD array
bound.push('new');
console.log(items);                        // [] — the add went to the old array
```

## Solution

**Never reassign the array that closures captured — mutate it in place** during the async restore:

```js
fetch('/api/admin/config').then(function (d) {
    // in-place merge: keep the same array object, only add items
    (d.pinned_articles || []).forEach(function (p) {
        if (pinnedArticles.indexOf(p) === -1) pinnedArticles.push(p);
    });
    render();
});
```

If you must reassign, make the closure read through a **getter function** (`getList()`) instead of capturing the variable.

## Debugging technique

The contradiction itself was the clue: **the Add handler clearly ran** (a POST went out, feedback showed) **yet the array was empty**. A handler that runs and saves an empty array means the handler mutated one array while the rest of the code read another. Whenever "state that should have changed didn't change" co-occurs with "the request still fired", suspect a lost reference.

## Summary

- Closures hold array **references**; an async callback that does `arr = newArr` orphans every earlier capture.
- Restore server state by **mutating in place** (`push` into the existing array), not by reassigning.
- In-place merge also fixes a second bug for free: a slow `fetch` can no longer clobber items the user added before it resolved.
