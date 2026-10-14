# Brainpress

An independently developed, Markdown-native personal publishing app. Content management follows familiar WordPress conventions; the frontend layout and interactions reference the supplied Zibll forum screenshots and source.

## Features

- One `posts` model, flat categories, and one category per post.
- Frontend publishing, editing, preview, image uploads, search, and pagination.
- A responsive forum feed with desktop navigation, content cards, and a sidebar.
- A mobile header and five bottom navigation entries: Home, AI chat, Publish, Messages, and Profile. AI chat, Messages, and Profile are placeholders.
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

The app intentionally has no account or publishing approval workflow and is intended for personal local testing.

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
- Menus: a single flat header menu shared across desktop and mobile. Add/remove, move up/down, show/hide; local paths and HTTP(S) links are accepted. Hidden entries remain editable. This does not change the five bottom mobile tabs.

The reading sidebar contains only Profile and Contents. The profile scrolls normally; only Contents sticks, with its own scroll area. Mobile keeps collapsible contents.

Administration remains in personal testing mode without authentication. Do not expose it publicly without server-side protection.

Build a clean runtime package with node scripts/package.mjs /absolute/new/output-directory. The destination must not already exist. PHP dependencies and built assets are included; private config, SQLite data and uploaded images are excluded.

HTTP integration checks in tests/admin-http.mjs require a separate running instance with a disposable database (default URL http://127.0.0.1:8082). They create test content and an image; never point them at your personal or production instance. Normal composer test uses in-memory databases.

System settings include Logo URL. The supplied logo is bundled at /assets/site-logo.svg (source license comment preserved). Empty Logo URL uses the site name. Desktop defaults to Home, AI chat, Messages and Profile; mobile uses bottom navigation without a duplicate header menu.
