<?php
putenv('BRAINPRESS_DSN=sqlite::memory:');
require dirname(__DIR__) . '/src/bootstrap.php';
function verify($ok, $name) { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
$source = "---\ndraft: true\nauthor: Ada\ndate: 2026-10-14\ntags: [notes, php]\n---\n## Hello\n\nBody";
[$meta, $body] = frontmatter($source);
verify($meta['draft'] === true && $meta['author'] === 'Ada' && count($meta['tags']) === 2, 'Typed YAML and unknown properties preserved');
verify(str_contains(markdown($source), '<h2>Hello</h2>') && !str_contains(markdown($source), 'draft:'), 'Metadata hidden from reading and excerpts');
verify(frontmatter("---\nNot a header")[1] === "---\nNot a header", 'Unclosed delimiter stays Markdown');
try { frontmatter("---\nauthor: [broken\n---\nBody"); throw new RuntimeException('Invalid YAML accepted'); } catch (Symfony\Component\Yaml\Exception\ParseException) { echo "PASS Invalid YAML rejected\n"; }
verify(in_array('status', array_column(db()->query('PRAGMA table_info(posts)')->fetchAll(), 'name')), 'Existing schema gains status');
