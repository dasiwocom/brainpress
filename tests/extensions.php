<?php
require __DIR__ . '/../src/bootstrap.php';
function verify(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
verify(str_contains(markdown('~~removed~~'), '<del>removed</del>'), 'Strikethrough');
verify(str_contains(markdown("- [x] Completed\n- [ ] Pending"), 'type="checkbox"'), 'Task lists');
verify(str_contains(markdown("A note[^1]\n\n[^1]: Footnote content"), 'footnote'), 'Footnotes');
verify(str_contains(markdown('https://example.com'), 'href="https://example.com"'), 'Automatic links');
verify(str_contains(markdown('Inline $x_i^2$ formula'), 'class="math-source" data-display="false">x_i^2</span>'), 'Inline math preserves underscores');
verify(str_contains(markdown('中文 $x_i$ formula'), '>x_i</span>'), 'Inline math after Unicode text');
verify(str_contains(markdown('Inline \(\frac{a}{b}\) formula'), '\frac{a}{b}</span>'), 'Parenthesis math');
verify(str_contains(markdown("$$\n\\sum_{i=1}^{n} i\n$$"), 'data-display="true">\sum_{i=1}^{n} i</div>'), 'Multiline display math');
verify(str_contains(markdown("\\[\na^2+b^2=c^2\n\\]"), 'data-display="true"'), 'Bracket display math');
verify(!str_contains(markdown('`$x_i$`'), 'class="math-source"'), 'Inline code stays literal');
verify(!str_contains(markdown("```text\n$$\nx_i\n$$\n```"), 'class="math-source"'), 'Fenced code stays literal');
verify(!str_contains(markdown('Cost: $20 and $30 each.'), 'class="math-source"'), 'Ordinary prices stay literal');
verify(str_contains(markdown("```mermaid\nflowchart LR\nA-->B\n```"), 'language-mermaid'), 'Mermaid source reaches client renderer');
verify(!str_contains(markdown('Invalid $x_i'), 'class="math-source"'), 'Unclosed math stays literal');
