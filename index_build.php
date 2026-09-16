<?php
declare(strict_types=1);

/**
 * Build the static GitHub Pages landing page from project metadata.
 *
 * Metadata is discovered from:
 *   - <folder>/index.meta.json for folder projects
 *   - .meta/<filename>.meta.json for root-level single-file projects
 */

$root = __DIR__;
$metadataFiles = [];

$rootMetadataDir = $root . DIRECTORY_SEPARATOR . '.meta';
if (is_dir($rootMetadataDir)) {
    foreach (glob($rootMetadataDir . DIRECTORY_SEPARATOR . '*.meta.json') ?: [] as $file) {
        $metadataFiles[] = $file;
    }
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getFilename() !== 'index.meta.json') {
        continue;
    }

    $path = $fileInfo->getPathname();
    if (str_starts_with($path, $root . DIRECTORY_SEPARATOR . '.meta' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $metadataFiles[] = $path;
}

$projects = [];
foreach (array_unique($metadataFiles) as $metadataFile) {
    $raw = file_get_contents($metadataFile);
    $metadata = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($metadata) || !isset($metadata['entry'], $metadata['title'], $metadata['description'], $metadata['section'])) {
        fwrite(STDERR, "Skipping invalid metadata: {$metadataFile}\n");
        continue;
    }

    $entry = ltrim(str_replace('\\', '/', (string) $metadata['entry']), '/');
    if ($entry === '' || $entry === 'index.php' || !is_file($root . DIRECTORY_SEPARATOR . $entry)) {
        fwrite(STDERR, "Skipping invalid or missing entry: {$entry}\n");
        continue;
    }

    $metadata['entry'] = $entry;
    $metadata['featured'] = (bool) ($metadata['featured'] ?? false);
    $metadata['version'] = isset($metadata['version']) ? trim((string) $metadata['version']) : '';
    $projects[] = $metadata;
}

$sectionOrder = [
    'Playable browser snippets',
    'Secure transfer snippets',
    'PocketSmith MCP bridge',
];

usort($projects, static function (array $a, array $b) use ($sectionOrder): int {
    $sectionA = array_search($a['section'], $sectionOrder, true);
    $sectionB = array_search($b['section'], $sectionOrder, true);
    $sectionA = $sectionA === false ? PHP_INT_MAX : $sectionA;
    $sectionB = $sectionB === false ? PHP_INT_MAX : $sectionB;
    return [$sectionA, $a['featured'] ? 0 : 1, strcasecmp((string) $a['title'], (string) $b['title'])]
        <=> [$sectionB, $b['featured'] ? 0 : 1, strcasecmp((string) $b['title'], (string) $a['title'])];
});

$sections = [];
foreach ($projects as $project) {
    $sections[$project['section']][] = $project;
}

$sectionDecor = [
    'Playable browser snippets' => ['icon' => '🕹️', 'count' => 'standalone HTML'],
    'Secure transfer snippets' => ['icon' => '🔐', 'count' => 'PHP + browser crypto'],
    'PocketSmith MCP bridge' => ['icon' => '🧾', 'count' => 'OAuth • JSON-RPC • PHP'],
];

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sectionHtml = '';
foreach ($sections as $sectionName => $sectionProjects) {
    $decor = $sectionDecor[$sectionName] ?? ['icon' => '🗂️', 'count' => 'metadata-driven snippets'];
    $sectionHtml .= "      <section>\n";
    $sectionHtml .= '        <div class="section-head"><h2>' . $escape($decor['icon'] . ' ' . $sectionName) . '</h2><span class="count">' . $escape($decor['count']) . "</span></div>\n";
    $sectionHtml .= "        <div class=\"cards\">\n";

    foreach ($sectionProjects as $project) {
        $classes = 'card' . ($project['featured'] ? ' featured' : '');
        $displayTitle = trim((string) $project['title'] . ($project['version'] !== '' ? ' ' . $project['version'] : ''));
        $sectionHtml .= '          <article class="' . $classes . '">';
        $sectionHtml .= '<h3><a href="' . $escape($project['entry']) . '">' . $escape($displayTitle) . '</a></h3>';
        $sectionHtml .= '<p>' . $escape((string) $project['description']) . '</p>';
        $sectionHtml .= '<div class="path">./' . $escape($project['entry']) . "</div></article>\n";
    }

    $sectionHtml .= "        </div>\n      </section>\n\n";
}

$html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Snippetlylickety | Pirate's Code</title>
  <style>
    :root{--ink:#081014;--sea:#0d1e25;--panel:#142b31;--panel-2:#19373b;--line:#2b5558;--gold:#f5b83d;--gold-soft:#ffd978;--text:#e8f0ed;--muted:#a5bbb7;--green:#65d391;--red:#ff7567}
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;color:var(--text);background:radial-gradient(circle at 50% -10%,#244a4b 0,#10272d 38%,var(--ink) 78%);font:16px/1.55 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    a{color:var(--gold-soft)}
    .wrap{width:min(1080px,100%);margin:auto;padding:28px 16px 54px}
    header{position:relative;text-align:center;padding:28px 18px 30px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(145deg,rgba(25,55,59,.94),rgba(11,28,34,.96));box-shadow:0 18px 60px rgba(0,0,0,.3);overflow:hidden}
    header:after{content:"⚓  ⚔  ⚓";display:block;margin-top:10px;color:var(--gold);letter-spacing:1.2em;font-size:18px;opacity:.8}
    h1{margin:0;color:var(--gold);font-size:clamp(2rem,7vw,4.4rem);line-height:1.05;letter-spacing:.04em;text-shadow:0 3px 0 #4e3311}
    .tagline{max-width:650px;margin:14px auto 0;color:var(--muted);font-size:1.05rem}
    .flag{display:inline-block;margin-bottom:16px;padding:5px 11px;border:1px solid #8e6c27;border-radius:999px;color:var(--gold-soft);background:#302711;text-transform:uppercase;font-size:.72rem;font-weight:800;letter-spacing:.16em}
    main{display:grid;gap:22px;margin-top:24px}
    section{border:1px solid var(--line);border-radius:14px;padding:18px;background:rgba(15,34,40,.9)}
    .section-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);margin-bottom:14px;padding-bottom:10px}
    h2{margin:0;color:var(--gold);font-size:1.2rem;letter-spacing:.04em}
    .count{color:var(--muted);font-size:.8rem}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:12px}
    .card{display:flex;flex-direction:column;min-height:132px;padding:15px;border:1px solid #2a4b4d;border-radius:10px;background:linear-gradient(160deg,var(--panel-2),var(--panel));transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease}
    .card:hover,.card:focus-within{transform:translateY(-2px);border-color:var(--gold);box-shadow:0 8px 24px rgba(0,0,0,.22)}
    .card h3{margin:0 0 6px;font-size:1rem}
    .card h3 a{text-decoration:none}
    .card h3 a:hover{text-decoration:underline}
    .card p{margin:0;color:var(--muted);font-size:.91rem}
    .path{margin-top:auto;padding-top:12px;color:#78aaa7;font:600 .76rem/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}
    .featured{border-color:#806329;background:linear-gradient(145deg,#263d34,#172b2d)}
    .featured h3 a{color:var(--gold-soft)}
    .note{margin:22px 2px 0;color:var(--muted);font-size:.86rem;text-align:center}
    footer{margin-top:28px;color:#71928c;text-align:center;font-size:.8rem}
    @media (max-width:520px){.wrap{padding:14px 10px 36px}header{padding:24px 14px}.section-head{align-items:flex-start;flex-direction:column;gap:2px}section{padding:14px}.card{min-height:0}}
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <span class="flag">The ship's locker ⚓ main</span>
      <h1>SNIPPETYLICKETY</h1>
      <p class="tagline">A small deck of browser experiments, secure PHP utilities, and PocketSmith bridge code. Pick a logbook below and set sail.</p>
    </header>

    <main>
__SECTIONS__    </main>
    <p class="note">Everything linked here is relative, self-contained, and dependency-light. External services and server-side snippets need their own HTTPS/PHP configuration.</p>
    <footer>Built for GitHub Pages</footer>
  </div>
</body>
</html>
HTML;

$html = str_replace('__SECTIONS__', $sectionHtml, $html);
file_put_contents($root . DIRECTORY_SEPARATOR . 'index.html', $html);
echo 'Generated index.html with ' . count($projects) . " metadata entries.\n";
