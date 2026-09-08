# Backup versus Snapshot and How Git Stores History

## What This Article Is About

When you edit a document, the old version is gone. Obsidian, a plain text folder, or a hand-made website all behave this way: save once, and the previous state disappears. This article explains the three common ways to protect your files (full backup, snapshot, version control), then dives into how Git actually stores history and why it takes so little space.

## The Three Ways to Protect Files

### 1. Full Backup (copy everything, somewhere else)

```
Original files ──copy──→ Backup files (another disk / another machine)
```

- A complete copy of the data is stored at a different location.
- Space used equals the data size (every copy costs full space).
- Restoring means copying the files back (can take minutes or hours).
- Typical example: pack the site folder into a tar file every day and upload it to MinIO.

What it protects against: disk failure, server loss, accidental deletion of the whole folder.

### 2. Snapshot (record a moment in time)

A snapshot records "what the data looked like at this moment" and can restore to that moment instantly.

Two common implementations:

| Implementation | What it stores | Space | Restore speed |
|---|---|---|---|
| LVM / ZFS snapshot | Metadata + old data blocks kept alive (copy-on-write) | Tiny | Seconds |
| File-history tools (Obsidian recovery plugin) | Copies of the old file versions | Large (full copies) | Seconds |

Key idea: snapshots do not depend on the working file. The snapshot itself contains a complete copy of the data as of that moment, so even if the original files are deleted, the snapshot can restore them byte for byte.

### 3. Version Control (Git)

Git is the most flexible option: every commit is a full snapshot of all files at that moment, and you can jump back to any commit.

| | Full Backup | Snapshot (LVM) | Git |
|---|---|---|---|
| Location | Another place | Same disk | `.git/` inside the project |
| Space | Full copy every time | Tiny (only kept old blocks) | Small (compressed + deduplicated) |
| Restore points | One per backup run | Only the snapshot moment | Any commit, unlimited |
| Restores source code? | Yes | Yes | Yes, byte for byte |
| Protects against disk death | Yes | No (same disk) | No (same disk) — push to remote for that |

## How Git Stores History (the interesting part)

### Git stores full snapshots, not diffs

A common misunderstanding is that Git saves "changes" (diffs) between versions. It does not. Every commit is a **complete snapshot of all files at that moment**.

### But it compresses and deduplicates

Storing a full copy per commit would be expensive. Git's trick is content addressing: each block of content is compressed, hashed, and stored once. Identical blocks are reused by reference.

```
File v1: 100 KB (compressed to 30 KB)  → stored once (30 KB)
File v2: one line changed              → 99.9 KB identical
        → identical blocks reused (0 KB new)
        → only the new line is stored (0.1 KB)
Total stored: 30.1 KB for two versions, instead of 60 KB
```

### What the `.git/` folder actually is

After `git init`, a hidden `.git/` directory appears inside the project:

```
project/
├── files...           ← working area (the real files you edit)
└── .git/              ← the repository
    ├── HEAD           ← which branch you are on
    ├── config         ← repository settings
    ├── index          ← staging area state
    ├── objects/       ← compressed snapshots (the data) ★
    └── refs/          ← pointers to commits (the version list)
```

It is not a single file — it is a directory containing many files. The important one is `objects/`, which holds the compressed content blocks, and `refs/`, which remembers which blocks belong to which version.

### The stored data is the source code itself

The objects are not a description of the code and not a list of changes. They are the **compressed source bytes**. Think of it as putting the source into a zip container: the container is not human-readable, but inside it is the complete, exact source. Restoring means unzipping — the source comes back byte for byte, not "approximately rebuilt".

### This is like md vs html

A `.md` file is plain text you can read. An `.html` file is another form of the same content. Git is the same idea: the plain source is one form, the compressed objects inside `.git/` are another form of the identical content. Different storage format, same content.

## The Three-Layer Protection Model

```
Layer 1: working files        → lost by mistakes (delete, bad edit)
Layer 2: Git / snapshot       → protects against mistakes (restore any commit)
Layer 3: remote backup        → protects against disasters (disk death, server loss)
         (MinIO, GitHub, another machine)
```

- Snapshot / Git handles daily accidents: instant rollback to any moment.
- Remote backup handles total destruction: even if the whole machine dies, data survives elsewhere.
- They complement each other — one is not a replacement for the other.

## Minimal Git Commands You Need

```bash
git init                       # create the repository (.git/ appears)
git add .                      # stage changes (choose files to commit)
git commit -m "message"        # take a snapshot of the staged files
git log                        # list all snapshots
git restore <file>             # bring a file back to the last commit
```

Workflow: edit files → `git add` → `git commit`. That is the whole loop. `git log` to review, `git restore` to roll back.

## Summary

- Full backup copies everything to another place; protects against disasters.
- Snapshot records a moment in time; restores instantly; protects against mistakes.
- Git is the most flexible: unlimited restore points, compressed and deduplicated storage, and the stored data is the exact source code itself.
- Git lives inside the project as a `.git/` directory; it does not need the working files to survive — the snapshots contain complete copies.
- For real disaster safety, combine Git (mistakes) with an off-site backup (disk death).
