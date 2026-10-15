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

## 2026-10-14 — Independent post covers

- Replace the top body-image picker with optional cover metadata: local paths, external HTTP(S) URLs, upload, preview, change and remove.
- Preserve body image insertion in the editor toolbar; cover changes never modify Markdown.
- Prefer covers on feed cards, fall back to the first body image, and show explicit covers on article pages.
- Add the cover URL column compatibly and retain existing covers when older save requests omit the field.
- Include the preceding quiet input-focus and consistent publication-field styling.

Validation: isolated post create/edit/draft/removal requests, cover URL safety, raw Markdown preservation, fallback rendering, PHP/JavaScript checks and regression tests.

## 2026-10-15 — Contextual customizer and footer settings

- Add a public-header layout editing entry, desktop fixed widget panel and mobile menu-drawer widget controls.
- Group controls by region and support widget configuration, region moves, ordering, live preview, save/apply, reset confirmation and unsaved-change protection.
- Preserve category-list parameters on editor entry, preview and exit.
- Add independent footer settings for site information visibility, copyright and ordered optional links.
- Verify footer persistence and safe rendering, contextual editor output, preview isolation, layout persistence and existing regressions. Windows browser visual automation was stopped because URL-policy enforcement is unsupported for the current Firefox browser; visual and interaction acceptance remains pending.

## 2026-10-15 — Media picker and links

- Display four media-library cards per desktop row.
- Add a simplified independent link manager: categories, name, address, description and logo; no advanced WordPress fields.
- Add a Zibll-inspired My images / External images chooser, centered on desktop and presented as a mobile bottom sheet.
- Support paged active-media search, upload, multi-selection and batch external URLs; cover selection remains single-image metadata.
- Verify link CRUD/category retention/search/URL checks, media pagination/trash exclusion, editor markup, cover persistence and existing customizer/footer regressions.

### Image chooser correction

- Prevent internal tab clicks from dismissing the dialog after its size changes; require a pointer gesture that starts and ends on the backdrop.
- Override the generic dialog button width and margin so search and upload retain the reference arrangement.
- Use reference-style numbered selection corner badges, renumber after deselection, and insert in selection order; keep single-cover checks.
- Add `node tests/image-picker.mjs` regression coverage for selection order and tab-resize dismissal. These are event/DOM-model tests, not browser visual acceptance.

### Editor controls and navigation canvas

- Hide the standalone cover URL field; retain cover metadata through the image chooser and keep removal available.
- Replace mixed toolbar glyphs with the shared SVG icon system.
- Normalize checkbox and radio rendering, including keyboard focus and forced-colors fallback.
- Set the root canvas background and color scheme synchronously with theme initialization and manual changes; add early fallback styles to both page shells.
- Verify cover persistence, picker behavior and theme initialization; actual navigation-flash acceptance remains pending.

### Requested rollback and navigation cleanup

- Revert the root-canvas/theme initialization experiment while preserving the existing theme switch.
- Remove the public header Publish shortcut and desktop admin sidebar collapsing.
- Keep the image picker, editor controls, contextual customizer, footer settings and simplified link manager.
