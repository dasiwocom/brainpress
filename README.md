# Brainpress

An independently developed, Markdown-native personal publishing app. Content management follows familiar WordPress conventions; the frontend layout and interactions reference the supplied Zibll forum screenshots and source.

## Features

- One `posts` model, two-level category relationships, and one category per post.
- Frontend publishing, editing, preview, image uploads, search, and pagination.
- A responsive forum feed with content cards and a sidebar.
- Desktop header mega panels: second-level headings form columns; each heading accepts plain text or optional links. Mobile navigation retains the same hierarchy.
- Backend page-layout diagrams with seven widget regions, widget configuration and per-scope save/reset.
- A fixed desktop admin sidebar with expandable management groups; mobile administration uses a drawer.
- English and Simplified Chinese system languages.
- A mobile header with a left menu drawer, theme switch and search panel below the header. Independently managed bottom tabs support up to five entries.
- Light and dark themes, remembered locally. The first visit follows the system preference. Theme changes use a circular View Transition when supported, respecting reduced motion.
- CommonMark plus tables, task lists, strikethrough, automatic links, and footnotes.
- Mermaid diagrams in fenced `mermaid` blocks.
- LaTeX math through KaTeX: `$...$`, `$$...$$`, `\(...\)`, and `\[...\]`.

Markdown source is stored unchanged in `posts.markdown`. Preview and reading share the same parser. Math is parsed before inline Markdown formatting, so underscores and backslashes survive; code blocks and inline code remain literal. Raw HTML is escaped and unsafe links are blocked. KaTeX and Mermaid assets are served locally.

## Local development

Requirements: PHP 8.2+, Composer, PDO SQLite, mbstring, and fileinfo. The prepared Windows checkout includes an ignored portable PHP runtime.

```sh
composer install
composer serve
```

This starts http://127.0.0.1:8080 . On the prepared Windows checkout, double-click `start.cmd` to use http://127.0.0.1:8081 instead. The database is created automatically at `storage/brainpress.sqlite`. Images are stored under `public/uploads`.

The app includes administrator/member accounts. Only administrators can enter /admin; signed-in users can publish and upload. Public registration creates member accounts only. Publishing approval is not implemented.

## Frontend assets

Built renderer assets are included in `public/assets/vendor`. To rebuild them:

```sh
npm ci
npm run build
```

Node.js is only needed to rebuild assets, not to run the PHP application. The bundled dependency licenses are retained alongside the assets.

## MySQL and Nginx

1. Create a MySQL database. Copy `config.example.php` to `config.php` and set the DSN, username, and password. Enable PHP `pdo_mysql`, `mbstring`, and `fileinfo`.
2. Install Composer dependencies.
3. Set the document root to `public` so configuration, dependencies, and database files remain outside the web root.
4. Use `try_files $uri $uri/ /index.php?$query_string;` with your usual PHP-FPM configuration.
5. Allow PHP to write to `public/uploads`. SQLite additionally requires write access to `storage`.
6. Recommended upload settings: `upload_max_filesize=5M`, `post_max_size=8M`.

Tables are created on first access. SQLite is tested locally; the MySQL branch still needs verification on the target server. Keep this unauthenticated version within a personal test environment.

## Checks and data

```sh
composer test
```

Tests cover original-source persistence, database relationships, safe rendering, Markdown extensions, math delimiters, and literal code boundaries. Browser checks cover actual diagram and formula rendering, preview, responsive navigation, and theme switching.

Back up the database and `public/uploads` together. Runtime data and the portable PHP installation are excluded from Git.

The English extended Markdown example lives in `resources/example.md`. Previously created article content is preserved independently of the English interface.

## Post properties

Posts have Draft and Published states. Drafts are listed under Drafts and excluded from the feed, public post URLs, search and published counts. This personal prototype has no access controls on the editing area.

An optional YAML Frontmatter block is retained with the Markdown source:

```yaml
---
title: A note
author: Ada
date: 2026-10-14
draft: true
tags: [notes]
---
```

The editor title and status take precedence when supplied; title is a fallback for requests without a title. Date sets the article date, author is displayed, and unknown properties remain available in the raw source. Changing status synchronizes an existing draft property (YAML formatting/comments may change in that case). Without a metadata change the source is preserved exactly. Frontmatter is omitted from rendered content and excerpts. This does not implement file import/export or all Obsidian extensions.

See deploy/README.md for server setup.

Manage provides recoverable post trash, restore, category rename and category deletion with post reassignment. No permanent post deletion is exposed.

## Administration

Open /admin. The sidebar contains Posts, Media library, Categories, System, Tags and Menus. The old /?manage entry redirects to Posts.

- Posts: searchable, paginated status filters; Markdown editor, tags, draft/publish, recoverable trash and restore.
- Media library: discover existing uploads, upload images, edit title/alternative text, copy Markdown, trash and restore. Trash hides a library item without deleting its file or breaking existing post images. Existing Markdown alternative text is not rewritten when library metadata changes.
- Categories: create, rename, and delete with post reassignment.
- Tags: create, rename and remove; comma-separated editor tags are linked to posts and support frontend filtering. Explicit editor tags override Frontmatter tags; metadata is retained in source.
- System: site name and description, PHP/database and writable-directory status.
- Menus: a two-level header menu shared across desktop and mobile. Add/remove, move up/down, show/hide; local paths and HTTP(S) links are accepted. Hidden entries remain editable. This does not change the five bottom mobile tabs.

The reading sidebar contains only Profile and Contents. The profile scrolls normally; only Contents sticks, with its own scroll area. Mobile keeps collapsible contents.

Administration requires an active administrator account. All writes require a session and CSRF token; member accounts can only edit their own posts and select their own uploaded images.

Build a clean runtime package with node scripts/package.mjs /absolute/new/output-directory. The destination must not already exist. PHP dependencies and built assets are included; private config, SQLite data and uploaded images are excluded.

HTTP integration checks in tests/admin-http.mjs require a separate running instance with a disposable database (default URL http://127.0.0.1:8082). They create test content and an image; never point them at your personal or production instance. Normal composer test uses in-memory databases.

System settings include Logo URL. The supplied logo is bundled at /assets/site-logo.svg (source license comment preserved). Empty Logo URL uses the site name. Desktop defaults to Home, AI chat, Messages and Profile; mobile uses bottom navigation without a duplicate header menu.

## System language

Administration → System management → Site information → Language provides English and Simplified Chinese. The persisted setting applies site-wide to interface labels and common feedback; saving reloads the settings page. Markdown, post titles, category/tag names, site description and custom menu labels are not translated. Edit custom menus separately if needed. The default locale on a new installation is English. Translation catalogs are included in runtime packages. The feed has no quick-publishing form; use dedicated publishing links.

## Pages and contextual layout editing

Open a frontend page and choose Edit layout. Configure the current route context, move widgets between allowed regions, reorder/remove them and preview changes without saving. Save layout publishes the configuration; Restore default resets that context. Article layout applies to all article details, category-index layout to the category list, and archive layout to category/tag/search results. Custom pages have their own layouts. The editor displays this scope explicitly.

Administration → Pages manages title, URL slug and Draft/Published state. System → Homepage selects a published page; choose Home for the standard feed. Built-in Home and Categories pages remain published. A selected homepage cannot be unpublished until another homepage is chosen. Only administrators can configure page layouts or preview draft custom pages.

Widgets: Post list and Category list in main content, Profile card in main or sidebar, Contents in article sidebars. Each type has at most one instance in this initial version. Profile name, introduction, avatar and cover are configurable. Article body remains a fixed core region. List widgets inherit route filters and use one main pagination stream. Widgets do not execute user-supplied code. No freeform nested canvas or drag-and-drop is implemented.

AI, Messages and Profile placeholder routes are retired; their old menu links are hidden. Mobile navigation now contains Home, Publish and Categories. Frontend left navigation is deferred. Colors are unchanged. tests/pages-http.mjs uses the disposable local test instance on port 8082 and must not target a personal or production database.

## Hierarchy and diagram loading

Categories now support one parent level. Choose a top-level parent when creating or updating a category; self-links, cycles and third levels are rejected. Deleting a parent promotes its child categories to top level and retains the existing post reassignment requirement. Menus store stable IDs and parent IDs; the editor preserves relationships while reordering and promotes children when their parent row is removed. Frontend dropdown/tree presentation is deferred: existing flat display is retained.

Mermaid and KaTeX load only when needed. Mermaid source is masked by a loading placeholder until rendering. Up to eight SVG results are cached per browser tab/session, keyed by exact source, theme and renderer version, and sanitized when restored. Source or theme changes miss the old cache. First renders still require the Mermaid engine; caching does not make all initial diagrams instantaneous.

The footer spans the viewport; category hover movement and frontend category creation controls are removed. The visible Edit layout shortcut is hidden; existing edit_layout URLs and backend page links still work. tests/hierarchy-http.mjs requires a disposable test instance on port 8082.

## Page regions and header panels

Open **Pages → Site layouts** to configure the homepage, article, category-list and archive layouts; custom pages have their own Edit layout links. Regions are top full width, main content top, main content, main content bottom, sidebar, bottom full width and footer. Empty regions take no space. Existing layouts remain valid.

The available widgets remain Post list, Category list, Profile card and Contents. Profile cards may repeat across regions; each list and Contents remains unique. Contents is restricted to article sidebars, and lists are excluded from article pages and sidebars. No Markdown text widget is included.

In **Menus**, create a root item and assign second-level items to it. Each second-level item becomes a column heading. Expand **Submenu text** to add labels and optional URLs. Empty URLs produce plain text; local paths and HTTP(S) URLs produce links. Root items with children open a wide panel without a separate description column. Changes do not modify Mobile tabs.

Deployment packaging is currently deferred. Existing archives do not include the latest changes.

## Post cover images

The top editor image area sets an optional cover, stored separately in `posts.cover_url`. Paste a local image path or HTTP(S) image URL, or upload an image using the existing media pipeline. Covers can be changed or removed; toolbar image uploads still insert Markdown body images. External cover URLs are loaded by the browser and are not downloaded or inspected by the server.

Feed cards prefer the explicit cover and otherwise use the first supported Markdown body image. Article pages show an explicitly configured cover above the title. Omitting `cover_url` from an update preserves the existing value; sending an empty string removes it. Existing databases gain the new column without rewriting article Markdown.

## Page layout administration and footer settings

Open **Page management → Page layout** (/admin/pages/layout). Select Home, Categories, shared article/archive layouts or a custom page from the dropdown. Click a region in the schematic to add, configure, move, reorder or remove widgets. Changes apply after Save and apply; Restore default resets only the selected scope. Existing saved layouts remain compatible. Public header editing shortcuts and the frontend widget drawer are removed. Desktop shows the schematic beside region settings; narrow screens stack them.

**System management → Footer settings** independently controls site-name and description visibility, copyright text and up to twelve ordered text/link items. Footer widgets remain configurable in the schematic's Footer region.

## Link management and image selection

**Links** is an independent bookmark manager with only name, address, description, Logo URL and link categories. Add, edit, search, filter and delete links; link categories are separate from article categories, and deleting a link category preserves its links. Navigation menus are unaffected.

The editor image toolbar opens a chooser with **My images** and **External images** tabs. Search and page through active media, select multiple images, upload, or enter one external URL per line. Desktop uses a centered modal and mobile uses a bottom sheet. Confirming inserts Markdown at the saved caret position; choosing a cover uses the same picker in single-image mode and keeps cover metadata separate. Trashed media is excluded.

The desktop administration media library displays four cards per row, with narrower-screen fallbacks.

Administration groups **Post management** into Article list / Article categories and **Link management** into Link list / Link categories. The current group opens automatically; desktop and mobile share the same navigation structure. Sidebar-width collapsing and the public-header Publish shortcut have been removed.

Media management contains separate Library (/admin/media) and Trash (/admin/media/trash) pages. System management contains Site information (/admin/system) and Footer settings (/admin/system/footer). Each active submenu opens its group automatically. Duplicate top-level administration page headings are removed; panel titles and frontend article titles remain.

## Accounts and first administrator

1. Open /setup on a new or upgraded installation. No default password or public first-admin signup exists.
2. In the server file manager, read storage/setup-token.txt and enter its contents on the setup form. Choose your own administrator username and password (10–72 bytes). The file is generated on opening /setup and is never printed on the page.
3. The first administrator receives ownership of legacy posts and indexed media. Existing article Markdown is preserved. Setup closes after the account is created.
4. Sign in at /login. Public /register creates ordinary member accounts; role fields supplied by visitors are ignored. /account contains personal posts, sign-out and password-change actions.
5. Administrators manage users at /admin/users, including role, status and password reset. The last active administrator cannot be demoted or disabled. Password, role and status changes invalidate previous sessions.

Article management contains Posts, Categories and Tags. System management contains Site information, Footer settings, Header menu and Mobile tabs. All bottom tabs use standard icon-and-label styling; each supports signed-in-only visibility, and publishing destinations always require sign-in. Anonymous visitors can read published content but cannot publish, upload, preview Markdown, list media or invoke administrative actions. Images embedded in published posts remain public URLs.

Sessions use HttpOnly/SameSite cookies (Secure over HTTPS), rotate on sign-in, expire after 12 idle hours at most, and are checked against account status/version. Authentication attempts are rate-limited. Use HTTPS on the server.

Run node tests/auth-http.mjs (or npm run test:auth) for isolated authentication integration checks. PHP_BIN can select the PHP executable. The test starts a temporary localhost server/database and cleans its generated uploads; it never targets an existing site.

Account and authentication routes share the standard public header, menu/search panels and bottom navigation. Desktop actions are Account, Theme and Administration; mobile Account access is a configurable bottom tab. Header menus contain exactly the configured menu entries, with no automatic account or administration links. Existing standard publishing tabs migrate from a bare plus to the writing icon once.
