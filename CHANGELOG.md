# Changelog

## 2026-10-14 — Header and search refinement

- Saved the initial working version in baseline commit `a5e43b0`.
- Removed the Zibll-like green logo; retained the Brainpress wordmark.
- Moved the mobile theme switch to the left and removed the menu toggle.
- Removed movement from button hover interactions; the header Publish button stays in place.
- Replaced mobile inline search with a top drawer: automatic focus, close control, backdrop dismissal, Escape handling, focus restoration, and background scroll lock.
- Added desktop search suggestions, recent searches stored in this browser, category shortcuts, and a history clear control.
- Improved keyboard focus states, mobile touch targets, and singular result counts.

Verification: PHP/JavaScript syntax checks; mobile 402px layout without horizontal overflow; search results and history; Escape/focus restoration; desktop search suggestions. Markdown rendering is unchanged.
