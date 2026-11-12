<?php
// AI router provider statistics (OpenRouter, OpenAI, Anthropic).
// Uses management/admin keys only. Keys never leave the server.

const LLMSTATS_PROVIDER_CACHE = '/tmp/llmstats-providers.json';
const LLMSTATS_PROVIDER_WINDOW = 86400; // rolling 24h
const LLMSTATS_PROVIDER_CACHE_VERSION = 'v3';

function llmstats_provider_defs()
{
    return [
        'openrouter' => ['label' => 'OpenRouter', 'keyHint' => 'Management key (sk-or-v1-…)'],
        'openai'     => ['label' => 'OpenAI',     'keyHint' => 'Admin key (sk-admin-…)'],
        'anthropic'  => ['label' => 'Anthropic',  'keyHint' => 'Admin key (sk-ant-admin…)'],
    ];
}

function llmstats_provider_refresh_options()
{
    return [5, 15, 30, 60, 120];
}

function llmstats_provider_refresh_minutes($value)
{
    $minutes = is_numeric($value) ? (int)$value : 15;

    return in_array($minutes, llmstats_provider_refresh_options(), true) ? $minutes : 15;
}

function llmstats_provider_enabled_ids($cfg)
{
    $ids = [];
    foreach (array_keys(llmstats_provider_defs()) as $id) {
        if (($cfg['PROVIDER_' . strtoupper($id) . '_ENABLED'] ?? '0') === '1') {
            $ids[] = $id;
        }
    }

    return $ids;
}

function llmstats_provider_key($cfg, $id)
{
    return trim((string)($cfg['PROVIDER_' . strtoupper($id) . '_KEY'] ?? ''));
}

function llmstats_provider_http_get($url, $headers, $timeout = 20)
{
    $result = ['code' => 0, 'body' => '', 'error' => ''];
    if (!function_exists('curl_init')) {
        $result['error'] = 'PHP curl extension missing';
        return $result;
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($handle);
    $result['code'] = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        $result['error'] = curl_error($handle);
    } else {
        $result['body'] = (string)$body;
    }
    curl_close($handle);

    return $result;
}

// Returns [array|null $data, string $error].
function llmstats_provider_get_json($url, $headers)
{
    $r = llmstats_provider_http_get($url, $headers);
    if ($r['error'] !== '') {
        return [null, 'Network error: ' . $r['error']];
    }
    if ($r['code'] === 401 || $r['code'] === 403) {
        return [null, 'Key rejected or missing admin/management permission (HTTP ' . $r['code'] . ').'];
    }
    $data = json_decode($r['body'], true);
    if ($r['code'] < 200 || $r['code'] >= 300) {
        $msg = is_array($data) ? ($data['error']['message'] ?? ($data['error'] ?? '')) : '';
        $msg = is_string($msg) ? $msg : '';

        return [null, 'HTTP ' . $r['code'] . ($msg !== '' ? ': ' . $msg : '')];
    }
    if (!is_array($data)) {
        return [null, 'Invalid JSON response.'];
    }

    return [$data, ''];
}

function llmstats_provider_new_totals()
{
    return [
        'requests' => 0,
        'inputTokens' => 0,        // all input-side tokens incl. cache reads/writes
        'cachedInputTokens' => 0,  // cache reads
        'outputTokens' => 0,
        'cost' => null,            // USD, null when provider gives no cost
        'hasCacheData' => false,
        'requestsAvailable' => true,
        'models' => [],
        'keys' => [],
        'costNote' => '',
        'keyNote' => '',
        'windowNote' => '',
    ];
}

function llmstats_provider_add_model(&$totals, $model, $requests, $inputTokens, $cachedTokens, $outputTokens, $cost = null)
{
    $totals['requests'] += $requests;
    $totals['inputTokens'] += $inputTokens;
    $totals['cachedInputTokens'] += $cachedTokens;
    $totals['outputTokens'] += $outputTokens;

    $name = $model !== '' ? $model : 'unknown';
    if (!isset($totals['models'][$name])) {
        $totals['models'][$name] = ['model' => $name, 'requests' => 0, 'tokens' => 0, 'cost' => null];
    }
    $totals['models'][$name]['requests'] += $requests;
    $totals['models'][$name]['tokens'] += $inputTokens + $outputTokens;
    if ($cost !== null) {
        $totals['models'][$name]['cost'] = ($totals['models'][$name]['cost'] ?? 0) + $cost;
    }
}

// Per-key accumulator. Does not touch the provider totals (those come from add_model).
function llmstats_provider_add_key(&$totals, $keyId, $requests, $inputTokens, $cachedTokens, $outputTokens)
{
    $id = $keyId !== '' ? $keyId : 'unknown';
    if (!isset($totals['keys'][$id])) {
        $totals['keys'][$id] = [
            'id' => $id, 'name' => $id, 'requests' => 0, 'inputTokens' => 0,
            'cachedInputTokens' => 0, 'outputTokens' => 0, 'cost' => null, 'costWeek' => null, 'costMonth' => null,
            'disabled' => false,
        ];
    }
    $totals['keys'][$id]['requests'] += $requests;
    $totals['keys'][$id]['inputTokens'] += $inputTokens;
    $totals['keys'][$id]['cachedInputTokens'] += $cachedTokens;
    $totals['keys'][$id]['outputTokens'] += $outputTokens;
}

// OpenAI: completions usage (hourly buckets, grouped by model and API key) + daily cost buckets.
function llmstats_provider_fetch_openai($key, $since, $until)
{
    $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
    $totals = llmstats_provider_new_totals();
    $totals['hasCacheData'] = true;

    $page = '';
    for ($i = 0; $i < 10; $i++) {
        $url = 'https://api.openai.com/v1/organization/usage/completions?start_time=' . $since
            . '&end_time=' . $until . '&bucket_width=1h&limit=24&group_by[]=model&group_by[]=api_key_id'
            . ($page !== '' ? '&page=' . rawurlencode($page) : '');
        [$data, $err] = llmstats_provider_get_json($url, $headers);
        if ($data === null) {
            return [null, $err];
        }
        foreach (($data['data'] ?? []) as $bucket) {
            foreach (($bucket['results'] ?? []) as $row) {
                $req = (int)($row['num_model_requests'] ?? 0);
                $in = (int)($row['input_tokens'] ?? 0);
                $cached = (int)($row['input_cached_tokens'] ?? 0);
                $out = (int)($row['output_tokens'] ?? 0);
                llmstats_provider_add_model($totals, (string)($row['model'] ?? ''), $req, $in, $cached, $out);
                llmstats_provider_add_key($totals, (string)($row['api_key_id'] ?? ''), $req, $in, $cached, $out);
            }
        }
        $page = (string)($data['next_page'] ?? '');
        if (empty($data['has_more']) || $page === '') {
            break;
        }
    }

    // Costs are daily buckets; the window is rounded down to the UTC day.
    $dayStart = $since - ($since % 86400);
    $costUrl = 'https://api.openai.com/v1/organization/costs?start_time=' . $dayStart
        . '&end_time=' . $until . '&bucket_width=1d&limit=7';
    [$costData, $costErr] = llmstats_provider_get_json($costUrl, $headers);
    if ($costData !== null) {
        $cost = 0.0;
        foreach (($costData['data'] ?? []) as $bucket) {
            foreach (($bucket['results'] ?? []) as $row) {
                $cost += (float)($row['amount']['value'] ?? 0);
            }
        }
        $totals['cost'] = $cost;
    } else {
        $totals['costNote'] = 'Cost unavailable: ' . $costErr;
    }
    $totals['keyNote'] = 'OpenAI does not report cost per API key; key view shows tokens and requests.';

    return [$totals, ''];
}

// Anthropic: message usage report (hourly, grouped by model and API key) + daily cost report.
function llmstats_provider_fetch_anthropic($key, $since, $until)
{
    $headers = ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'Accept: application/json'];
    $totals = llmstats_provider_new_totals();
    $totals['hasCacheData'] = true;
    $totals['requestsAvailable'] = false; // usage report has no request count
    $start = gmdate('Y-m-d\TH:i:s\Z', $since);
    $end = gmdate('Y-m-d\TH:i:s\Z', $until);

    $page = '';
    for ($i = 0; $i < 10; $i++) {
        $url = 'https://api.anthropic.com/v1/organizations/usage_report/messages?starting_at=' . rawurlencode($start)
            . '&ending_at=' . rawurlencode($end) . '&bucket_width=1h&limit=24&group_by[]=model&group_by[]=api_key_id'
            . ($page !== '' ? '&page=' . rawurlencode($page) : '');
        [$data, $err] = llmstats_provider_get_json($url, $headers);
        if ($data === null) {
            return [null, $err];
        }
        foreach (($data['data'] ?? []) as $bucket) {
            foreach (($bucket['results'] ?? []) as $row) {
                $cacheRead = (int)($row['cache_read_input_tokens'] ?? 0);
                $cacheWrite = 0;
                foreach (($row['cache_creation'] ?? []) as $v) {
                    $cacheWrite += (int)$v;
                }
                $in = (int)($row['uncached_input_tokens'] ?? 0) + $cacheRead + $cacheWrite;
                $out = (int)($row['output_tokens'] ?? 0);
                llmstats_provider_add_model($totals, (string)($row['model'] ?? ''), 0, $in, $cacheRead, $out);
                llmstats_provider_add_key($totals, (string)($row['api_key_id'] ?? ''), 0, $in, $cacheRead, $out);
            }
        }
        $page = (string)($data['next_page'] ?? '');
        if (empty($data['has_more']) || $page === '') {
            break;
        }
    }

    $dayStart = gmdate('Y-m-d\TH:i:s\Z', $since - ($since % 86400));
    $costUrl = 'https://api.anthropic.com/v1/organizations/cost_report?starting_at=' . rawurlencode($dayStart)
        . '&ending_at=' . rawurlencode($end) . '&bucket_width=1d';
    [$costData, $costErr] = llmstats_provider_get_json($costUrl, $headers);
    if ($costData !== null) {
        $cents = 0.0;
        foreach (($costData['data'] ?? []) as $bucket) {
            foreach (($bucket['results'] ?? []) as $row) {
                $cents += (float)($row['amount'] ?? 0);
            }
        }
        $totals['cost'] = $cents / 100.0;
    } else {
        $totals['costNote'] = 'Cost unavailable: ' . $costErr;
    }
    $totals['keyNote'] = 'Anthropic does not report cost per API key; key view shows tokens only.';

    return [$totals, ''];
}

// OpenRouter: activity gives daily rows per model (UTC, lags ~1 day); /keys gives cost per API key.
function llmstats_provider_fetch_openrouter($key, $since, $until)
{
    $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
    [$data, $err] = llmstats_provider_get_json('https://openrouter.ai/api/v1/activity', $headers);
    if ($data === null) {
        // No management rights: fall back to key-only mode (cost of this key, no tokens/requests).
        return llmstats_provider_fetch_openrouter_key_only($headers, $err);
    }

    $totals = llmstats_provider_new_totals();
    $totals['hasCacheData'] = true;
    $days = [gmdate('Y-m-d', $until - 86400), gmdate('Y-m-d', $until)];
    $cost = 0.0;
    foreach (($data['data'] ?? []) as $row) {
        if (!in_array(substr((string)($row['date'] ?? ''), 0, 10), $days, true)) {
            continue;
        }
        $rowCost = (float)($row['usage'] ?? 0);
        $cost += $rowCost;
        llmstats_provider_add_model(
            $totals,
            (string)($row['model'] ?? ''),
            (int)($row['requests'] ?? 0),
            (int)($row['prompt_tokens'] ?? 0),
            (int)($row['cached_tokens'] ?? 0),
            (int)($row['completion_tokens'] ?? 0) + (int)($row['reasoning_tokens'] ?? 0),
            $rowCost
        );
    }
    $totals['cost'] = $cost;
    $totals['windowNote'] = 'Yesterday + today (UTC) - OpenRouter reports daily totals; data lags ~1 day';

    // Per-key cost. The key list has no token/request data, only cost (today, week, month).
    [$keysData, $keysErr] = llmstats_provider_get_json('https://openrouter.ai/api/v1/keys', $headers);
    if ($keysData !== null) {
        foreach (($keysData['data'] ?? []) as $k) {
            $id = (string)($k['hash'] ?? ($k['label'] ?? ''));
            if ($id === '') {
                continue;
            }
            $totals['keys'][$id] = [
                'id' => $id,
                'name' => (string)($k['name'] ?? ($k['label'] ?? $id)),
                'requests' => null,
                'inputTokens' => null,
                'cachedInputTokens' => null,
                'outputTokens' => null,
                'cost' => (float)($k['usage_daily'] ?? 0),
                'costWeek' => (float)($k['usage_weekly'] ?? 0),
                'costMonth' => (float)($k['usage_monthly'] ?? 0),
                'disabled' => !empty($k['disabled']),
            ];
        }
        $totals['keyNote'] = 'Key cost is today (UTC). OpenRouter does not report tokens or requests per key.';
    } else {
        $totals['keyNote'] = 'Keys unavailable: ' . $keysErr;
    }

    return [$totals, ''];
}

// Key-only mode for OpenRouter: a regular API key can read its own usage via /api/v1/key.
// That gives cost (today, week, month) and the limit, but no tokens, requests or models.
function llmstats_provider_fetch_openrouter_key_only($headers, $activityErr)
{
    [$me, $meErr] = llmstats_provider_get_json('https://openrouter.ai/api/v1/key', $headers);
    if ($me === null) {
        return [null, $activityErr . ' Key info also failed: ' . $meErr];
    }
    $d = $me['data'] ?? [];

    $totals = llmstats_provider_new_totals();
    $totals['requestsAvailable'] = false;
    $totals['hasCacheData'] = false;
    $totals['cost'] = (float)($d['usage_daily'] ?? 0);
    $totals['windowNote'] = 'Key-only mode: this key\'s cost today (UTC). Tokens, requests and models need a management key.';
    $totals['keys']['self'] = [
        'id' => 'self',
        'name' => (string)($d['label'] ?? 'this key'),
        'requests' => null,
        'inputTokens' => null,
        'cachedInputTokens' => null,
        'outputTokens' => null,
        'cost' => (float)($d['usage_daily'] ?? 0),
        'costWeek' => (float)($d['usage_weekly'] ?? 0),
        'costMonth' => (float)($d['usage_monthly'] ?? 0),
        'disabled' => !empty($d['disabled']),
    ];
    $limit = $d['limit'] ?? null;
    $totals['keyNote'] = $limit !== null
        ? 'Key limit: $' . number_format((float)$limit, 2, '.', '') . ', remaining $' . number_format((float)($d['limit_remaining'] ?? 0), 2, '.', '') . '.'
        : 'No spending limit set on this key.';

    return [$totals, ''];
}

function llmstats_provider_fetch($id, $key, $since, $until)
{
    switch ($id) {
        case 'openrouter':
            return llmstats_provider_fetch_openrouter($key, $since, $until);
        case 'openai':
            return llmstats_provider_fetch_openai($key, $since, $until);
        case 'anthropic':
            return llmstats_provider_fetch_anthropic($key, $since, $until);
    }

    return [null, 'Unknown provider.'];
}

function llmstats_provider_fmt_tokens($n)
{
    if ($n >= 1000000) {
        return round($n / 1000000, 1) . ' M tok';
    }
    if ($n >= 1000) {
        return round($n / 1000, 1) . ' k tok';
    }

    return $n . ' tok';
}

function llmstats_provider_summarize($id, $label, $totals)
{
    $models = array_values($totals['models']);
    usort($models, function ($a, $b) {
        return $b['tokens'] <=> $a['tokens'];
    });
    $models = array_slice($models, 0, 8);

    $requests = (int)$totals['requests'];
    $tokens = $totals['inputTokens'] + $totals['outputTokens'];
    $cacheRate = ($totals['hasCacheData'] && $totals['inputTokens'] > 0)
        ? $totals['cachedInputTokens'] / $totals['inputTokens']
        : null;
    $avg = ($requests > 0 && $totals['requestsAvailable']) ? (int)round($tokens / $requests) : null;

    // Per-key rows, sorted by cost (when known), then by tokens.
    $keys = [];
    foreach ($totals['keys'] as $k) {
        $kIn = $k['inputTokens'];
        $kTokens = $kIn === null ? null : $kIn + $k['outputTokens'];
        $keys[] = [
            'id' => $k['id'],
            'name' => $k['name'],
            'requests' => $k['requests'],
            'tokens' => $kTokens,
            'cacheRate' => ($kIn !== null && $kIn > 0) ? $k['cachedInputTokens'] / $kIn : null,
            'cost' => $k['cost'],
            'costWeek' => $k['costWeek'],
            'costMonth' => $k['costMonth'],
            'disabled' => $k['disabled'],
        ];
    }
    usort($keys, function ($a, $b) {
        $ca = $a['cost'] ?? -1;
        $cb = $b['cost'] ?? -1;
        if ($ca !== $cb) {
            return $cb <=> $ca;
        }

        return ($b['tokens'] ?? 0) <=> ($a['tokens'] ?? 0);
    });
    $keys = array_slice($keys, 0, 25);

    // Short summary for the collapsed dashboard row.
    $meta = [];
    if ($tokens > 0) {
        $meta[] = llmstats_provider_fmt_tokens($tokens);
    }
    if ($cacheRate !== null) {
        $meta[] = round($cacheRate * 100) . '% cache';
    }
    if ($totals['cost'] !== null) {
        $meta[] = '$' . number_format((float)$totals['cost'], 2, '.', '');
    }

    return [
        'id' => $id,
        'label' => $label,
        'status' => 'ok',
        'error' => '',
        'chipMeta' => implode(' · ', $meta),
        'requests' => $requests,
        'requestsAvailable' => $totals['requestsAvailable'],
        'inputTokens' => (int)$totals['inputTokens'],
        'cachedInputTokens' => (int)$totals['cachedInputTokens'],
        'outputTokens' => (int)$totals['outputTokens'],
        'totalTokens' => (int)$tokens,
        'avgTokensPerRequest' => $avg,
        'cacheHitRate' => $cacheRate,
        'cost' => $totals['cost'],
        'costNote' => $totals['costNote'],
        'keyNote' => $totals['keyNote'],
        'windowNote' => $totals['windowNote'],
        'models' => $models,
        'keys' => $keys,
    ];
}

// Builds the provider stats payload. Results are cached in /tmp for the configured window;
// the cache is invalidated when the set of enabled providers or their keys changes.
function llmstats_provider_stats($cfg, $now = null)
{
    $now = $now ?? time();
    $minutes = llmstats_provider_refresh_minutes($cfg['PROVIDER_REFRESH_MINUTES'] ?? 15);
    $defs = llmstats_provider_defs();
    $enabled = llmstats_provider_enabled_ids($cfg);

    $sigParts = [];
    foreach ($enabled as $id) {
        $sigParts[] = $id . ':' . hash('sha256', llmstats_provider_key($cfg, $id));
    }
    $sig = hash('sha256', implode('|', $sigParts) . '|' . LLMSTATS_PROVIDER_WINDOW . '|' . LLMSTATS_PROVIDER_CACHE_VERSION);

    $cached = @file_get_contents(LLMSTATS_PROVIDER_CACHE);
    $cached = $cached !== false ? json_decode($cached, true) : null;
    if (is_array($cached)
        && ($cached['sig'] ?? '') === $sig
        && ($cached['refreshMinutes'] ?? 0) === $minutes
        && ($now - (int)($cached['generatedAt'] ?? 0)) < $minutes * 60
    ) {
        return $cached['payload'];
    }

    $since = $now - LLMSTATS_PROVIDER_WINDOW;
    $providers = [];
    foreach ($enabled as $id) {
        $label = $defs[$id]['label'];
        $key = llmstats_provider_key($cfg, $id);
        if ($key === '') {
            $providers[] = ['id' => $id, 'label' => $label, 'status' => 'error', 'error' => 'No key saved.'];
            continue;
        }
        [$totals, $err] = llmstats_provider_fetch($id, $key, $since, $now);
        if ($totals === null) {
            $providers[] = ['id' => $id, 'label' => $label, 'status' => 'error', 'error' => $err];
            continue;
        }
        $providers[] = llmstats_provider_summarize($id, $label, $totals);
    }

    $payload = [
        'enabled' => count($enabled) > 0,
        'windowHours' => LLMSTATS_PROVIDER_WINDOW / 3600,
        'refreshMinutes' => $minutes,
        'generatedAt' => $now,
        'providers' => $providers,
    ];

    @file_put_contents(
        LLMSTATS_PROVIDER_CACHE,
        json_encode(['sig' => $sig, 'refreshMinutes' => $minutes, 'generatedAt' => $now, 'payload' => $payload]),
        LOCK_EX
    );

    return $payload;
}
