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

function find_honor_candidates($data): array {
  $found = [];
  $walk = function($node, string $path) use (&$walk, &$found) {
    if (!is_array($node)) return;

    foreach ($node as $k => $v) {
      $p = $path === '' ? (string)$k : ($path . '.' . $k);
      $kstr = (string)$k;

      if (is_numeric($v) && preg_match('~honor~i', $kstr) && preg_match('~kill~i', $kstr)) {
        $found[] = ['path' => $p, 'value' => (int)$v];
      }
      if (is_numeric($v) && preg_match('~honorable~i', $kstr)) {
        $found[] = ['path' => $p, 'value' => (int)$v];
      }

      $walk($v, $p);
    }
  };

  $walk($data, '');
  return $found;
}

$clientId = need_env('BLIZZARD_CLIENT_ID');
$clientSecret = need_env('BLIZZARD_CLIENT_SECRET');

$docsDir = __DIR__ . '/docs';
if (!is_dir($docsDir)) mkdir($docsDir, 0775, true);

$charsPath = $docsDir . '/characters.json';
$chars = json_decode(file_get_contents($charsPath), true);
if (!is_array($chars) || count($chars) === 0) {
  throw new RuntimeException("characters.json empty or invalid");
}

$token = get_blizzard_token($clientId, $clientSecret);

$results = [];

foreach ($chars as $c) {
  $region = (string)$c['region']; // eu
  $realm  = (string)$c['realm'];  // goldrinn
  $name   = (string)$c['name'];   // тентара

  $apiHost = "{$region}.api.blizzard.com";
  $ns = "profile-{$region}";

  $encodedName  = rawurlencode(mb_strtolower($name));
  $encodedRealm = rawurlencode(mb_strtolower($realm));

  $endpoints = [
    'pvp_summary' => "https://{$apiHost}/profile/wow/character/{$encodedRealm}/{$encodedName}/pvp-summary?namespace={$ns}&locale=ru_RU",
    'statistics'  => "https://{$apiHost}/profile/wow/character/{$encodedRealm}/{$encodedName}/statistics?namespace={$ns}&locale=ru_RU",
  ];

  $charRes = [
    'uid' => $c['uid'],
    'user' => $c['user'],
    'char' => ['region'=>$region,'realm'=>$realm,'name'=>$name],
    'http' => [],
    'honor_candidates' => [],
  ];

  foreach ($endpoints as $key => $url) {
    [$code, $json, $raw] = http_get_json($url, [
      "Authorization: Bearer {$token}",
      "User-Agent: trialportal-hk-bot/1.0",
    ]);

    $charRes['http'][$key] = [
      'status' => $code,
      'ok' => ($code === 200),
      'url' => $url,
    ];

    if (is_array($json)) {
      $cand = find_honor_candidates($json);
      if ($cand) $charRes['honor_candidates'][$key] = $cand;
    } else {
      $charRes['http'][$key]['raw_sample'] = mb_substr((string)$raw, 0, 500);
    }
  }

  $results[] = $charRes;
}

file_put_contents(
  $docsDir . '/honor_debug.json',
  json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

echo "OK: wrote docs/honor_debug.json\n";
