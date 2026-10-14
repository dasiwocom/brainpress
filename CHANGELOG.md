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

## System language

- Remove feed quick publishing while keeping dedicated editor entry points.
- Add English/Simplified Chinese system language, persisted in System settings; translate interface templates, common feedback and client controls without translating user content.
- Include language catalogs in deployment packages. Keep existing colors.

## Pages and contextual widgets

- Add page title/slug/status management and a published-page homepage setting.
- Edit frontend layouts in current-page context with scoped live preview, save and reset.
- Extract post list, categories, profile and contents into widget renderers; keep article core content fixed.
- Retire AI/messages/profile placeholders, defer left navigation and simplify mobile navigation.
- Preserve current colors, article data and shared system language.

## Stable feed refresh

- Remove desktop post-card entrance animation.
- Emit intrinsic dimensions for local feed images to reserve space before loading.

## Footer, rendering and two-level organization

- Full-width footer with aligned inner content; remove category-card movement, frontend category creation and visible layout-edit shortcut.
- Lazy-load Mermaid/KaTeX; mask source while loading; reuse sanitized, source/theme-versioned session SVG cache.
- Add parent category and stable parent menu IDs with two-level validation, cycle protection and parent removal handling. Defer frontend nested presentation.

### Mobile navigation and panels

- Add independent mobile bottom navigation management at /admin/tabs with labels, URLs, icons, ordering, visibility and an optional prominent action.
- Align the full-width footer with the fixed bottom bar and safe area on short and long pages.
- Add a left mobile menu drawer below the header; move the theme control beside search.
- Keep existing search content and styling, open below the header, and toggle the header search button into a close control.
- Keep the background inert while panels are open; support Escape and backdrop dismissal.

## 2026-10-14 — Layout regions and header panels

- Save the previous working version as Git baseline 2935ffa.
- Add all seven page layout regions, preserve existing configurations, and preview every region before saving.
- Keep the four existing widget types; allow repeated profile cards while retaining one paginated post list.
- Add header mega panels with second-level column headings and optional linked text. No description column or Markdown text widget.
- Add management links for home, article, category and archive layouts.
- Defer deployment package generation.

Validation: isolated database requests verify region preview/save/reset, menu hierarchy and optional links; PHP/JavaScript checks and regression tests pass. Browser visual acceptance remains pending.
