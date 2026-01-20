<?php
declare(strict_types=1);

function need_env(string $name): string {
  $v = getenv($name);
  if ($v === false || trim($v) === '') {
    fwrite(STDERR, "Missing env: {$name}\n");
    exit(1);
  }
  return $v;
}

function http_post_form(string $url, array $headers, array $form): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($form),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($body === false) throw new RuntimeException("cURL error: {$err}");
  return [$code, $body];
}

function http_get_json(string $url, array $headers): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($body === false) throw new RuntimeException("cURL error: {$err}");
  $json = json_decode($body, true);
  return [$code, $json, $body];
}

function get_blizzard_token(string $clientId, string $clientSecret): string {
  $basic = base64_encode($clientId . ':' . $clientSecret);
  [$code, $body] = http_post_form(
    'https://oauth.battle.net/token',
    [
      "Authorization: Basic {$basic}",
      "Content-Type: application/x-www-form-urlencoded",
      "User-Agent: trialportal-hk-bot/1.0",
    ],
    ['grant_type' => 'client_credentials']
  );

  $data = json_decode($body, true);
  if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
    throw new RuntimeException("Token request failed ({$code}): {$body}");
  }
  return (string)$data['access_token'];
}

function key_for_char(string $region, string $realm, string $name): string {
  return mb_strtolower($region) . '|' . mb_strtolower($realm) . '|' . mb_strtolower($name);
}

$MAX_LEVEL = 20;

$clientId = need_env('BLIZZARD_CLIENT_ID');
$clientSecret = need_env('BLIZZARD_CLIENT_SECRET');

$docsDir = __DIR__ . '/docs';
if (!is_dir($docsDir)) mkdir($docsDir, 0775, true);

$charsPath = $docsDir . '/characters.json';
$chars = json_decode(file_get_contents($charsPath), true);
if (!is_array($chars) || count($chars) === 0) {
  throw new RuntimeException("characters.json empty or invalid");
}

$statePath = $docsDir . '/rating_state.json';
$state = [];
if (file_exists($statePath)) {
  $tmp = json_decode(file_get_contents($statePath), true);
  if (is_array($tmp)) $state = $tmp;
}

$monthKey = gmdate('Y-m');

$token = get_blizzard_token($clientId, $clientSecret);

$rows = [];
$errors = [];

foreach ($chars as $c) {
  $region = (string)$c['region'];
  $realm  = (string)$c['realm'];
  $name   = (string)$c['name'];

  $apiHost = "{$region}.api.blizzard.com";
  $ns = "profile-{$region}";

  $encodedName  = rawurlencode(mb_strtolower($name));
  $encodedRealm = rawurlencode(mb_strtolower($realm));

  $headers = [
    "Authorization: Bearer {$token}",
    "User-Agent: trialportal-hk-bot/1.0",
  ];


  $summaryUrl = "https://{$apiHost}/profile/wow/character/{$encodedRealm}/{$encodedName}?namespace={$ns}&locale=ru_RU";
  [$sCode, $sJson, $sRaw] = http_get_json($summaryUrl, $headers);

  if ($sCode !== 200 || !is_array($sJson)) {
    $errors[] = [
      'user' => $c['user'] ?? '',
      'uid' => $c['uid'] ?? 0,
      'char' => "{$region}/{$realm}/{$name}",
      'status' => $sCode,
      'where' => 'character_summary',
      'raw_sample' => mb_substr((string)$sRaw, 0, 200),
    ];
    continue;
  }

  $level = (int)($sJson['level'] ?? 0);
  if ($level <= 0) {
    $errors[] = [
      'user' => $c['user'] ?? '',
      'uid' => $c['uid'] ?? 0,
      'char' => "{$region}/{$realm}/{$name}",
      'status' => $sCode,
      'where' => 'character_summary',
      'raw_sample' => 'Missing or invalid level',
    ];
    continue;
  }


  if ($level > $MAX_LEVEL) {
    $errors[] = [
      'user' => $c['user'] ?? '',
      'uid' => $c['uid'] ?? 0,
      'char' => "{$region}/{$realm}/{$name}",
      'status' => 200,
      'where' => 'level_filter',
      'raw_sample' => "Skipped: level {$level} > {$MAX_LEVEL}",
    ];
    continue;
  }

  $pvpUrl = "https://{$apiHost}/profile/wow/character/{$encodedRealm}/{$encodedName}/pvp-summary?namespace={$ns}&locale=ru_RU";
  [$code, $json, $raw] = http_get_json($pvpUrl, $headers);

  if ($code !== 200 || !is_array($json)) {
    $errors[] = [
      'user' => $c['user'] ?? '',
      'uid' => $c['uid'] ?? 0,
      'char' => "{$region}/{$realm}/{$name}",
      'status' => $code,
      'where' => 'pvp_summary',
      'raw_sample' => mb_substr((string)$raw, 0, 200),
    ];
    continue;
  }

  $current = (int)($json['honorable_kills'] ?? 0);

  $k = key_for_char($region, $realm, $name);
  if (!isset($state[$k]) || !is_array($state[$k])) {
    $state[$k] = [
      'month' => $monthKey,
      'month_start' => $current,
      'last' => $current,
    ];
  }

  if (($state[$k]['month'] ?? '') !== $monthKey) {
    $prevMonthKey = (string)($state[$k]['month'] ?? '');
    $prevStart    = (int)($state[$k]['month_start'] ?? $current);
    $prevLast     = (int)($state[$k]['last'] ?? $current);
    $prevDelta    = max(0, $prevLast - $prevStart);

    $state[$k]['prev_month'] = $prevMonthKey;
    $state[$k]['prev_month_kills'] = $prevDelta;

    $state[$k]['month'] = $monthKey;
    $state[$k]['month_start'] = $current;
  }

  if ($current < (int)($state[$k]['month_start'] ?? 0)) {
    $state[$k]['month_start'] = $current;
  }

  $monthStart = (int)$state[$k]['month_start'];
  $monthDelta = max(0, $current - $monthStart);

  $state[$k]['last'] = $current;

  $rows[] = [
    'uid' => (int)($c['uid'] ?? 0),
    'user' => (string)($c['user'] ?? ''),
    'skype' => (string)($c['skype'] ?? ''),
    'char_url' => (string)($c['char_url'] ?? ''),
    'pvp_url' => (string)($c['pvp_url'] ?? ''),
    'region' => $region,
    'realm' => $realm,
    'name' => $name,
    'level' => $level,
    'honorable_kills_total' => $current,
    'honorable_kills_month' => $monthDelta,
    'honorable_kills_prev_month' => (int)($state[$k]['prev_month_kills'] ?? 0),
  ];
}

usort($rows, function($a, $b) {
  $d = ($b['honorable_kills_month'] <=> $a['honorable_kills_month']);
  if ($d !== 0) return $d;
  return ($b['honorable_kills_total'] <=> $a['honorable_kills_total']);
});

$out = [
  'month' => $monthKey,
  'updated_utc' => gmdate('c'),
  'count' => count($rows),
  'count_all_chars' => count($chars),
  'max_level' => $MAX_LEVEL,
  'rows' => $rows,
  'errors' => $errors,
];

file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($docsDir . '/rating.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$js = "window.TRIALPORTAL_RATING = " . json_encode($out, JSON_UNESCAPED_UNICODE) . ";\n";
file_put_contents($docsDir . '/rating_data.js', $js);

echo "OK: wrote docs/rating.json, docs/rating_data.js and docs/rating_state.json\n";
