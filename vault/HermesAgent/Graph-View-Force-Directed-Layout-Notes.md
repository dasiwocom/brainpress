# Graph View Force-Directed Layout Notes

## The simulation

A force-directed graph treats every node as a particle and iterates physical forces each frame:

- **Repulsion** between every pair of nodes (keeps them apart)
- **Spring** attraction along links (pulls connected nodes together)
- **Center gravity** (keeps the whole cluster from drifting away)
- **Damping** (energy loss — without it nodes oscillate forever)

Parameters matter: too much repulsion or too little damping and the graph **bounces forever** instead of settling.

## Getting it to settle

- Repulsion coefficient modest (e.g. `4000` for tens of nodes)
- Center gravity strong enough to bind (`0.04`)
- Damping `0.85` with a per-node speed clamp (`±6`)
- A hard frame cap (e.g. 600) so the simulation **always stops** even if forces fight

## Rendering performance

- Create SVG elements once, cache references, update attributes per frame (never `querySelectorAll` per frame)
- Index nodes/links directly (`elements[i]` ↔ `nodes[i]`) — zero object allocation per frame
- Let the simulation settle before showing the graph, or start it on open and stop on convergence

## Related

- [[Markdown-Wikilinks-Connect-Your-Knowledge]]
- [[Why-SVG-Icons-Flicker-on-Click]]
- [[CSS-Transition-and-Theme-Switching]]
