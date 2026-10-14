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
