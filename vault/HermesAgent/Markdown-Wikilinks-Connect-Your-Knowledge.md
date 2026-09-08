# Markdown Wikilinks Connect Your Knowledge

## Why links matter

A knowledge base without links is a pile of isolated notes. Add `[[wikilink]]` between related notes and the vault becomes a **graph**: connections you can see, traverse, and ask questions about.

```markdown
See [[Getting-Started]] for the quick tour, or [[What-Is-MD2HTML]] for the big picture.
```

## Link forms

| Form | Matches |
| --- | --- |
| `[[Note-Name]]` | Any note whose file name is `Note-Name.md` |
| `[[dir/Note-Name]]` | A note at a specific path |
| `[[Note-Name|display text]]` | Same, with custom display text |

File names are matched by basename, so links survive small moves.

## What it unlocks

- **Graph view** draws one node per note and one line per link — the vault becomes visible structure.
- Backlinks ("who links to me") let readers discover related material they would never search for.
- The AI assistant treats linked context as stronger signal when answering.

## Related

- [[Graph-View-Force-Directed-Layout-Notes]]
- [[What-Is-MD2HTML]]
- [[getting-started]]
- [[learning-how-to-use-obsidian]]
