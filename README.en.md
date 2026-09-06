<div align="center">

[**English**](./README.en.md) · [**简体中文**](./README.md)

# 🧠 BrainPress

**If Obsidian is your second brain, BrainPress is your brain printer.**

*Write with Obsidian, print with BrainPress.*

---
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-personal-5672cd?style=flat-square)
![Self-hosted](https://img.shields.io/badge/self--hosted-✔-3e63dd?style=flat-square)
![No build](https://img.shields.io/badge/no--build-✔-22c55e?style=flat-square)
![No DB](https://img.shields.io/badge/no--database-✔-22c55e?style=flat-square)
![Docker ready](https://img.shields.io/badge/docker-ready-2496ed?style=flat-square&logo=docker&logoColor=white)
![WebDAV](https://img.shields.io/badge/WebDAV-Obsidian%20Sync-7c3aed?style=flat-square)
![AI RAG](https://img.shields.io/badge/AI-RAG%20QnA-f59e0b?style=flat-square)

[**Docs**](https://docs.dasiwo.com) · [**GitHub**](https://github.com/dasiwocom/brainpress) · [**Contact**](mailto:contact@dasiwo.com)

</div>

---

## ✨ What is this?

**BrainPress** is a **self-hosted Markdown knowledge base**: point it at a folder of notes and it turns into a browsable, searchable, AI-answering website — **no build step, no database, no Node.js**.

It also ships a **WebDAV sync endpoint**, so Obsidian's *Remotely Save* plugin can sync in one click — your notes are the site, and the site is your notes.

> Born for **AI memory visualization** — an AI writes notes about what it learns, and you watch it "think" in the browser; these days it works just as well for humans.

---

## 🎯 Features

- 📄 **Live Markdown rendering** — edit a note, refresh, done; no rebuild
- ✍️ **Full Obsidian syntax** — Callout, KaTeX math, highlights, nested tags, block IDs, footnotes, `[[wikilinks]]` and `![[embeds]]`; line numbers in code blocks
- 🌳 **Auto directory tree** — your folder structure drives the sidebar; pin, force-expand, hide paths
- 📖 **Built-in PDF reader** — pdf.js: outline, paging, zoom, fullscreen; `![[book.pdf]]` embeds straight into articles
- 🎨 **Excalidraw + Canvas** — fullscreen `.excalidraw.md` drawings and `.canvas` whiteboards with pan/zoom; cards connect in real time
- 🕸️ **Knowledge graph** — d3-force physics; node size scales with connection count, larger touch targets on mobile
- 🤖 **AI Q&A** — RAG answers grounded in your knowledge base (OpenAI-compatible endpoints / DeepSeek / Ollama)
- ☁️ **Multiple backends** — local / WebDAV / S3 (MinIO) / Tencent ima / custom mounts
- 🔗 **Obsidian one-click sync** — built-in WebDAV endpoint
- 🔒 **Admin panel** — password-protected, graphical configuration
- 🌗 **Dark/light mode** + font presets, **only local assets** (no CDN)

---

## 🚀 Deploy

### Option 1: Docker one-liner

```bash
docker run -d --name brainpress \
  --restart unless-stopped \
  -p 8080:80 \
  -v "$PWD/brainpress-data":/data \
  -v "$PWD/brainpress-vault":/var/www/html/vault \
  crpi-k60hf4g69i7wfk22.cn-hongkong.personal.cr.aliyuncs.com/dasiwocom/brainpress:latest
```

1. Open `http://server-ip:8080`
2. Visit `http://server-ip:8080/admin` and **set an admin password** on first run
3. Config persists in `./brainpress-data/config.json`, notes in `./brainpress-vault/` (survive container removal)

> The first boot auto-seeds the example vault so the site is never empty; skip the `vault` volume if you'd rather use the built-in seed.

### Option 2: Source code + rewrite rules

**Requirements**: PHP 8.0+ (with `curl` and `mbstring`) + Nginx or Apache.

1. Upload the repo source to your site root (e.g. upload/extract via your control panel — files are owned by the web user automatically)
2. Add the **rewrite rules** below to the site config (Apache: just use the bundled `apache-site.conf`)
3. Visit `/admin` and **set an admin password**
4. Put your notes in `vault/` — they show up immediately

**Nginx rewrite rules**:

```nginx
# Admin
location ~ ^/admin(/.*)?$       { rewrite .* /admin.php last; }
location ~ ^/api/admin(/.*)?$   { rewrite .* /admin.php last; }
# API / graph / WebDAV → main entry
location ~ ^/api(/.*)?$         { rewrite .* /index.php last; }
location ~ ^/graph(/.*)?$       { rewrite .* /index.php last; }
location ~ ^/dav(/.*)?$         { rewrite .* /index.php last; }
# Markdown / PDF / Canvas / HTML rendering
location ~* \.(md|pdf|canvas|html)$ { rewrite .* /index.php last; }
# Block sensitive files
location ~* (config\.json|\.user\.ini|\.env|\.bak|\.tmp|\.log)$ { return 404; }
```

> Local debugging needs no Nginx: run `./start.sh` in the repo root (PHP built-in server + routing shim).
> Permissions tip: if the extracted files end up owned by a non-PHP user (e.g. you untar as root), the first password save may 500. Set the site-folder owner to the web user (`www`) in your control panel, or check the hint shown at the top of the `/admin` page.

---

## 📚 Documentation

All docs ship as Markdown inside the repo at `vault/BrainPress/` — the same source that powers your site:

| Doc | About | Read |
|-----|-------|------|
| **Introduction** | Overview / architecture / quick config | [EN](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Introduction.md) · [ZHS](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Introduction.zh.md) |
| **Getting Started** | Tutorial | [EN](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/GettingStarted.md) · [ZHS](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/GettingStarted.zh.md) |
| **Deployment** | Deploy & security hardening | [EN](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Deployment.md) · [ZHS](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Deployment.zh.md) |
| **Configuration** | Every config field | [EN](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Configuration.md) · [ZHS](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Configuration.zh.md) |
| **API Reference** | Public API details | [EN](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/API-Reference.md) · [ZHS](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/API-Reference.zh.md) |

---

## 📄 License

**Author**: Ryan · **Type**: personal project — free to use and modify.

---

<div align="center"><b>Write with Obsidian, print with BrainPress.</b><br><i>用 Obsidian 写，用 BrainPress 印。</i></div>