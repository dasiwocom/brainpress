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

## 2026-10-14 — Publishing and reading

- Draft/published states and YAML Frontmatter metadata.
- Article sidebar table of contents, responsive collapsible contents and code copy.
- Subtle input focus and consistent select controls.
- Clean production package with server configuration examples.
- Lightweight management: recoverable trash, restore and category reassignment.

## 2026-10-14 — Administration and custom navigation

- Added /admin with a sidebar and six working areas: posts, media, categories, system, tags and menus.
- Added persisted site settings, post tags and header menu editing shared by desktop/mobile.
- Media library preserves existing uploads and provides reversible trash without breaking article images.
- Fixed nested sidebar sticky positioning; kept Profile and Contents only.
- Fixed draft links/pagination and consistent author display; improved contents anchors and active-section tracking.
- Added clean deployment packaging and administration regression coverage.

## 2026-10-14 — Navigation and branding refinement

- Anchor the admin sidebar to the left window edge and use the remaining width for content.
- Replace pink accents with blue and simplify mobile bottom navigation.
- Install supplied SVG branding and expose Logo URL in System settings.
- Default desktop menu matches Home, AI chat, Messages and Profile; hide header menus on mobile.
- Keep only All posts and Categories in frontend navigation; remove notice bars.
