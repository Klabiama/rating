<?php
declare(strict_types=1);

require __DIR__ . '/uAPImodule.php';

function need_env(string $name): string {
  $v = getenv($name);
  if ($v === false || trim($v) === '') {
    fwrite(STDERR, "Missing env: {$name}\n");
    exit(1);
  }
  return $v;
}

function uapi_decode($resp): array {
  if (is_string($resp)) {
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : [];
  }
  return is_array($resp) ? $resp : [];
}

$request = new Request([
  'oauth_consumer_key'    => need_env('UCOZ_CONSUMER_KEY'),
  'oauth_consumer_secret' => need_env('UCOZ_CONSUMER_SECRET'),
  'oauth_token'           => need_env('UCOZ_TOKEN'),
  'oauth_token_secret'    => need_env('UCOZ_TOKEN_SECRET'),
]);

$docsDir = __DIR__ . '/docs';
if (!is_dir($docsDir)) mkdir($docsDir, 0775, true);

$fieldsResp = uapi_decode($request->get('/users/fields', []));
file_put_contents($docsDir . '/uapi_users_fields.json', json_encode($fieldsResp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$page1 = uapi_decode($request->get('/users', ['page' => 1, 'per_page' => 50]));
file_put_contents($docsDir . '/uapi_users_page1.json', json_encode($page1, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$perPage = 50;
$page = 1;
$out = [];
$totalUsersSeen = 0;
$totalWithHomePage = 0;

do {
  $resp = uapi_decode($request->get('/users', ['page' => $page, 'per_page' => $perPage]));

  $pages = (int)($resp['pages'] ?? 1);
  $users = $resp['users'] ?? [];

  if (is_array($users)) {
    $totalUsersSeen += count($users);
    foreach ($users as $u) {
      $home = trim((string)($u['home_page'] ?? ''));
      if ($home !== '') $totalWithHomePage++;

      if ($home === '') continue;

      $out[] = [
        'uid'       => (int)($u['uid'] ?? 0),
        'user'      => (string)($u['user'] ?? ''),
        'group_id'  => (int)($u['group']['id'] ?? 0),
        'home_page' => $home,
        'skype'     => (string)($u['skype'] ?? ''),
      ];
    }
  }

  $page++;
} while ($page <= $pages);

file_put_contents($docsDir . '/users_with_www.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$stats = [
  'total_users_seen' => $totalUsersSeen,
  'total_with_home_page_any' => $totalWithHomePage,
  'total_with_www_saved' => count($out),
];
file_put_contents($docsDir . '/uapi_users_stats.json', json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "OK\n";
echo "Users seen: {$totalUsersSeen}\n";
echo "Users with home_page (any): {$totalWithHomePage}\n";
echo "Saved to users_with_www: " . count($out) . "\n";
