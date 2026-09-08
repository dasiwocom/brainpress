# CSS Order Matters: A Mobile Media Query Silently Overridden by a Later Base Rule

## Symptom

Two pages (frontend and admin panel) share a supposedly identical top navbar — the CSS was copied from one to the other line by line. On desktop they look pixel-identical. But in DevTools mobile viewport (≤768px), the layouts differ: the frontend `#vp-nav-left` measures **306px wide**, the admin one **282px** — exactly 24px narrower.

Both `#vp-nav-right` blocks are 72px, so the flex container width is identical. The 24px gap equals `12px × 2` — the mobile padding. One page applies `padding:0 12px` on mobile, the other still uses the desktop `padding:0 24px`.

## Principle

CSS cascade rule: **when two rules have the same specificity, the one declared later wins.**

```css
/* admin.css — media query FIRST (file top) */
@media (max-width:768px) {
    #vp-nav { padding:0 12px; }   /* specificity: 0,1,0 */
}
/* ...later in the same file... */
#vp-nav { padding:0 24px; }        /* specificity: 0,1,0 — SAME, declared LATER → WINS */
```

Key trap: **a media query does NOT increase specificity.** `@media` only filters *when* a rule applies; inside the block the selector has exactly the same specificity as the base rule. So:

- Media rule declared **after** the base rule → mobile padding wins on small screens ✓
- Media rule declared **before** the base rule → base rule wins **even on small screens** ✗ (silently!)

The frontend file had the media query at line 912 and the base rule at line 606 (media later → correct). The admin file had the media query at line 20 and the base rule at line 73 (media earlier → broken). Copying the rules' *content* was not enough — their *order* differed.

## How to reproduce

```css
/* Broken: media query before base rule */
#vp-nav { padding:0 12px; }          /* mobile intended */
...
#vp-nav { padding:0 24px; }          /* desktop — overrides everything */

/* Correct: media query after base rule */
#vp-nav { padding:0 24px; }
...
@media (max-width:768px) {
    #vp-nav { padding:0 12px; }
}
```

## Solution

- **Keep media queries after the base rules** they modify — this is also the most readable order (base style first, responsive adjustments last).
- If you cannot move the rule, raise specificity or use `!important` — but prefer order over `!important`.
- When "copying" CSS between files, verify **order**, not just presence. A quick check: `grep -n` both rules and compare line numbers — the responsive one must come after the base one.

## Debugging technique that cracked it

Don't compare whole pages — compare **one computed dimension** at a time in DevTools:

1. Select the same element on both pages (`#vp-nav-left`).
2. Compare `getComputedStyle` / the Box Model values: width 306 vs 282 → narrowed to a 24px difference.
3. `vp-nav-right` was 72px on both → the flex container was identical → the difference had to be *inside* the navbar: its padding.
4. `24px = 12px × 2` → pointed straight at the mobile padding rule that wasn't applying.

## Summary

- Media queries do not add specificity — same-specificity rules are decided by **declaration order**, so a mobile rule written before its base rule is silently dead.
- When duplicating CSS between files, copy the **order** too; content alone is not enough.
- Use computed-value comparison (one dimension at a time) to bisect layout differences instead of eyeballing.
