<?php
declare(strict_types=1);

$in = __DIR__ . '/docs/users_with_www.json';
if (!file_exists($in)) {
  fwrite(STDERR, "Missing $in\n");
  exit(1);
}

$users = json_decode(file_get_contents($in), true);
if (!is_array($users)) {
  fwrite(STDERR, "Bad JSON in users_with_www.json\n");
  exit(1);
}

function parse_wow_character_url(string $url): ?array {
  $url = trim($url);
  if ($url === '') return null;

  if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
  $url = str_replace('\\', '/', $url);

  $re = '~^https?://worldofwarcraft\.blizzard\.com/(?:[a-z]{2}-[a-z]{2}/)?character/([^/]+)/([^/]+)/([^/?#]+)~iu';
  if (!preg_match($re, $url, $m)) return null;

  return [
    'region' => urldecode($m[1]),
    'realm'  => urldecode($m[2]),
    'name'   => urldecode($m[3]),
  ];
}

$out = [];

foreach ($users as $u) {
  $www = (string)($u['home_page'] ?? '');
  $www = trim($www);

  if ($www === '') {
    continue;
  }

  if (preg_match('~%[0-9A-Fa-f]{2}~', $www)) {
    continue;
  }

  $p = parse_wow_character_url($www);
  if (!$p) continue;

  $base = rtrim($www, '/'); 
  $base = preg_replace('~/(pvp)(/.*)?$~iu', '', $base);

  $out[] = [
    'uid'       => (int)($u['uid'] ?? 0),
    'user'      => (string)($u['user'] ?? ''),
    'skype'     => (string)($u['skype'] ?? ''),
    'home_page' => $www,
    'region'    => $p['region'],
    'realm'     => $p['realm'],
    'name'      => $p['name'],
    'char_url'  => $base,
    'pvp_url'   => $base . '/pvp',
  ];
}

$docsDir = __DIR__ . '/docs';
if (!is_dir($docsDir)) mkdir($docsDir, 0775, true);

file_put_contents($docsDir . '/characters.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "OK: " . count($out) . " character links parsed\n";
