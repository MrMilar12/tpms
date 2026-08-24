<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
sendSecurityHeaders();
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function buildActiveContext(array $payload): ?array {
    $resultType = (string)($payload['result_type'] ?? ($_SESSION['chatbot_last_result_type'] ?? ''));
    if ($resultType === '') {
        return null;
    }

    $context = [
        'entity' => $resultType,
        'label' => '',
        'filters' => [],
    ];

    if ($resultType === 'teachers') {
        $f = $_SESSION['chatbot_last_filters'] ?? [];
        if (!is_array($f)) $f = [];
        $context['filters'] = array_filter([
            'q' => trim((string)($f['q'] ?? '')),
            'dist' => trim((string)($f['dist'] ?? '')),
            'pos' => trim((string)($f['pos'] ?? '')),
            'gen' => trim((string)($f['gen'] ?? '')),
            'spec' => trim((string)($f['spec'] ?? '')),
        ], static fn($v) => $v !== '');
        $context['label'] = !empty($context['filters'])
            ? ('Teachers filtered by ' . implode(', ', array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($context['filters']), array_values($context['filters']))))
            : 'Teachers (latest context)';
    } elseif ($resultType === 'schools') {
        $f = $_SESSION['chatbot_last_school_filters'] ?? [];
        if (!is_array($f)) $f = [];
        $context['filters'] = array_filter([
            'q' => trim((string)($f['q'] ?? '')),
            'dist' => trim((string)($f['dist'] ?? '')),
            'type' => trim((string)($f['type'] ?? '')),
            'staffing' => trim((string)($f['staffing'] ?? '')),
        ], static fn($v) => $v !== '' && $v !== 'all');
        $context['label'] = !empty($context['filters'])
            ? ('Schools filtered by ' . implode(', ', array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($context['filters']), array_values($context['filters']))))
            : 'Schools (latest context)';
    } elseif ($resultType === 'districts') {
        $seed = trim((string)($_SESSION['chatbot_last_district_seed'] ?? ''));
        if ($seed !== '') {
            $context['filters'] = ['dist' => $seed];
            $context['label'] = 'Districts filtered by: ' . $seed;
        } else {
            $context['label'] = 'Districts (latest context)';
        }
    } elseif ($resultType === 'universal') {
        $f = $_SESSION['chatbot_last_filters'] ?? [];
        if (!is_array($f)) $f = [];
        $seed = trim((string)($f['q'] ?? ''));
        if ($seed !== '') {
            $context['filters'] = ['q' => $seed];
            $context['label'] = 'Universal search for: ' . $seed;
        } else {
            $context['label'] = 'Universal search (latest context)';
        }
    } elseif ($resultType === 'planning') {
        $f = $_SESSION['chatbot_last_filters'] ?? [];
        if (!is_array($f)) $f = [];
        $school = trim((string)($f['q'] ?? ''));
        $dist = trim((string)($f['dist'] ?? ''));
        $context['filters'] = array_filter([
            'school' => $school,
            'dist' => $dist,
        ], static fn($v) => $v !== '');
        $context['label'] = $school !== ''
            ? ('Planning analysis for: ' . $school . ($dist !== '' ? (' (' . $dist . ')') : ''))
            : 'Planning analysis (latest context)';
    } elseif ($resultType === 'forecast') {
        $f = $_SESSION['chatbot_last_filters'] ?? [];
        if (!is_array($f)) $f = [];
        $school = trim((string)($f['q'] ?? ''));
        $dist = trim((string)($f['dist'] ?? ''));
        $growth = (float)($_SESSION['chatbot_last_forecast_pct'] ?? 0);
        $context['filters'] = array_filter([
            'school' => $school,
            'dist' => $dist,
            'growth_pct' => $growth > 0 ? number_format($growth, 1) . '%' : '',
        ], static fn($v) => $v !== '');
        $context['label'] = $school !== ''
            ? ('Forecast for: ' . $school . ($growth > 0 ? (' at +' . number_format($growth, 1) . '% enrollment') : ''))
            : 'Forecast analysis (latest context)';
    } else {
        return null;
    }

    return $context;
}

function chatbotJson(array $payload, int $status = 200): void {
    // Data-only mode: never expose system/page/file links through chatbot responses.
    if (isset($payload['links'])) {
        unset($payload['links']);
    }
    if (isset($payload['download']) && is_array($payload['download'])) {
        $payload['download'] = [
            'label' => (string)($payload['download']['label'] ?? 'Download data export'),
        ];
    }
    if (!empty($payload['results']) && is_array($payload['results'])) {
        foreach ($payload['results'] as &$row) {
            if (is_array($row) && isset($row['view_url'])) {
                unset($row['view_url']);
            }
        }
        unset($row);
    }
    $context = buildActiveContext($payload);
    if ($context !== null) {
        $payload['active_context'] = $context;
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    chatbotJson(['reply' => 'Method not allowed.'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    chatbotJson(['reply' => 'Invalid request body.'], 400);
}

$csrfToken = (string)($data['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    chatbotJson(['reply' => 'Invalid session token. Please refresh the page.'], 403);
}

$message = trim((string)($data['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 900) {
    chatbotJson(['reply' => 'Please provide a message up to 900 characters.'], 422);
}

$normalizedText = strtolower(trim((string)preg_replace('/\s+/', ' ', $message)));
$normalizedText = preg_replace('/[“”]/u', '"', $normalizedText);
$normalizedText = preg_replace('/[’]/u', "'", $normalizedText);

function normalizeConversationNoise(string $input): string {
    $text = strtolower(trim($input));
    $text = preg_replace('/\s+/', ' ', (string)$text);

    $typos = [
        'beacuse' => 'because',
        'becuse' => 'because',
        'scool' => 'school',
        'shcool' => 'school',
        'schoool' => 'school',
        'techer' => 'teacher',
        'teahcer' => 'teacher',
        'serch' => 'search',
        'retierment' => 'retirement',
        'mria' => 'maria',
    ];
    foreach ($typos as $from => $to) {
        $text = preg_replace('/\b' . preg_quote($from, '/') . '\b/i', $to, (string)$text);
    }

    // Keep the main command intent and drop trailing conversational explanations.
    $text = preg_replace('/\b(?:because|since|as|kasi)\b.*$/i', '', (string)$text);
    $text = preg_replace('/\b(?:cant|can\'t|cannot|not showing|not working|system can\'t show|system cannot show)\b.*$/i', '', (string)$text);
    return trim((string)$text, " \t\n\r\0\x0B.,:;!?");
}

$normalizedText = normalizeConversationNoise($normalizedText);

$commandLexicon = [
    'action' => ['show','find','search','list','get','display','download','export','generate','filter','track','open'],
    'entity' => ['teacher','teachers','employee','position','district','school','schools','specialization','male','female','settings','appearance','theme','background','municipality','barangay','located','near','within','from','at','overview','summary','retirement','users','system','status'],
    'format' => ['csv','excel','xlsx'],
    'followup' => ['this','that','same','again','also','continue','current','result','results'],
];
$analysisText = $normalizedText;
$intentTokens = [];
$trackedKeywords = [];

// Session rate limit: max 30 requests per minute per user session.
$window = 60;
$maxReq = 30;
$now = time();
if (empty($_SESSION['chatbot_req_times']) || !is_array($_SESSION['chatbot_req_times'])) {
    $_SESSION['chatbot_req_times'] = [];
}
$_SESSION['chatbot_req_times'] = array_values(array_filter(
    $_SESSION['chatbot_req_times'],
    static fn($ts) => is_int($ts) && ($now - $ts) < $window
));
if (count($_SESSION['chatbot_req_times']) >= $maxReq) {
    chatbotJson(['reply' => 'Too many requests. Please wait a minute and try again.'], 429);
}
$_SESSION['chatbot_req_times'][] = $now;

$db = getDB();
$text = strtolower($message);

/**
 * Tokenize user text for intent matching.
 * Keeps only alphanumeric tokens and removes tiny noise tokens.
 */
function tokenizeIntentWords(string $input): array {
    $parts = preg_split('/[^a-z0-9]+/i', strtolower($input)) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        $token = trim((string)$part);
        if ($token === '' || strlen($token) < 2) {
            continue;
        }
        $tokens[$token] = true;
    }
    return array_keys($tokens);
}

function normalizeIntentToken(string $token): string {
    $t = strtolower(trim($token));
    if (strlen($t) > 4 && str_ends_with($t, 'ies')) {
        return substr($t, 0, -3) . 'y';
    }
    if (strlen($t) > 3 && str_ends_with($t, 'es')) {
        return substr($t, 0, -2);
    }
    if (strlen($t) > 3 && str_ends_with($t, 's')) {
        return substr($t, 0, -1);
    }
    return $t;
}

function tokenMatchesKeywordSmart(string $token, string $keyword): bool {
    $tokenRaw = strtolower(trim($token));
    $keywordRaw = strtolower(trim($keyword));
    if ($tokenRaw === '' || $keywordRaw === '') {
        return false;
    }
    if ($tokenRaw === $keywordRaw) {
        return true;
    }

    $tokenNorm = normalizeIntentToken($tokenRaw);
    $keywordNorm = normalizeIntentToken($keywordRaw);
    if ($tokenNorm === $keywordNorm) {
        return true;
    }

    if (strlen($tokenNorm) >= 4 && strlen($keywordNorm) >= 4) {
        if (str_starts_with($tokenNorm, $keywordNorm) || str_starts_with($keywordNorm, $tokenNorm)) {
            if (abs(strlen($tokenNorm) - strlen($keywordNorm)) <= 2) {
                return true;
            }
        }
    }

    $len = max(strlen($tokenNorm), strlen($keywordNorm));
    if ($len >= 5) {
        $distance = levenshtein($tokenNorm, $keywordNorm);
        $allow = $len >= 8 ? 2 : 1;
        if ($distance <= $allow) {
            return true;
        }
    }

    return false;
}

function containsSmartIntent(string $input, array $keywords, array $tokens): bool {
    $haystack = strtolower($input);
    foreach ($keywords as $keyword) {
        $k = strtolower(trim((string)$keyword));
        if ($k === '') {
            continue;
        }

        if (str_contains($k, ' ')) {
            if (preg_match('/\b' . preg_quote($k, '/') . '\b/i', $haystack)) {
                return true;
            }
            $parts = preg_split('/\s+/', $k) ?: [];
            $allMatched = true;
            foreach ($parts as $part) {
                $partMatched = false;
                foreach ($tokens as $token) {
                    if (tokenMatchesKeywordSmart($token, $part)) {
                        $partMatched = true;
                        break;
                    }
                }
                if (!$partMatched) {
                    $allMatched = false;
                    break;
                }
            }
            if ($allMatched) {
                return true;
            }
            continue;
        }

        foreach ($tokens as $token) {
            if (tokenMatchesKeywordSmart($token, $k)) {
                return true;
            }
        }
    }
    return false;
}

function countSmartIntentMatches(string $input, array $keywords, array $tokens): int {
    $hits = 0;
    foreach ($keywords as $keyword) {
        if (containsSmartIntent($input, [(string)$keyword], $tokens)) {
            $hits++;
        }
    }
    return $hits;
}

function detectSmartKeywords(string $input, array $lexicon): array {
    $tokens = tokenizeIntentWords($input);
    $hits = [];
    foreach ($lexicon as $group => $words) {
        foreach ($words as $word) {
            if (containsSmartIntent($input, [$word], $tokens)) {
                $hits[] = strtolower((string)$word);
            }
        }
    }
    return array_values(array_unique($hits));
}

/**
 * Rewrite informal user language into a normalized query shape.
 */
function rewriteNaturalQuery(string $input): string {
    $rewritten = strtolower(trim($input));

    $rules = [
        '/\b(can you|could you|would you|kindly|please)\b/i' => ' ',
        '/\b(show me|give me|let me see|i need|i want|i am looking for|i\'m looking for|looking for)\b/i' => ' show ',
        '/\b(who are|who is)\b/i' => ' list ',
        '/\b(what is the number of|how many)\b/i' => ' count ',
        '/\b(senior high school)\b/i' => ' shs ',
        '/\b(junior high school)\b/i' => ' jhs ',
        '/\b(grade school)\b/i' => ' elementary ',
        '/\b(without assigned teachers?|no assigned teachers?)\b/i' => ' without teacher ',
        '/\b(not staffed)\b/i' => ' unstaffed ',
        '/\b(faculty members?|teaching staff)\b/i' => ' teachers ',
        '/\b(staff members?)\b/i' => ' personnel ',
        '/\b(campus|campuses)\b/i' => ' school ',
        '/\b(overview|big picture|overall status|health check|snapshot|dashboard summary)\b/i' => ' system overview ',
        '/\b(staffing gap|staff gaps|teacher shortage|shortage)\b/i' => ' shortage ',
        '/\b(retirements?|retiring soon)\b/i' => ' retirement ',
        '/\b(accounts?)\b/i' => ' users ',
        '/\b(any way|any form|however asked|whatever wording)\b/i' => ' flexible query ',
    ];

    foreach ($rules as $pattern => $replacement) {
        $rewritten = preg_replace($pattern, $replacement, $rewritten);
    }

    // Remove trailing conversational explanations that are not part of filters.
    $rewritten = preg_replace('/\b(?:because|beca?use|beacuse|since|as|kasi)\b.*$/i', '', (string)$rewritten);
    $rewritten = preg_replace('/\b(?:cant|can\'t|cannot|not showing|not working|system cant show|system cannot show)\b.*$/i', '', (string)$rewritten);

    $rewritten = trim((string)preg_replace('/\s+/', ' ', (string)$rewritten));
    return trim($rewritten, " \t\n\r\0\x0B.,:;!?\"'");
}

function humanReply(string $lead, array $details = [], string $next = ''): string {
    $out = trim($lead);
    if ($details) {
        $out .= "\n" . implode("\n", array_map(static fn($d) => '- ' . trim((string)$d), $details));
    }
    if ($next !== '') {
        $out .= "\n\n" . trim($next);
    }
    return $out;
}

function extractBestSearchSeed(string $input): string {
    $seed = extractNamedPhrase($input);
    if ($seed === '') {
        $seed = extractLocationPhrase($input);
    }
    if ($seed !== '') {
        return trim((string)$seed);
    }

    $candidate = preg_replace('/\b(show|find|search|list|get|display|give|download|export|generate|filter|track|open|system|entire|whole|everything|anything|all|data|records|can|you|please|kindly|for|with|where|in|from|at|within|near|the|a|an|me|to|of|because|since|as|kasi|that|this|those|these)\b/i', ' ', $input);
    $candidate = trim((string)preg_replace('/\s+/', ' ', (string)$candidate));
    return trim((string)$candidate, " \t\n\r\0\x0B.,:;!?");
}

function canViewAdminOpsData(): bool {
    return isAdmin();
}

/**
 * Return the canonical key for the first alias group matched in input.
 */
function firstMatchedAliasCanonical(array $aliasMap, string $input, array $tokens): string {
    foreach ($aliasMap as $canonical => $aliases) {
        $needles = is_array($aliases) ? $aliases : [$aliases];
        if (containsSmartIntent($input, $needles, $tokens)) {
            return (string)$canonical;
        }
    }
    return '';
}

/**
 * Extract a natural-language location phrase from free text, e.g. "in maria west".
 */
function extractLocationPhrase(string $input): string {
    $pattern = '/\b(?:in|from|at|within|near|located\s+in)\s+([a-z0-9\- ]{2,100}?)(?=(?:\s+(?:with|without|where|that|which|for|and|or|named|called|show|shows|list|lists|find|finds|get|gets|search|count|total|breakdown|download|export|generate|it|this|these|those|please|thanks|because|beca?use|beacuse|since|kasi)\b|[,.!?]|$))/i';
    if (preg_match($pattern, $input, $m)) {
        return trim((string)($m[1] ?? ''));
    }
    return '';
}

function locationTokens(string $phrase): array {
    $cleaned = strtolower(trim((string)$phrase));
    $cleaned = preg_replace('/[^a-z0-9 ]+/i', ' ', (string)$cleaned);
    $parts = preg_split('/\s+/', (string)$cleaned) ?: [];
    $stop = ['the', 'and', 'for', 'from', 'with', 'without', 'district', 'school', 'schools'];
    $tokens = [];
    foreach ($parts as $part) {
        $token = trim((string)$part);
        if ($token === '' || strlen($token) < 3 || in_array($token, $stop, true)) {
            continue;
        }
        $tokens[$token] = true;
    }
    return array_keys($tokens);
}

function normalizeDistrictPhrase(string $input): string {
    $value = strtolower(trim($input));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\b(the|a|an)\b\s+/i', '', $value);
    $value = preg_replace('/\b(it|this|that|these|those|please|thanks|thank you)\b.*$/i', '', $value);
    $value = preg_replace('/\b(districts?|districs?|distrct)\b/i', 'district', $value);
    $value = trim((string)preg_replace('/\s+/', ' ', (string)$value));
    return trim($value, " \t\n\r\0\x0B.,:;!?\"'");
}

/**
 * Extract a simple name phrase after named/called, if present.
 */
function extractNamedPhrase(string $input): string {
    if (preg_match('/\b(?:named|called)\s+([a-z0-9\- ]{2,100})/i', $input, $m)) {
        return trim((string)($m[1] ?? ''));
    }
    return '';
}

/**
 * Extract trailing phrase after entity words, e.g. "schools maria west".
 */
function extractPhraseAfterEntity(string $input, array $entityWords): string {
    $escaped = array_map(static fn($w) => preg_quote((string)$w, '/'), $entityWords);
    $pattern = '/\b(?:' . implode('|', $escaped) . ')\b\s+([a-z0-9\- ]{2,80}?)(?=(?:\s+(?:with|without|where|that|which|for|and|or|named|called|show|list|find|get|search|count|total|breakdown|download|export|generate)\b|[,.!?]|$))/i';
    if (preg_match($pattern, $input, $m)) {
        return trim((string)($m[1] ?? ''));
    }
    return '';
}

/**
 * Resolve the best school candidate for planning analysis from a natural query.
 */
function resolveSchoolForPlanning(PDO $db, string $analysisText, array $parsedCommand): ?array {
    $candidates = [];

    if (preg_match('/\bschool\s+([a-z0-9\- ]{2,140})/i', $analysisText, $m)) {
        $candidates[] = trim((string)$m[1]);
    }

    $named = extractNamedPhrase($analysisText);
    if ($named !== '') {
        $candidates[] = $named;
    }

    $seed = trim((string)($parsedCommand['seed'] ?? ''));
    if ($seed !== '') {
        $candidates[] = $seed;
    }

    $location = extractLocationPhrase($analysisText);
    if ($location !== '') {
        $candidates[] = $location;
    }

    $lastSchool = trim((string)(($_SESSION['chatbot_last_filters']['q'] ?? '')));
    if ($lastSchool !== '') {
        $candidates[] = $lastSchool;
    }

    $seen = [];
    foreach ($candidates as $cand) {
        $q = strtolower(trim((string)preg_replace('/\s+/', ' ', $cand)));
        if ($q === '' || isset($seen[$q])) {
            continue;
        }
        $seen[$q] = true;

        $like = '%' . $q . '%';
        $st = $db->prepare(
            'SELECT s.*, COALESCE(d.district_name, "") AS district_name
             FROM schools s
             LEFT JOIN districts d ON s.district_id = d.id
             WHERE LOWER(s.school_name) LIKE ?
                OR LOWER(COALESCE(s.school_id_code, "")) LIKE ?
                OR LOWER(COALESCE(s.municipality, "")) LIKE ?
                OR LOWER(COALESCE(d.district_name, "")) LIKE ?
             ORDER BY
                CASE WHEN LOWER(s.school_name) = ? THEN 0 ELSE 1 END,
                CASE WHEN LOWER(s.school_name) LIKE ? THEN 0 ELSE 1 END,
                s.school_name
             LIMIT 1'
        );
        $st->execute([$like, $like, $like, $like, $q, $like]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return null;
}

function extractForecastPercent(string $input): float {
    if (preg_match('/\b(?:increase|growth|grow|rises?|up)\s*(?:by\s*)?(\d{1,3}(?:\.\d+)?)\s*%?/i', $input, $m)) {
        return max(0.1, min(200.0, (float)$m[1]));
    }
    if (preg_match('/\b(\d{1,3}(?:\.\d+)?)\s*%\b/i', $input, $m)) {
        return max(0.1, min(200.0, (float)$m[1]));
    }
    if (preg_match('/\b(\d{1,3}(?:\.\d+)?)\s*(?:percent|pct)\b/i', $input, $m)) {
        return max(0.1, min(200.0, (float)$m[1]));
    }
    return 10.0;
}

/**
 * Detect short filter-only messages like "only female" or "in west" so
 * the chatbot can treat them as follow-ups to previous results.
 */
function isLikelyFilterOnlyFollowup(string $input, array $tokens): bool {
    $hasActionWord = containsSmartIntent($input, ['show', 'find', 'search', 'list', 'get', 'display', 'download', 'export', 'generate', 'count'], $tokens);
    if ($hasActionWord) {
        return false;
    }

    $filterWords = [
        'only', 'just', 'male', 'female', 'position', 'specialization', 'district', 'in', 'from', 'within', 'near',
        'public', 'private', 'als', 'elementary', 'jhs', 'shs', 'untagged', 'unstaffed', 'without teacher',
        'remove', 'exclude', 'without', 'instead', 'change', 'switch',
    ];

    $hasFilterWord = containsSmartIntent($input, $filterWords, $tokens);
    if (!$hasFilterWord) {
        return false;
    }

    $tokenCount = count(tokenizeIntentWords($input));
    return $tokenCount <= 12;
}

function orderedIntentTokens(string $input): array {
    $parts = preg_split('/[^a-z0-9]+/i', strtolower($input)) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        $token = trim((string)$part);
        if ($token === '') continue;
        $tokens[] = $token;
    }
    return $tokens;
}

/**
 * Parse user text word-by-word into an exact command frame.
 */
function parseWordCommand(string $input): array {
    $tokens = orderedIntentTokens($input);
    $actionWords = ['show', 'find', 'search', 'list', 'get', 'display', 'count', 'download', 'export', 'generate'];
    $entityMap = [
        'teacher' => 'teachers', 'teachers' => 'teachers', 'employee' => 'teachers', 'employees' => 'teachers',
        'school' => 'schools', 'schools' => 'schools', 'campus' => 'schools', 'campuses' => 'schools',
        'district' => 'districts', 'districts' => 'districts',
        'user' => 'users', 'users' => 'users', 'account' => 'users', 'accounts' => 'users',
    ];

    $action = '';
    $entity = '';
    $entityExplicit = false;
    $hasUniversalCue = false;
    $wantsAll = false;
    $download = false;
    $format = 'csv';

    foreach ($tokens as $token) {
        if ($action === '' && in_array($token, $actionWords, true)) {
            $action = $token;
        }
        if ($entity === '' && isset($entityMap[$token])) {
            $entity = $entityMap[$token];
            $entityExplicit = true;
        }
        if ($token === 'all' || $token === 'everything') {
            $wantsAll = true;
        }
        if (in_array($token, ['download', 'export', 'generate'], true)) {
            $download = true;
        }
        if (in_array($token, ['excel', 'xlsx'], true)) {
            $format = 'excel';
        }
        if (in_array($token, ['universal', 'system', 'entire', 'global'], true)) {
            $hasUniversalCue = true;
        }
    }

    if ($action === '') $action = 'search';
    if ($entity === '') $entity = 'universal';

    $location = extractLocationPhrase($input);
    $named = extractNamedPhrase($input);
    $seed = '';
    $seedSource = 'none';
    if ($named !== '') {
        $seed = $named;
        $seedSource = 'named';
    } elseif ($location !== '') {
        $seed = $location;
        $seedSource = 'location';
    } else {
        $seed = extractBestSearchSeed($input);
        $seedSource = $seed !== '' ? 'fallback' : 'none';
    }

    if ($hasUniversalCue || ($wantsAll && $entity !== 'schools' && $entity !== 'teachers')) {
        $entity = 'universal';
    }

    $exactCommand = trim($action . ' ' . $entity . ($seed !== '' ? (' in ' . $seed) : ''));

    return [
        'action' => $action,
        'entity' => $entity,
        'entity_explicit' => $entityExplicit,
        'location' => $location,
        'seed' => $seed,
        'seed_source' => $seedSource,
        'wants_all' => $wantsAll,
        'is_download' => $download,
        'format' => $format,
        'is_universal' => $hasUniversalCue,
        'exact_command' => $exactCommand,
    ];
}

$analysisText = rewriteNaturalQuery($normalizedText);
$parsedCommand = parseWordCommand($analysisText);
$intentTokens = tokenizeIntentWords($analysisText);
$trackedKeywords = detectSmartKeywords($analysisText, $commandLexicon);
if (!empty($trackedKeywords)) {
    $_SESSION['chatbot_last_keywords'] = $trackedKeywords;
}
$_SESSION['chatbot_last_exact_command'] = (string)($parsedCommand['exact_command'] ?? '');
$text = $analysisText;

// Remove polite wrappers so intent extraction focuses on command meaning.
$intentText = preg_replace('/\b(can you|could you|would you|please|kindly|i need|i want|help me|show me|give me|let me)\b/i', ' ', $text);
$intentText = trim((string)preg_replace('/\s+/', ' ', (string)$intentText));

if (preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening)\b/i', $normalizedText)) {
    chatbotJson([
        'reply' => "Hi! I can help with TPMS data requests only.\n"
            . "Try: 'show school type counts', 'find master teachers in west district', or 'download csv female teachers'.",
    ]);
}

if (preg_match('/\b(thank you|thanks|tnx)\b/i', $normalizedText)) {
    chatbotJson([
        'reply' => 'You are welcome. Ask for teacher and school data anytime.',
    ]);
}

if (preg_match('/\b(bye|goodbye|see you)\b/i', $normalizedText)) {
    chatbotJson([
        'reply' => 'Goodbye. I am here anytime you need TPMS assistance.',
    ]);
}

if (preg_match('/\b(help|what can you do|commands?)\b/i', $normalizedText)) {
    chatbotJson([
        'reply' => humanReply(
            'I can help you like a TPMS data assistant. Try asking naturally, for example:',
            [
                "Search teachers: 'show female master teachers in west district'",
                "Find an employee: 'find employee 4268476'",
                "Count/list schools: 'show school type counts' or 'list schools in west district'",
                "Get summaries: 'show district teacher totals'",
                "Export data: 'download csv teacher iii' or 'generate excel untagged schools'",
                "Follow-up export: say 'download this' after a result",
            ],
            'You can also ask for a system overview anytime.'
        ),
    ]);
}

$isDownloadCmd = (bool)($parsedCommand['is_download'] ?? false) || containsSmartIntent($analysisText, ['download', 'export', 'generate', 'extract'], $intentTokens);
$format = (string)($parsedCommand['format'] ?? '') === 'excel' ? 'excel' : (preg_match('/\bexcel|xlsx\b/i', $message) ? 'excel' : 'csv');
$isFollowupReference = containsSmartIntent($analysisText, ['this', 'that', 'it', 'them', 'those', 'current', 'result', 'results'], $intentTokens);
$wantsAll = (bool)($parsedCommand['wants_all'] ?? false) || containsSmartIntent($analysisText, ['all', 'everything', 'all data', 'all records', 'show all', 'list all'], $intentTokens);
$wantsSameContext = containsSmartIntent($analysisText, ['same', 'again', 'continue', 'also', 'as before', 'previous'], $intentTokens);

$teacherEntityTerms = [
    'teacher', 'teachers', 'employee', 'employees', 'position', 'specialization', 'male', 'female',
    'instructor', 'instructors', 'faculty', 'staff', 'personnel'
];
$schoolEntityTerms = [
    'school', 'schools', 'school type', 'elementary', 'jhs', 'shs', 'als', 'untagged', 'public',
    'private', 'campus', 'campuses', 'institution', 'institutions'
];
$districtEntityTerms = [
    'district', 'districts', 'zone', 'zones', 'cluster', 'clusters'
];
$settingsEntityTerms = ['settings', 'appearance', 'theme', 'background', 'accent', 'layout', 'glass', 'readability', 'version', 'update', 'updates'];
$reportEntityTerms = ['report', 'reports', 'dashboard', 'analytics', 'statistics', 'stats', 'chart', 'charts', 'graph', 'graphs', 'summary'];

$mentionsTeacherEntity = ((string)($parsedCommand['entity'] ?? '') === 'teachers') || containsSmartIntent($analysisText, $teacherEntityTerms, $intentTokens);
$mentionsSchoolEntity = ((string)($parsedCommand['entity'] ?? '') === 'schools') || containsSmartIntent($analysisText, $schoolEntityTerms, $intentTokens);
$mentionsDistrictEntity = ((string)($parsedCommand['entity'] ?? '') === 'districts') || containsSmartIntent($analysisText, $districtEntityTerms, $intentTokens);
$mentionsSettingsEntity = containsSmartIntent($analysisText, $settingsEntityTerms, $intentTokens);
$mentionsReportEntity = containsSmartIntent($analysisText, $reportEntityTerms, $intentTokens);
$wantsTour = containsSmartIntent($analysisText, ['how to use', 'tour', 'guide me', 'walk me through', 'where do i', 'how can i navigate', 'how to navigate', 'how do i start'], $intentTokens);

$wantsSystemOverview = containsSmartIntent($analysisText, [
    'system overview', 'overview', 'status', 'summary', 'snapshot', 'dashboard summary', 'overall', 'big picture',
    'system health', 'system report', 'retirement', 'users', 'activity'
], $intentTokens)
&& !containsSmartIntent($analysisText, ['download', 'export'], $intentTokens);

$wantsFeatureList = containsSmartIntent($analysisText, ['feature', 'features', 'capability', 'capabilities', 'module', 'modules', 'pages', 'what can you do', 'functions'], $intentTokens);
$wantsSystemWideSearch = containsSmartIntent($analysisText, ['entire system', 'whole system', 'system wide', 'everything', 'all data', 'all records', 'overall'], $intentTokens)
    || (containsSmartIntent($analysisText, ['search', 'find', 'look up'], $intentTokens) && containsSmartIntent($analysisText, ['anything', 'everything', 'all'], $intentTokens));
$wantsAllEntities = containsSmartIntent($analysisText, ['apply to all', 'all entities', 'all modules', 'not only schools', 'not only school', 'not only for schools', 'universal'], $intentTokens);
$wantsUniversalSearch = ((bool)($parsedCommand['is_universal'] ?? false))
    || $wantsSystemWideSearch
    || $wantsAllEntities
    || containsSmartIntent($analysisText, ['universal search', 'global search', 'across all', 'across the system', 'search entire system'], $intentTokens);
$wantsTableInventory = containsSmartIntent($analysisText, ['all tables', 'database tables', 'tables in database', 'table counts', 'list tables', 'show tables'], $intentTokens);
$wantsIndexOptimization = containsSmartIntent($analysisText, ['improve indexing', 'optimize indexing', 'optimize index', 'index optimization', 'database performance', 'tune indexes', 'index tune'], $intentTokens);
$wantsForecast = containsSmartIntent($analysisText, [
    'forecast', 'projection', 'project', 'predict', 'what if', 'if enrollment increases', 'enrollment increase',
    'next year', 'future', 'scenario'
], $intentTokens)
    && containsSmartIntent($analysisText, ['school', 'schools', 'teacher', 'teachers', 'staffing', 'enrollment'], $intentTokens);
$wantsPlanningAnalysis = containsSmartIntent($analysisText, [
    'need more teachers', 'teacher requirement', 'staffing requirement', 'teacher shortage', 'shortage',
    'overloaded', 'overload', 'planning analysis', 'staffing analysis', 'does school need', 'required teachers'
], $intentTokens)
    || (containsSmartIntent($analysisText, ['need', 'requires', 'required', 'requirement'], $intentTokens)
        && containsSmartIntent($analysisText, ['teacher', 'teachers', 'staffing'], $intentTokens)
        && containsSmartIntent($analysisText, ['school', 'schools'], $intentTokens));

$teacherIntentScore = countSmartIntentMatches($analysisText, array_merge($teacherEntityTerms, ['teacher', 'employee', 'personnel', 'faculty']), $intentTokens);
$schoolIntentScore = countSmartIntentMatches($analysisText, array_merge($schoolEntityTerms, ['school', 'campus', 'institution', 'district']), $intentTokens);

if ($mentionsTeacherEntity && $mentionsSchoolEntity) {
    if ($teacherIntentScore > $schoolIntentScore) {
        $mentionsSchoolEntity = false;
    } elseif ($schoolIntentScore > $teacherIntentScore) {
        $mentionsTeacherEntity = false;
    }
}

// Enforce parsed command route as primary execution target.
$parsedEntity = (string)($parsedCommand['entity'] ?? '');
if (!empty($parsedCommand['entity_explicit']) && $parsedEntity === 'schools') {
    $mentionsSchoolEntity = true;
    $mentionsTeacherEntity = false;
    $mentionsDistrictEntity = false;
} elseif (!empty($parsedCommand['entity_explicit']) && $parsedEntity === 'teachers') {
    $mentionsTeacherEntity = true;
    $mentionsSchoolEntity = false;
    $mentionsDistrictEntity = false;
} elseif (!empty($parsedCommand['entity_explicit']) && $parsedEntity === 'districts') {
    $mentionsDistrictEntity = true;
    $mentionsTeacherEntity = false;
    $mentionsSchoolEntity = false;
} elseif ($parsedEntity === 'universal') {
    $wantsUniversalSearch = true;
}

$lastResultType = (string)($_SESSION['chatbot_last_result_type'] ?? '');
$hasExplicitEntityMention = containsSmartIntent($analysisText, [
    'teacher', 'teachers', 'employee', 'employees',
    'school', 'schools', 'campus', 'campuses',
    'district', 'districts',
    'universal', 'global', 'entire system', 'across all'
], $intentTokens) || !empty($parsedCommand['entity_explicit']);

$looksLikeFilterOnlyFollowup = isLikelyFilterOnlyFollowup($analysisText, $intentTokens);
if ($looksLikeFilterOnlyFollowup && $lastResultType !== '' && !$hasExplicitEntityMention) {
    $wantsSameContext = true;
}

if (($wantsSameContext || $isFollowupReference) && !$hasExplicitEntityMention) {
    if ($lastResultType === 'teachers') {
        $mentionsTeacherEntity = true;
        $mentionsSchoolEntity = false;
        $mentionsDistrictEntity = false;
    } elseif ($lastResultType === 'schools') {
        $mentionsSchoolEntity = true;
        $mentionsTeacherEntity = false;
        $mentionsDistrictEntity = false;
    } elseif ($lastResultType === 'districts') {
        $mentionsDistrictEntity = true;
        $mentionsTeacherEntity = false;
        $mentionsSchoolEntity = false;
    } elseif ($lastResultType === 'universal') {
        $wantsUniversalSearch = true;
    } elseif (($lastResultType === 'planning' || $lastResultType === 'forecast')
        && containsSmartIntent($analysisText, ['what if', 'forecast', 'projection', 'predict', 'increase', 'growth', '%', 'percent', 'next year'], $intentTokens)) {
        $wantsForecast = true;
    }
}

$wantsSystemInternals = (bool)preg_match('/\b(file|files|system files?|source code|codebase|config(?:uration)?|env|\.env|php file|database schema|table structure|server path|root path|directory|folders?)\b/i', $analysisText);
if ($wantsSystemInternals) {
    chatbotJson([
        'reply' => 'For security, Tala AI only returns operational data summaries and records. System files, source code, and internal configuration details are not available.',
        'matched_keywords' => $trackedKeywords,
    ], 403);
}

if ($wantsForecast && !$isDownloadCmd) {
    $school = resolveSchoolForPlanning($db, $analysisText, $parsedCommand);
    if (!$school) {
        chatbotJson([
            'reply' => "I can run enrollment-growth forecasting, but I could not identify the target school.\nTry: 'If enrollment increases by 12% in Casiguran National High School, how many teachers are needed?'",
            'matched_keywords' => $trackedKeywords,
        ]);
    }

    $planning = computeSchoolTeacherPlanning($db, (int)($school['id'] ?? 0));
    if (!$planning || empty($planning['summary']) || empty($planning['settings'])) {
        chatbotJson([
            'reply' => 'I could not compute the forecast right now. Please try again.',
            'matched_keywords' => $trackedKeywords,
        ]);
    }

    $s = $planning['summary'];
    $settings = $planning['settings'];
    $growthPct = extractForecastPercent($analysisText);
    $factor = 1 + ($growthPct / 100.0);

    $currentStudents = max(0, (int)($s['total_students'] ?? 0));
    $currentTeachers = max(0, (int)($s['total_teachers'] ?? 0));
    $projectedStudents = (int)ceil($currentStudents * $factor);
    $projectedClasses = (int)ceil(max(0, (int)($s['required_classes'] ?? 0)) * $factor);
    $hoursPerClass = max(0.5, (float)($s['hours_per_class_week'] ?? 5));
    $projectedTeachingHours = $projectedClasses * $hoursPerClass;

    $ratioStd = max(1, (int)($settings['recommended_student_teacher_ratio'] ?? 35));
    $maxLoadStd = max(1.0, (float)($settings['max_teaching_load_hours'] ?? 30));
    $maxClassesStd = max(1, (int)($settings['max_classes_per_teacher'] ?? 6));

    $neededByRatio = (int)ceil($projectedStudents / $ratioStd);
    $neededByLoad = $projectedTeachingHours > 0 ? (int)ceil($projectedTeachingHours / $maxLoadStd) : 0;
    $neededByClasses = $projectedClasses > 0 ? (int)ceil($projectedClasses / $maxClassesStd) : 0;
    $projectedRecommendedTeachers = max($neededByRatio, $neededByLoad, $neededByClasses);
    $additionalNeeded = max(0, $projectedRecommendedTeachers - $currentTeachers);

    $headline = 'Forecast for ' . (string)($school['school_name'] ?? 'the selected school') . ' with +' . number_format($growthPct, 1) . '% enrollment: ';
    if ($additionalNeeded > 0) {
        $headline .= 'you will likely need ' . number_format($additionalNeeded) . ' additional teacher(s).';
    } else {
        $headline .= 'current staffing is likely sufficient under this growth scenario.';
    }

    $_SESSION['chatbot_last_result_type'] = 'forecast';
    $_SESSION['chatbot_last_forecast_pct'] = $growthPct;
    $_SESSION['chatbot_last_filters'] = [
        'q' => (string)($school['school_name'] ?? ''),
        'pos' => '',
        'dist' => (string)($school['district_name'] ?? ''),
        'gen' => '',
        'spec' => '',
        'school' => (int)($school['id'] ?? 0),
    ];

    chatbotJson([
        'reply' => humanReply(
            $headline,
            [
                'Current students: ' . number_format($currentStudents),
                'Projected students: ' . number_format($projectedStudents),
                'Current teachers: ' . number_format($currentTeachers),
                'Projected teacher requirement (max of ratio/load/classes rules): ' . number_format($projectedRecommendedTeachers),
                'Additional teachers needed: ' . number_format($additionalNeeded),
            ],
            'Rule outputs -> Ratio: ' . number_format($neededByRatio)
                . ', Load: ' . number_format($neededByLoad)
                . ', Classes: ' . number_format($neededByClasses)
        ),
        'matched_keywords' => $trackedKeywords,
        'result_type' => 'forecast',
        'results' => [[
            'school_name' => (string)($school['school_name'] ?? ''),
            'district' => (string)($school['district_name'] ?? ''),
            'growth_pct' => $growthPct,
            'projected_students' => $projectedStudents,
            'current_teachers' => $currentTeachers,
            'recommended_teachers' => $projectedRecommendedTeachers,
            'additional_teachers_needed' => $additionalNeeded,
        ]],
        'summary' => [
            'total' => 1,
            'shown' => 1,
            'capped' => false,
        ],
        'forecast' => [
            'school_id' => (int)($school['id'] ?? 0),
            'growth_pct' => $growthPct,
            'projected_students' => $projectedStudents,
            'needed_by_ratio' => $neededByRatio,
            'needed_by_load' => $neededByLoad,
            'needed_by_classes' => $neededByClasses,
            'projected_recommended_teachers' => $projectedRecommendedTeachers,
            'additional_teachers_needed' => $additionalNeeded,
        ],
    ]);
}

if ($wantsPlanningAnalysis && !$isDownloadCmd) {
    $school = resolveSchoolForPlanning($db, $analysisText, $parsedCommand);
    if (!$school) {
        chatbotJson([
            'reply' => "I can run staffing planning analysis, but I could not identify the target school.\nTry: 'Does Casiguran National High School need more teachers?'",
            'matched_keywords' => $trackedKeywords,
        ]);
    }

    $planning = computeSchoolTeacherPlanning($db, (int)($school['id'] ?? 0));
    if (!$planning || empty($planning['summary'])) {
        chatbotJson([
            'reply' => 'I could not compute planning analysis right now. Please try again.',
            'matched_keywords' => $trackedKeywords,
        ]);
    }

    $s = $planning['summary'];
    $shortage = (int)($s['teacher_shortage'] ?? 0);
    $surplus = (int)($s['teacher_surplus'] ?? 0);
    $ratioActual = $s['student_teacher_ratio_actual'] !== null
        ? number_format((float)$s['student_teacher_ratio_actual'], 1)
        : 'n/a';

    $headline = 'Planning analysis for ' . (string)($school['school_name'] ?? 'the selected school') . ':';
    if ($shortage > 0) {
        $headline .= ' this school has a teacher shortage of ' . number_format($shortage) . '.';
    } elseif ($surplus > 0) {
        $headline .= ' this school has a teacher surplus of ' . number_format($surplus) . '.';
    } else {
        $headline .= ' staffing is currently balanced against configured planning rules.';
    }

    $detailLines = [
        'Students: ' . number_format((int)($s['total_students'] ?? 0)),
        'Teachers: ' . number_format((int)($s['total_teachers'] ?? 0)),
        'Current student-teacher ratio: ' . $ratioActual,
        'Recommended teachers (max of ratio/load/classes rules): ' . number_format((int)($s['recommended_teachers'] ?? 0)),
        'Overloaded teachers: ' . number_format((int)($s['overloaded_teachers'] ?? 0)),
        'Underloaded teachers: ' . number_format((int)($s['underloaded_teachers'] ?? 0)),
    ];

    $reasoning = [];
    foreach ((array)($planning['recommendations'] ?? []) as $rec) {
        $rec = trim((string)$rec);
        if ($rec !== '') {
            $reasoning[] = $rec;
        }
    }

    $_SESSION['chatbot_last_result_type'] = 'planning';
    $_SESSION['chatbot_last_filters'] = [
        'q' => (string)($school['school_name'] ?? ''),
        'pos' => '',
        'dist' => (string)($school['district_name'] ?? ''),
        'gen' => '',
        'spec' => '',
        'school' => (int)($school['id'] ?? 0),
    ];

    chatbotJson([
        'reply' => humanReply($headline, $detailLines, 'Triggered planning rules: ' . implode(' | ', $reasoning)),
        'matched_keywords' => $trackedKeywords,
        'result_type' => 'planning',
        'results' => [[
            'school_name' => (string)($school['school_name'] ?? ''),
            'district' => (string)($school['district_name'] ?? ''),
            'teacher_shortage' => $shortage,
            'teacher_surplus' => $surplus,
            'recommended_teachers' => (int)($s['recommended_teachers'] ?? 0),
            'total_teachers' => (int)($s['total_teachers'] ?? 0),
            'total_students' => (int)($s['total_students'] ?? 0),
            'overloaded_teachers' => (int)($s['overloaded_teachers'] ?? 0),
        ]],
        'summary' => [
            'total' => 1,
            'shown' => 1,
            'capped' => false,
        ],
        'planning' => [
            'school_id' => (int)($school['id'] ?? 0),
            'summary' => $s,
            'recommendations' => $reasoning,
        ],
    ]);
}

if (containsSmartIntent($analysisText, ['clear context', 'reset context', 'forget context', 'new search', 'start over', 'clear filters', 'reset filters'], $intentTokens)) {
    unset(
        $_SESSION['chatbot_last_result_type'],
        $_SESSION['chatbot_last_filters'],
        $_SESSION['chatbot_last_query_string'],
        $_SESSION['chatbot_last_school_query'],
        $_SESSION['chatbot_last_school_filters'],
        $_SESSION['chatbot_last_district_seed'],
        $_SESSION['chatbot_last_exact_command']
    );

    chatbotJson([
        'reply' => "Done. I cleared your previous search context and filters.\nYou can start fresh with a new request like 'show schools in maria aurora west'.",
        'matched_keywords' => $trackedKeywords,
    ]);
}

if ($wantsTableInventory) {
    if (!isAdmin()) {
        chatbotJson([
            'reply' => 'For security, full table inventory is restricted to Admin users. I can still provide high-level operational summaries.',
            'matched_keywords' => $trackedKeywords,
        ], 403);
    }

    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $tableSummary = [];
    foreach ($tables as $row) {
        $tableName = (string)($row[0] ?? '');
        if ($tableName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            continue;
        }
        $safeTable = '`' . $tableName . '`';
        $count = (int)$db->query('SELECT COUNT(*) FROM ' . $safeTable)->fetchColumn();
        $tableSummary[] = [
            'table' => $tableName,
            'rows' => $count,
        ];
    }

    usort($tableSummary, static function(array $a, array $b): int {
        return ($b['rows'] <=> $a['rows']) ?: strcmp((string)$a['table'], (string)$b['table']);
    });

    chatbotJson([
        'reply' => humanReply(
            'Database table inventory complete.',
            [
                'Total tables: ' . number_format(count($tableSummary)),
                'Tip: ask "optimize indexing" to run secure index hardening (admin only).',
            ]
        ),
        'result_type' => 'table_inventory',
        'results' => $tableSummary,
        'matched_keywords' => $trackedKeywords,
    ]);
}

if ($wantsIndexOptimization) {
    if (!isAdmin()) {
        chatbotJson([
            'reply' => 'Index optimization is restricted to Admin users for safety and change control.',
            'matched_keywords' => $trackedKeywords,
        ], 403);
    }

    $indexReport = ensureDatabasePerformanceIndexes($db);
    $createdCount = count($indexReport['created']);
    $skippedCount = count($indexReport['skipped']);
    $errorCount = count($indexReport['errors']);

    $details = [
        'Indexes created: ' . number_format($createdCount),
        'Already existed / skipped: ' . number_format($skippedCount),
        'Errors: ' . number_format($errorCount),
    ];

    $payload = [
        'reply' => humanReply('Secure index optimization finished.', $details),
        'result_type' => 'index_optimization',
        'matched_keywords' => $trackedKeywords,
        'summary' => [
            'created' => $createdCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
        ],
        'results' => [
            'created' => $indexReport['created'],
            'skipped' => $indexReport['skipped'],
            'errors' => $indexReport['errors'],
        ],
    ];

    chatbotJson($payload);
}

if ($wantsFeatureList && !$isDownloadCmd) {
    chatbotJson([
        'reply' => "Here is what Tala can do for your users:\n"
            . "1) Search teachers by name, employee number, district, position, gender, and specialization\n"
            . "2) Search schools by name, district, type, and staffing status\n"
            . "3) Give quick totals and breakdown summaries for schools and teachers\n"
            . "4) Export filtered results to CSV/Excel when your role allows it\n"
            . "5) Follow conversational requests like: 'can you show me female teachers in west district' or 'find unstaffed schools in maria west'",
        'matched_keywords' => $trackedKeywords,
        'features' => true,
    ]);
}

if ($wantsSystemOverview) {
    $teacherTotal = (int)$db->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
    $schoolTotal = (int)$db->query('SELECT COUNT(*) FROM schools')->fetchColumn();
    $districtTotal = (int)$db->query('SELECT COUNT(*) FROM districts')->fetchColumn();
    $pwdTotal = (int)$db->query("SELECT COUNT(*) FROM teachers WHERE LOWER(TRIM(COALESCE(pwd_status, ''))) IN ('yes','pwd','1','true')")->fetchColumn();

    $retiringWithin12Months = (int)$db->query(
        "SELECT COUNT(*) FROM teachers
         WHERE retirement_date IS NOT NULL
           AND retirement_date <> '0000-00-00'
           AND retirement_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)"
    )->fetchColumn();

    $details = [
        'Teachers: ' . number_format($teacherTotal),
        'Schools: ' . number_format($schoolTotal),
        'Districts: ' . number_format($districtTotal),
        'PWD-tagged teachers: ' . number_format($pwdTotal),
        'Retiring within 12 months: ' . number_format($retiringWithin12Months),
    ];

    if (canViewAdminOpsData()) {
        $activeUsers = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
        $inactiveUsers = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();
        $logs24h = (int)$db->query('SELECT COUNT(*) FROM activity_logs WHERE created_at >= (NOW() - INTERVAL 1 DAY)')->fetchColumn();
        $details[] = 'Active users: ' . number_format($activeUsers) . ' | Inactive users: ' . number_format($inactiveUsers);
        $details[] = 'Activity logs (24h): ' . number_format($logs24h);
    }

    chatbotJson([
        'reply' => humanReply(
            'Here is your TPMS system overview right now.',
            $details,
            'Ask follow-ups like "show district teacher totals", "list schools without teachers", or "find female master teachers in west district".'
        ),
        'matched_keywords' => $trackedKeywords,
        'result_type' => 'overview',
        'summary' => [
            'teachers_total' => $teacherTotal,
            'schools_total' => $schoolTotal,
            'districts_total' => $districtTotal,
            'pwd_total' => $pwdTotal,
            'retiring_12_months' => $retiringWithin12Months,
        ],
    ]);
}

if ($wantsUniversalSearch && !$isDownloadCmd) {
    $searchSeed = extractBestSearchSeed($analysisText);
    $usedUniversalMemory = false;
    if ($searchSeed === '' && ($wantsSameContext || $isFollowupReference)) {
        $prevFilters = $_SESSION['chatbot_last_filters'] ?? null;
        if (is_array($prevFilters) && trim((string)($prevFilters['q'] ?? '')) !== '') {
            $searchSeed = trim((string)$prevFilters['q']);
            $usedUniversalMemory = true;
        }
    }

    if ($searchSeed === '') {
        chatbotJson([
            'reply' => 'I can search across the entire TPMS dataset. Tell me a name, employee number, school, district, or location phrase.',
            'matched_keywords' => $trackedKeywords,
        ]);
    }

    $searchLike = '%' . $searchSeed . '%';

    $teacherRowsStmt = $db->prepare(
        'SELECT t.id, t.employee_number, t.first_name, t.last_name, t.position, t.gender,
                COALESCE(s.school_name, t.school_name_raw) AS school_name,
                COALESCE(d.district_name, t.district_raw) AS district
         FROM teachers t
         LEFT JOIN schools s ON t.school_id = s.id
         LEFT JOIN districts d ON s.district_id = d.id
         WHERE t.employee_number LIKE ?
            OR t.first_name LIKE ?
            OR t.last_name LIKE ?
            OR t.position LIKE ?
            OR t.specialization LIKE ?
            OR COALESCE(s.school_name, t.school_name_raw) LIKE ?
            OR COALESCE(d.district_name, t.district_raw) LIKE ?
         ORDER BY
            CASE WHEN LOWER(t.last_name) = LOWER(?) OR LOWER(t.first_name) = LOWER(?) THEN 0 ELSE 1 END,
            t.last_name, t.first_name
         LIMIT 20'
    );
    $teacherRowsStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchSeed, $searchSeed]);
    $teacherRows = $teacherRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $teacherCountStmt = $db->prepare(
        'SELECT COUNT(*)
         FROM teachers t
         LEFT JOIN schools s ON t.school_id = s.id
         LEFT JOIN districts d ON s.district_id = d.id
         WHERE t.employee_number LIKE ?
            OR t.first_name LIKE ?
            OR t.last_name LIKE ?
            OR t.position LIKE ?
            OR t.specialization LIKE ?
            OR COALESCE(s.school_name, t.school_name_raw) LIKE ?
            OR COALESCE(d.district_name, t.district_raw) LIKE ?'
    );
    $teacherCountStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike]);
    $globalTeacherCount = (int)$teacherCountStmt->fetchColumn();

     $schoolRowsStmt = $db->prepare(
          'SELECT s.id, s.school_name, s.school_type, s.learner_count,
                     COALESCE(d.district_name, "") AS district,
                     (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count
            FROM schools s
            LEFT JOIN districts d ON s.district_id = d.id
            WHERE s.school_name LIKE ?
                OR s.school_id_code LIKE ?
                OR s.municipality LIKE ?
                OR d.district_name LIKE ?
            ORDER BY
                CASE WHEN LOWER(s.school_name) = LOWER(?) THEN 0 ELSE 1 END,
                s.school_name ASC
            LIMIT 20'
     );
     $schoolRowsStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike, $searchSeed]);
     $schoolRows = $schoolRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $schoolCountStmt = $db->prepare(
        'SELECT COUNT(*)
         FROM schools s
         LEFT JOIN districts d ON s.district_id = d.id
         WHERE s.school_name LIKE ?
            OR s.school_id_code LIKE ?
            OR s.municipality LIKE ?
            OR d.district_name LIKE ?'
    );
    $schoolCountStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike]);
    $globalSchoolCount = (int)$schoolCountStmt->fetchColumn();

    $districtRowsStmt = $db->prepare(
        'SELECT d.id, d.district_name,
                (SELECT COUNT(*) FROM schools s WHERE s.district_id = d.id) AS school_count,
                (SELECT COUNT(*)
                 FROM teachers t
                 LEFT JOIN schools s2 ON t.school_id = s2.id
                 WHERE s2.district_id = d.id
                    OR LOWER(COALESCE(t.district_raw, "")) LIKE LOWER(?)) AS teacher_count
         FROM districts d
         WHERE d.district_name LIKE ?
         ORDER BY d.district_name
         LIMIT 15'
    );
    $districtRowsStmt->execute([$searchLike, $searchLike]);
    $districtRows = $districtRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $districtCountStmt = $db->prepare('SELECT COUNT(*) FROM districts WHERE district_name LIKE ?');
    $districtCountStmt->execute([$searchLike]);
    $globalDistrictCount = (int)$districtCountStmt->fetchColumn();

    $userRows = [];
    $globalUserCount = 0;
    if (isAdmin()) {
        $userRowsStmt = $db->prepare(
            'SELECT id, username, full_name, role, is_active
             FROM users
             WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? OR role LIKE ?
             ORDER BY full_name
             LIMIT 15'
        );
        $userRowsStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike]);
        $userRows = $userRowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $userCountStmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? OR role LIKE ?');
        $userCountStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike]);
        $globalUserCount = (int)$userCountStmt->fetchColumn();
    }

    $totalHits = $globalTeacherCount + $globalSchoolCount + $globalDistrictCount + $globalUserCount;

    if ($totalHits <= 0) {
        chatbotJson([
            'reply' => 'I searched the entire system but found no matches for "' . $searchSeed . '". Try a broader keyword or nearby district name.',
            'matched_keywords' => $trackedKeywords,
        ]);
    }

    $_SESSION['chatbot_last_result_type'] = 'universal';
    $_SESSION['chatbot_last_filters'] = ['q' => $searchSeed, 'pos' => '', 'dist' => '', 'gen' => '', 'spec' => '', 'school' => 0];
    $_SESSION['chatbot_last_query_string'] = http_build_query(['q' => $searchSeed]);
    $_SESSION['chatbot_last_school_query'] = http_build_query(['q' => $searchSeed]);

    $universalResults = [
        'teachers' => $teacherRows,
        'schools' => $schoolRows,
        'districts' => $districtRows,
    ];
    if (isAdmin()) {
        $universalResults['users'] = $userRows;
    }

    chatbotJson([
        'reply' => humanReply(
            'Universal search complete for "' . $searchSeed . '".' . ($usedUniversalMemory ? ' I used your previous search context for this follow-up.' : ''),
            [
                'Teachers matched: ' . number_format($globalTeacherCount),
                'Schools matched: ' . number_format($globalSchoolCount),
                'Districts matched: ' . number_format($globalDistrictCount),
                isAdmin() ? ('Users matched: ' . number_format($globalUserCount)) : 'Users matched: admin-only',
            ],
            'You can refine with commands like: "show teachers in ' . $searchSeed . '" or "list schools in ' . $searchSeed . '".'
        ),
        'results' => $universalResults,
        'matched_keywords' => $trackedKeywords,
        'result_type' => 'universal',
        'summary' => [
            'teachers_total' => $globalTeacherCount,
            'schools_total' => $globalSchoolCount,
            'districts_total' => $globalDistrictCount,
            'users_total' => $globalUserCount,
            'shown_teachers' => count($teacherRows),
            'shown_schools' => count($schoolRows),
            'shown_districts' => count($districtRows),
            'shown_users' => isAdmin() ? count($userRows) : 0,
        ],
    ]);
}

if ($mentionsDistrictEntity && !$mentionsTeacherEntity && !$mentionsSchoolEntity && !$isDownloadCmd) {
    $districtSeed = trim((string)($parsedCommand['location'] ?? ''));
    $districtUsedMemory = false;
    if ($districtSeed === '') {
        $districtSeed = normalizeDistrictPhrase(extractLocationPhrase($analysisText));
    }
    if ($districtSeed === '') {
        $districtSeed = trim((string)($parsedCommand['seed'] ?? ''));
    }
    if ($districtSeed === '' && ($wantsSameContext || $isFollowupReference)) {
        $prevDistrict = trim((string)($_SESSION['chatbot_last_district_seed'] ?? ''));
        if ($prevDistrict !== '') {
            $districtSeed = $prevDistrict;
            $districtUsedMemory = true;
        }
    }

    $districtConditions = [];
    $districtParams = [];
    if ($districtSeed !== '') {
        $districtConditions[] = '(d.district_name LIKE ? OR REPLACE(LOWER(COALESCE(d.district_name, "")), " district", "") LIKE ?)';
        $districtParams[] = '%' . $districtSeed . '%';
        $districtParams[] = '%' . trim((string)preg_replace('/\bdistrict\b/i', '', strtolower($districtSeed))) . '%';

        $distTokens = locationTokens($districtSeed);
        foreach ($distTokens as $token) {
            $districtConditions[] = 'LOWER(d.district_name) LIKE ?';
            $districtParams[] = '%' . $token . '%';
        }
    }

    $districtWhere = $districtConditions ? (' WHERE ' . implode(' AND ', $districtConditions)) : '';

    $districtCountSql = 'SELECT COUNT(*) FROM districts d' . $districtWhere;
    $districtCountStmt = $db->prepare($districtCountSql);
    $districtCountStmt->execute($districtParams);
    $districtTotalMatches = (int)$districtCountStmt->fetchColumn();

    $districtMaxVisible = $wantsAll ? 100 : 25;
    $districtSql =
        'SELECT d.id, d.district_name,
                (SELECT COUNT(*) FROM schools s WHERE s.district_id = d.id) AS school_count,
                (SELECT COUNT(*)
                 FROM teachers t
                 LEFT JOIN schools s2 ON t.school_id = s2.id
                 WHERE s2.district_id = d.id
                    OR LOWER(COALESCE(t.district_raw, "")) LIKE LOWER(?)) AS teacher_count
         FROM districts d'
        . $districtWhere
        . ' ORDER BY d.district_name LIMIT ?';

    $districtStmt = $db->prepare($districtSql);
    $districtStmt->execute(array_merge(['%' . ($districtSeed !== '' ? $districtSeed : '') . '%'], $districtParams, [$districtMaxVisible]));
    $districtRows = $districtStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$districtRows) {
        chatbotJson([
            'reply' => 'No district records matched your request. Try a broader district keyword.',
            'results' => [],
            'matched_keywords' => $trackedKeywords,
            'result_type' => 'districts',
        ]);
    }

    $_SESSION['chatbot_last_result_type'] = 'districts';
    $_SESSION['chatbot_last_district_seed'] = $districtSeed;
    $_SESSION['chatbot_last_filters'] = [
        'q' => $districtSeed,
        'pos' => '',
        'dist' => $districtSeed,
        'gen' => '',
        'spec' => '',
        'school' => 0,
    ];

    chatbotJson([
        'reply' => 'Found ' . number_format($districtTotalMatches) . ' matching district(s). Showing ' . number_format(count($districtRows)) . '.' . ($districtUsedMemory ? ' I used your previous district context for this follow-up.' : ''),
        'results' => $districtRows,
        'matched_keywords' => $trackedKeywords,
        'result_type' => 'districts',
        'summary' => [
            'total' => $districtTotalMatches,
            'shown' => count($districtRows),
            'capped' => $districtTotalMatches > count($districtRows),
        ],
    ]);
}

if ($isDownloadCmd && $isFollowupReference && !$mentionsTeacherEntity && !$mentionsSchoolEntity && $lastResultType === 'schools') {
    $mentionsSchoolEntity = true;
}

if ($wantsTour) {
    $tourReply = "TPMS quick guide (data-focused):\n"
        . "1) Ask for school counts by type or district.\n"
        . "2) Ask for teacher lists by filters like district, position, and gender.\n"
        . "3) Ask for summaries such as totals and breakdowns.\n"
        . "4) Ask for CSV/Excel data exports when your role allows it.";

    if ($mentionsTeacherEntity) {
        $tourReply = "Teacher data guide:\n"
            . "1) Ask filters like district, position, gender, and specialization.\n"
            . "2) Ask for totals or matching records.\n"
            . "3) Ask for export when needed.";
    } elseif ($mentionsSchoolEntity) {
        $tourReply = "School data guide:\n"
            . "1) Ask by type, district, or staffing status.\n"
            . "2) Ask for counts, breakdowns, or matching schools.\n"
            . "3) Ask for data export when needed.";
    } elseif ($mentionsReportEntity) {
        $tourReply = "Reporting guide:\n"
            . "1) Ask for data summaries by filters.\n"
            . "2) Ask for export-ready teacher or school datasets.\n"
            . "3) Review totals, breakdowns, and shortages from returned data.";
    } elseif ($mentionsSettingsEntity) {
        $tourReply = "Tala AI is currently restricted to data-only requests for security.\n"
            . "Please ask about teachers, schools, counts, summaries, and exports.";
    }

    chatbotJson([
        'reply' => $tourReply,
        'matched_keywords' => $trackedKeywords,
        'tour' => true,
    ]);
}

if (preg_match('/\b(open|go to|navigate to|take me to)\b/i', $analysisText)) {
    chatbotJson([
        'reply' => humanReply(
            'I cannot navigate pages directly in data-only mode for security.',
            ['I can still fetch counts, summaries, filtered lists, and exports for you.'],
            'Try: "system overview", "show schools in maria west", or "download csv female teachers".'
        ),
        'matched_keywords' => $trackedKeywords,
    ]);
}

if ($mentionsReportEntity && !$mentionsTeacherEntity && !$mentionsSchoolEntity) {
    chatbotJson([
        'reply' => "Report data options:\n"
            . "1) Ask for teacher totals by district, position, or gender\n"
            . "2) Ask for school totals by type or staffing status\n"
            . "3) Ask for CSV or Excel exports of filtered records",
        'matched_keywords' => $trackedKeywords,
    ]);
}

if ($mentionsSettingsEntity) {
    $reply = "Tala AI is in data-only mode for security.\n"
        . "Please ask about teacher data, school data, counts, summaries, and exports.";

    chatbotJson([
        'reply' => $reply,
        'matched_keywords' => $trackedKeywords,
    ]);
}

if ($mentionsSchoolEntity && !$mentionsTeacherEntity) {
    $schoolFilters = [
        'q' => '',
        'dist' => '',
        'type' => 'all',
        'staffing' => 'all',
    ];
    $schoolUsedMemory = false;

    $schoolTypeAliasMap = [
        'public' => ['public', 'government school', 'state school'],
        'private' => ['private', 'independent school'],
        'als' => ['als', 'alternative learning system'],
        'elementary' => ['elementary', 'elem', 'grade school', 'es'],
        'jhs' => ['jhs', 'junior high', 'junior high school', 'jhs/shs'],
        'shs' => ['shs', 'senior high', 'senior high school', 'pure shs'],
        'untagged' => ['untagged', 'unclassified', 'not tagged', 'no type'],
    ];

    if (preg_match('/\bdistrict\s+([a-z0-9\- ]{2,60})/i', $analysisText, $m)) {
        $schoolFilters['dist'] = normalizeDistrictPhrase(trim((string)$m[1]));
    }

    if ($schoolFilters['dist'] === '') {
        $schoolFilters['dist'] = normalizeDistrictPhrase((string)($parsedCommand['location'] ?? ''));
    }

    if ($schoolFilters['dist'] === '') {
        $schoolFilters['dist'] = normalizeDistrictPhrase(extractLocationPhrase($analysisText));
    }
    if ($schoolFilters['dist'] === '') {
        $schoolFilters['dist'] = normalizeDistrictPhrase(extractPhraseAfterEntity($analysisText, ['school', 'schools', 'campus', 'campuses']));
    }

    $matchedSchoolType = firstMatchedAliasCanonical($schoolTypeAliasMap, $analysisText, $intentTokens);
    if ($matchedSchoolType !== '') {
        $schoolFilters['type'] = $matchedSchoolType;
    }

    if (containsSmartIntent($normalizedText, ['no teacher', 'without teacher', 'unstaffed', 'not staffed', 'no teachers', 'vacant'], $intentTokens)) {
        $schoolFilters['staffing'] = 'no_teacher';
    }

    $namedSchool = extractNamedPhrase($analysisText);
    if ($namedSchool !== '') {
        $schoolFilters['q'] = $namedSchool;
    } elseif ($schoolFilters['q'] === '' && (string)($parsedCommand['seed_source'] ?? '') !== 'location') {
        $schoolFilters['q'] = trim((string)($parsedCommand['seed'] ?? ''));
    }

    $schoolWantsList = in_array((string)($parsedCommand['action'] ?? ''), ['show', 'list', 'find', 'search', 'display', 'get'], true)
        || (bool)preg_match('/\b(show|list|find|search|display|get|open)\b/i', $normalizedText);
    $schoolWantsCounts = (bool)preg_match('/\b(count|counts|how many|summary|total|totals|breakdown)\b/i', $normalizedText);

    if ($wantsSameContext || $isFollowupReference) {
        $prevSchoolFilters = $_SESSION['chatbot_last_school_filters'] ?? null;
        if (is_array($prevSchoolFilters)) {
            foreach (['q', 'dist', 'type', 'staffing'] as $k) {
                $isMissing = ($k === 'type' || $k === 'staffing')
                    ? (($schoolFilters[$k] ?? 'all') === 'all')
                    : (trim((string)($schoolFilters[$k] ?? '')) === '');
                if ($isMissing && isset($prevSchoolFilters[$k])) {
                    $schoolFilters[$k] = $prevSchoolFilters[$k];
                    $schoolUsedMemory = true;
                }
            }
        }
    }

    $schoolQueryString = http_build_query(array_filter([
        'q' => $schoolFilters['q'] !== '' ? $schoolFilters['q'] : null,
        'dist' => $schoolFilters['dist'] !== '' ? $schoolFilters['dist'] : null,
        'type' => $schoolFilters['type'] !== 'all' ? $schoolFilters['type'] : null,
        'staffing' => $schoolFilters['staffing'] !== 'all' ? $schoolFilters['staffing'] : null,
    ], static fn($v) => $v !== null && $v !== ''));

    if ($schoolQueryString !== '') {
        $_SESSION['chatbot_last_school_query'] = $schoolQueryString;
    }
    $_SESSION['chatbot_last_school_filters'] = $schoolFilters;

    if ($isDownloadCmd && $schoolQueryString === '' && $isFollowupReference) {
        $schoolQueryString = (string)($_SESSION['chatbot_last_school_query'] ?? '');
    }

    if ($isDownloadCmd) {
        if (!canEdit()) {
            chatbotJson([
                'reply' => 'Download/generate commands are restricted to Admin/HR accounts for data security.',
            ], 403);
        }

        $schoolExportUrl = APP_URL . '/actions/export_schools.php?format=' . $format;
        $schoolReply = 'Prepared your ' . strtoupper($format) . ' school export. Click download below.';
        if ($schoolQueryString !== '') {
            $schoolExportUrl .= '&' . $schoolQueryString;
        } else {
            $schoolReply = 'No school filter found, so I prepared a full ' . strtoupper($format) . ' export for all schools.';
        }

        $_SESSION['chatbot_last_result_type'] = 'schools';
        $_SESSION['chatbot_last_school_query'] = $schoolQueryString;
        chatbotJson([
            'reply' => $schoolReply,
            'download' => [
                'url' => $schoolExportUrl,
                'label' => 'Download School ' . strtoupper($format),
            ],
            'matched_keywords' => $trackedKeywords,
            'result_type' => 'schools',
        ]);
    }

    $schoolStats = $db->query(
        'SELECT
            COUNT(*) AS total_schools,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(school_type, "")) IN ("elementary", "es") THEN 1 ELSE 0 END), 0) AS elementary_count,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(school_type, "")) IN ("jhs", "jhs/shs") THEN 1 ELSE 0 END), 0) AS jhs_count,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(school_type, "")) IN ("shs", "jhs/shs") THEN 1 ELSE 0 END), 0) AS shs_count,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(school_type, "")) = "als" THEN 1 ELSE 0 END), 0) AS als_count,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(school_type, "")) = "" THEN 1 ELSE 0 END), 0) AS untagged_count
         FROM schools'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($schoolWantsCounts && $schoolFilters['q'] === '' && $schoolFilters['dist'] === '' && $schoolFilters['type'] === 'all' && $schoolFilters['staffing'] === 'all') {
        $_SESSION['chatbot_last_result_type'] = 'schools';
        $_SESSION['chatbot_last_school_query'] = '';
        chatbotJson([
            'reply' => 'School count summary: '
                . number_format((int)($schoolStats['total_schools'] ?? 0)) . ' total schools. '
                . 'Elementary: ' . number_format((int)($schoolStats['elementary_count'] ?? 0)) . ', '
                . 'JHS: ' . number_format((int)($schoolStats['jhs_count'] ?? 0)) . ', '
                . 'SHS: ' . number_format((int)($schoolStats['shs_count'] ?? 0)) . ', '
                . 'ALS: ' . number_format((int)($schoolStats['als_count'] ?? 0)) . ', '
                . 'Untagged: ' . number_format((int)($schoolStats['untagged_count'] ?? 0)) . '.',
            'matched_keywords' => $trackedKeywords,
            'links' => [
                ['url' => APP_URL . '/schools.php', 'label' => 'Open Schools'],
            ],
        ]);
    }

    $schoolConditions = [];
    $schoolParams = [];
    if ($schoolFilters['q'] !== '') {
        $schoolConditions[] = '(s.school_name LIKE ? OR s.school_id_code LIKE ? OR s.municipality LIKE ?)';
        $like = '%' . $schoolFilters['q'] . '%';
        array_push($schoolParams, $like, $like, $like);
    }
    if ($schoolFilters['dist'] !== '') {
        $schoolConditions[] = '(
            d.district_name LIKE ?
            OR REPLACE(LOWER(COALESCE(d.district_name, "")), " district", "") LIKE ?
            OR s.municipality LIKE ?
            OR s.school_name LIKE ?
        )';
        $likeDist = '%' . $schoolFilters['dist'] . '%';
        $distNoSuffix = '%' . trim((string)preg_replace('/\bdistrict\b/i', '', strtolower($schoolFilters['dist']))) . '%';
        array_push($schoolParams, $likeDist, $distNoSuffix, $likeDist, $likeDist);

        // Conversational location matching: each token must appear in district/municipality/school text.
        $distTokens = locationTokens($schoolFilters['dist']);
        foreach ($distTokens as $token) {
            $schoolConditions[] = 'LOWER(CONCAT_WS(" ", COALESCE(d.district_name, ""), COALESCE(s.municipality, ""), COALESCE(s.school_name, ""))) LIKE ?';
            $schoolParams[] = '%' . $token . '%';
        }
    }
    if ($schoolFilters['type'] === 'untagged') {
        $schoolConditions[] = '(s.school_type IS NULL OR TRIM(s.school_type) = "")';
    } elseif ($schoolFilters['type'] === 'jhs') {
        $schoolConditions[] = 'LOWER(COALESCE(s.school_type, "")) IN ("jhs", "jhs/shs")';
    } elseif ($schoolFilters['type'] === 'shs') {
        $schoolConditions[] = 'LOWER(COALESCE(s.school_type, "")) IN ("shs", "jhs/shs")';
    } elseif ($schoolFilters['type'] !== 'all') {
        $schoolConditions[] = 'LOWER(COALESCE(s.school_type, "")) = ?';
        $schoolParams[] = $schoolFilters['type'];
    }
    if ($schoolFilters['staffing'] === 'no_teacher') {
        $schoolConditions[] = 'NOT EXISTS (SELECT 1 FROM teachers t0 WHERE t0.school_id = s.id)';
    }

    $schoolWhere = $schoolConditions ? (' WHERE ' . implode(' AND ', $schoolConditions)) : '';
    $schoolFromSql = ' FROM schools s
        LEFT JOIN districts d ON s.district_id = d.id
        LEFT JOIN teachers sh ON s.school_head_teacher_id = sh.id' . $schoolWhere;

    $schoolCountStmt = $db->prepare('SELECT COUNT(*)' . $schoolFromSql);
    $schoolCountStmt->execute($schoolParams);
    $schoolTotalMatches = (int)$schoolCountStmt->fetchColumn();

    $schoolMaxVisible = $wantsAll ? (canEdit() ? 200 : 100) : 25;
    $schoolListStmt = $db->prepare(
        'SELECT s.id, s.school_name, s.school_type, s.learner_count,
                COALESCE(d.district_name, "") AS district,
                CONCAT_WS(" ", sh.first_name, sh.last_name) AS school_head_name,
                (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count'
        . $schoolFromSql . '
        ORDER BY s.school_name
        LIMIT ?'
    );
    $schoolListStmt->execute(array_merge($schoolParams, [$schoolMaxVisible]));
    $schoolRows = $schoolListStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($schoolRows as &$schoolRow) {
        $schoolRow['view_url'] = APP_URL . '/schools.php?' . http_build_query(['q' => $schoolRow['school_name']]);
    }
    unset($schoolRow);

    if (!$schoolRows && ($schoolWantsList || $schoolFilters['q'] !== '' || $schoolFilters['dist'] !== '' || $schoolFilters['type'] !== 'all' || $schoolFilters['staffing'] !== 'all')) {
        chatbotJson([
            'reply' => 'No school records matched your request. Try a broader district, remove a type filter, or ask for school counts.',
            'results' => [],
            'matched_keywords' => $trackedKeywords,
            'result_type' => 'schools',
            'links' => [
                ['url' => APP_URL . '/schools.php', 'label' => 'Open Schools'],
            ],
        ]);
    }

    if ($schoolWantsList || $schoolFilters['q'] !== '' || $schoolFilters['dist'] !== '' || $schoolFilters['type'] !== 'all' || $schoolFilters['staffing'] !== 'all') {
        $schoolReply = 'Found ' . number_format($schoolTotalMatches) . ' matching school(s). Showing ' . number_format(count($schoolRows)) . '.';
        if ($schoolFilters['staffing'] === 'no_teacher') {
            $schoolReply .= "\nFilter applied: schools without assigned teachers.";
        }
        if ($schoolUsedMemory) {
            $schoolReply .= "\nI used your previous school filters and applied your follow-up.";
        }
        if ($schoolTotalMatches > count($schoolRows)) {
            $schoolReply .= "\nFor performance, chat display is capped at " . number_format($schoolMaxVisible) . ".";
        }

        $schoolDownload = null;
        if (canEdit()) {
            $schoolDownloadUrl = APP_URL . '/actions/export_schools.php?format=csv';
            if ($schoolQueryString !== '') {
                $schoolDownloadUrl .= '&' . $schoolQueryString;
            }
            $schoolDownload = [
                'url' => $schoolDownloadUrl,
                'label' => 'Download School CSV',
            ];
            $schoolReply .= "\nTip: say 'download excel schools' to get an Excel file.";
        }

        $_SESSION['chatbot_last_result_type'] = 'schools';
        $_SESSION['chatbot_last_school_query'] = $schoolQueryString;

        chatbotJson([
            'reply' => $schoolReply,
            'results' => $schoolRows,
            'download' => $schoolDownload,
            'matched_keywords' => $trackedKeywords,
            'result_type' => 'schools',
            'summary' => [
                'total' => $schoolTotalMatches,
                'shown' => count($schoolRows),
                'capped' => $schoolTotalMatches > count($schoolRows),
            ],
            'links' => [
                ['url' => APP_URL . '/schools.php', 'label' => 'Open Schools'],
            ],
        ]);
    }

    $_SESSION['chatbot_last_result_type'] = 'schools';
    $_SESSION['chatbot_last_school_query'] = '';

    chatbotJson([
        'reply' => 'School summary: '
            . number_format((int)($schoolStats['total_schools'] ?? 0)) . ' total schools, '
            . number_format((int)($schoolStats['elementary_count'] ?? 0)) . ' Elementary, '
            . number_format((int)($schoolStats['jhs_count'] ?? 0)) . ' JHS, '
            . number_format((int)($schoolStats['shs_count'] ?? 0)) . ' SHS, '
            . number_format((int)($schoolStats['als_count'] ?? 0)) . ' ALS, and '
            . number_format((int)($schoolStats['untagged_count'] ?? 0)) . ' Untagged.',
        'matched_keywords' => $trackedKeywords,
        'result_type' => 'schools',
        'links' => [
            ['url' => APP_URL . '/schools.php', 'label' => 'Open Schools'],
        ],
    ]);
}

$filters = [
    'q' => '',
    'pos' => '',
    'dist' => '',
    'gen' => '',
    'spec' => '',
    'school' => 0,
];

$freeTextQuery = '';

if (preg_match('/\b(male|female)\b/i', $analysisText, $m)) {
    $filters['gen'] = ucfirst(strtolower($m[1]));
}

$positionAliasMap = [
    'master teacher' => ['master teacher', 'mt', 'master'],
    'teacher iii' => ['teacher iii', 'teacher 3', 't3', 't iii'],
    'teacher ii' => ['teacher ii', 'teacher 2', 't2', 't ii'],
    'teacher i' => ['teacher i', 'teacher 1', 't1', 't i'],
    'principal' => ['principal', 'school principal', 'principal i', 'principal ii'],
    'head teacher' => ['head teacher', 'ht', 'department head', 'school head'],
    'special education teacher' => ['sped', 'special education teacher', 'special education'],
    'guidance counselor' => ['guidance', 'guidance counselor', 'counselor'],
    'school librarian' => ['librarian', 'school librarian', 'library teacher'],
    'als' => ['als', 'mobile teacher', 'alternative learning system teacher'],
];
$matchedPosition = firstMatchedAliasCanonical($positionAliasMap, $intentText !== '' ? $intentText : $text, $intentTokens);
if ($matchedPosition !== '') {
    $filters['pos'] = $matchedPosition;
}
if ($filters['pos'] === '' && preg_match('/\bposition\s+(?:is\s+)?([a-z0-9\- ]{2,60})/i', $analysisText, $m)) {
    $filters['pos'] = trim($m[1]);
}

if (preg_match('/\bdistrict\s+([a-z0-9\- ]{2,60})/i', $analysisText, $m)) {
    $filters['dist'] = normalizeDistrictPhrase(trim((string)$m[1]));
}
if ($filters['dist'] === '') {
    $filters['dist'] = normalizeDistrictPhrase((string)($parsedCommand['location'] ?? ''));
}
if ($filters['dist'] === '') {
    $filters['dist'] = normalizeDistrictPhrase(extractLocationPhrase($analysisText));
}
if (preg_match('/\bspecialization\s+([a-z0-9\- ]{2,60})/i', $analysisText, $m)) {
    $filters['spec'] = trim($m[1]);
}

if (preg_match('/\b(employee|emp)\s*(number|no\.?|#)?\s*([0-9]{3,20})\b/i', $message, $m)) {
    $filters['q'] = trim($m[3]);
}
if ($filters['q'] === '') {
    $namedTeacher = extractNamedPhrase($analysisText);
    if ($namedTeacher !== '') {
        $filters['q'] = $namedTeacher;
    } elseif ((string)($parsedCommand['seed_source'] ?? '') !== 'location') {
        $filters['q'] = trim((string)($parsedCommand['seed'] ?? ''));
    }
}
if ($filters['q'] === '') {
    $afterTeacherEntity = extractPhraseAfterEntity($analysisText, ['teacher', 'teachers', 'employee', 'employees', 'instructor', 'instructors']);
    if ($afterTeacherEntity !== '') {
        $afterTeacherEntity = trim((string)preg_replace('/\b(in|from|at|within|near|district|position|specialization|male|female)\b/i', ' ', $afterTeacherEntity));
        $afterTeacherEntity = trim((string)preg_replace('/\s+/', ' ', $afterTeacherEntity));
        if ($afterTeacherEntity !== '' && strlen($afterTeacherEntity) >= 2) {
            $filters['q'] = $afterTeacherEntity;
        }
    }
}

// If user mentions a known school by name fragment, map to ID.
if (preg_match('/\bschool\s+([a-z0-9\- ]{2,100})/i', $analysisText, $m)) {
    $schoolHint = trim($m[1]);
    $stSchool = $db->prepare('SELECT id FROM schools WHERE LOWER(school_name) LIKE ? ORDER BY school_name LIMIT 1');
    $stSchool->execute(['%' . strtolower($schoolHint) . '%']);
    $sid = (int)$stSchool->fetchColumn();
    if ($sid > 0) {
        $filters['school'] = $sid;
    }
}

if ($filters['q'] === '' && !$isDownloadCmd) {
    $candidate = extractBestSearchSeed($intentText !== '' ? strtolower($intentText) : strtolower($analysisText));

    // Only apply broad free-text search if no structured filters were extracted.
    $hasStructuredFilter =
        $filters['pos'] !== ''
        || $filters['dist'] !== ''
        || $filters['spec'] !== ''
        || $filters['gen'] !== ''
        || $filters['school'] > 0;

    if (!$hasStructuredFilter && $candidate !== '' && strlen($candidate) >= 2) {
        $freeTextQuery = $candidate;
    }
}

// Conversational carry-over: re-use previous filters if user asks for same/again context.
$teacherUsedMemory = false;
if ($wantsSameContext || $isFollowupReference) {
    $prevFilters = $_SESSION['chatbot_last_filters'] ?? null;
    if (is_array($prevFilters)) {
        foreach (['q','pos','dist','gen','spec','school'] as $fk) {
            $isMissing = ($fk === 'school') ? ((int)($filters[$fk] ?? 0) <= 0) : (trim((string)($filters[$fk] ?? '')) === '');
            if ($isMissing && isset($prevFilters[$fk])) {
                $filters[$fk] = $prevFilters[$fk];
                $teacherUsedMemory = true;
            }
        }
    }
}

$queryString = http_build_query(array_filter([
    'q' => $filters['q'] !== '' ? $filters['q'] : $freeTextQuery,
    'dist' => $filters['dist'],
    'school' => $filters['school'] > 0 ? $filters['school'] : null,
    'pos' => $filters['pos'],
    'gen' => $filters['gen'],
    'spec' => $filters['spec'],
], static fn($v) => $v !== null && $v !== ''));

$_SESSION['chatbot_last_filters'] = [
    'q' => $filters['q'] !== '' ? $filters['q'] : $freeTextQuery,
    'pos' => $filters['pos'],
    'dist' => $filters['dist'],
    'gen' => $filters['gen'],
    'spec' => $filters['spec'],
    'school' => (int)$filters['school'],
];

if ($queryString !== '') {
    $_SESSION['chatbot_last_query_string'] = $queryString;
}

$_SESSION['chatbot_last_result_type'] = 'teachers';

if ($isDownloadCmd && $queryString === '' && $isFollowupReference) {
    $queryString = (string)($_SESSION['chatbot_last_query_string'] ?? '');
}

if ($isDownloadCmd) {
    if (!canEdit()) {
        chatbotJson([
            'reply' => 'Download/generate commands are restricted to Admin/HR accounts for data security.',
        ], 403);
    }

    if ($queryString === '' && $isFollowupReference) {
        chatbotJson([
            'reply' => 'I do not have a previous result to export yet. Run a search first, then say "download this".',
        ]);
    }

    $url = APP_URL . '/actions/export.php?format=' . $format . ($queryString !== '' ? '&' . $queryString : '');
    $keywordHint = !empty($trackedKeywords) ? (' Keywords detected: ' . implode(', ', $trackedKeywords) . '.') : '';
    chatbotJson([
        'reply' => 'Prepared your ' . strtoupper($format) . ' export based on your request. Click download below.' . $keywordHint,
        'download' => [
            'url' => $url,
            'label' => 'Download ' . strtoupper($format) . ' Export',
        ],
        'matched_keywords' => $trackedKeywords,
    ]);
}

$where = ['1=1'];
$params = [];
if ($filters['q'] !== '') {
    $like = '%' . $filters['q'] . '%';
    $where[] = '(t.employee_number LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.specialization LIKE ? OR t.position LIKE ? OR s.school_name LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filters['q'] === '' && $freeTextQuery !== '') {
    $like = '%' . $freeTextQuery . '%';
    $where[] = '(t.employee_number LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.specialization LIKE ? OR t.position LIKE ? OR s.school_name LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filters['dist'] !== '') {
    $where[] = '(
        COALESCE(NULLIF(t.district_raw, ""), d.district_name) LIKE ?
        OR REPLACE(LOWER(COALESCE(NULLIF(t.district_raw, ""), d.district_name)), " district", "") LIKE ?
    )';
    $params[] = '%' . $filters['dist'] . '%';
    $params[] = '%' . trim((string)preg_replace('/\bdistrict\b/i', '', strtolower($filters['dist']))) . '%';

    $distTokens = locationTokens($filters['dist']);
    foreach ($distTokens as $token) {
        $where[] = 'LOWER(CONCAT_WS(" ", COALESCE(NULLIF(t.district_raw, ""), d.district_name), COALESCE(t.school_name_raw, s.school_name), COALESCE(t.first_name, ""), COALESCE(t.last_name, ""))) LIKE ?';
        $params[] = '%' . $token . '%';
    }
}
if ($filters['school'] > 0) {
    $where[] = 't.school_id = ?';
    $params[] = $filters['school'];
}
if ($filters['pos'] !== '') {
    $where[] = 'REPLACE(LOWER(COALESCE(t.position, "")), " ", "") LIKE REPLACE(LOWER(?), " ", "")';
    $params[] = '%' . $filters['pos'] . '%';
}
if ($filters['gen'] !== '') {
    $where[] = 'LOWER(TRIM(COALESCE(t.gender, ""))) IN (?, ?)';
    $g = strtolower($filters['gen']);
    if ($g === 'male') {
        $params[] = 'male';
        $params[] = 'm';
    } else {
        $params[] = 'female';
        $params[] = 'f';
    }
}
if ($filters['spec'] !== '') {
    $where[] = 't.specialization LIKE ?';
    $params[] = '%' . $filters['spec'] . '%';
}

$fromSql = ' FROM teachers t
         LEFT JOIN schools s ON t.school_id = s.id
         LEFT JOIN districts d ON s.district_id = d.id
         WHERE ' . implode(' AND ', $where);

$countSql = 'SELECT COUNT(*)' . $fromSql;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalMatches = (int)$countStmt->fetchColumn();

$maxVisible = $wantsAll ? (canEdit() ? 500 : 200) : 25;

$sql = 'SELECT t.id, t.employee_number, t.first_name, t.last_name, t.position, t.gender,
           COALESCE(s.school_name, t.school_name_raw) AS school_name,
           COALESCE(d.district_name, t.district_raw) AS district'
    . $fromSql . '
    ORDER BY t.last_name, t.first_name
    LIMIT ?';

$st = $db->prepare($sql);
$st->execute(array_merge($params, [$maxVisible]));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $row['view_url'] = APP_URL . '/view_teacher.php?id=' . encryptId((int)($row['id'] ?? 0));
}
unset($row);

if (!$rows) {
    $fallbackSeed = trim((string)($parsedCommand['location'] ?? ''));
    if ($fallbackSeed === '') {
        $fallbackSeed = trim((string)($filters['dist'] ?? ''));
    }
    if ($fallbackSeed === '') {
        $fallbackSeed = trim((string)($parsedCommand['seed'] ?? ''));
    }
    if ($fallbackSeed === '') {
        $fallbackSeed = trim((string)$freeTextQuery);
    }

    if ($fallbackSeed !== '') {
        $searchLike = '%' . $fallbackSeed . '%';

        $teacherRowsStmt = $db->prepare(
            'SELECT t.id, t.employee_number, t.first_name, t.last_name, t.position, t.gender,
                    COALESCE(s.school_name, t.school_name_raw) AS school_name,
                    COALESCE(d.district_name, t.district_raw) AS district
             FROM teachers t
             LEFT JOIN schools s ON t.school_id = s.id
             LEFT JOIN districts d ON s.district_id = d.id
             WHERE t.employee_number LIKE ?
                OR t.first_name LIKE ?
                OR t.last_name LIKE ?
                OR t.position LIKE ?
                OR t.specialization LIKE ?
                OR COALESCE(s.school_name, t.school_name_raw) LIKE ?
                OR COALESCE(d.district_name, t.district_raw) LIKE ?
             ORDER BY t.last_name, t.first_name
             LIMIT 20'
        );
        $teacherRowsStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike]);
        $fallbackTeachers = $teacherRowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $schoolRowsStmt = $db->prepare(
            'SELECT s.id, s.school_name, s.school_type, s.learner_count,
                        COALESCE(d.district_name, "") AS district,
                        (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count
             FROM schools s
             LEFT JOIN districts d ON s.district_id = d.id
             WHERE s.school_name LIKE ?
                OR s.school_id_code LIKE ?
                OR s.municipality LIKE ?
                OR d.district_name LIKE ?
             ORDER BY s.school_name ASC
             LIMIT 20'
        );
        $schoolRowsStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike]);
        $fallbackSchools = $schoolRowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $districtRowsStmt = $db->prepare(
            'SELECT d.id, d.district_name,
                    (SELECT COUNT(*) FROM schools s WHERE s.district_id = d.id) AS school_count,
                    (SELECT COUNT(*)
                     FROM teachers t
                     LEFT JOIN schools s2 ON t.school_id = s2.id
                     WHERE s2.district_id = d.id
                        OR LOWER(COALESCE(t.district_raw, "")) LIKE LOWER(?)) AS teacher_count
             FROM districts d
             WHERE d.district_name LIKE ?
             ORDER BY d.district_name
             LIMIT 15'
        );
        $districtRowsStmt->execute([$searchLike, $searchLike]);
        $fallbackDistricts = $districtRowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $fallbackUsers = [];
        if (isAdmin()) {
            $userRowsStmt = $db->prepare(
                'SELECT id, username, full_name, role, is_active
                 FROM users
                 WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? OR role LIKE ?
                 ORDER BY full_name
                 LIMIT 15'
            );
            $userRowsStmt->execute([$searchLike, $searchLike, $searchLike, $searchLike]);
            $fallbackUsers = $userRowsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($fallbackTeachers) || !empty($fallbackSchools) || !empty($fallbackDistricts) || !empty($fallbackUsers)) {
            $_SESSION['chatbot_last_result_type'] = 'universal';
            $_SESSION['chatbot_last_filters'] = ['q' => $fallbackSeed, 'pos' => '', 'dist' => '', 'gen' => '', 'spec' => '', 'school' => 0];
            $_SESSION['chatbot_last_query_string'] = http_build_query(['q' => $fallbackSeed]);
            $_SESSION['chatbot_last_school_query'] = http_build_query(['q' => $fallbackSeed]);

            $universalResults = [
                'teachers' => $fallbackTeachers,
                'schools' => $fallbackSchools,
                'districts' => $fallbackDistricts,
            ];
            if (isAdmin()) {
                $universalResults['users'] = $fallbackUsers;
            }

            chatbotJson([
                'reply' => 'No strict teacher match found, so I automatically ran a system-wide search for "' . $fallbackSeed . '".',
                'results' => $universalResults,
                'matched_keywords' => $trackedKeywords,
                'result_type' => 'universal',
            ]);
        }
    }

    chatbotJson([
        'reply' => humanReply(
            'I could not find teacher records matching that request.',
            ['Try broader terms like "master teacher" or remove one filter.'],
            'If you want, I can run a system-wide search next.'
        ),
        'results' => [],
        'matched_keywords' => $trackedKeywords,
    ]);
}

$shownCount = count($rows);
$reply = 'Found ' . number_format($totalMatches) . ' matching teacher(s). Showing ' . number_format($shownCount) . '.';
if ($wantsAll && $totalMatches > $maxVisible) {
    $reply .= "\nFor performance and security, chat display is capped at " . number_format($maxVisible) . ".";
}
if ($queryString !== '' && canEdit()) {
    $reply .= "\nTip: say 'download csv for this' to export a filtered report.";
}
if (!empty($trackedKeywords)) {
    $reply .= "\nI interpreted these keywords: " . implode(', ', $trackedKeywords) . '.';
}
if (!empty($teacherUsedMemory)) {
    $reply .= "\nI used your previous teacher filters and applied your follow-up.";
}

$download = null;
if (canEdit() && $queryString !== '' && $totalMatches > $shownCount) {
    $download = [
        'url' => APP_URL . '/actions/export.php?format=csv&' . $queryString,
        'label' => 'Download Full CSV (' . number_format($totalMatches) . ' rows)',
    ];
}

chatbotJson([
    'reply' => $reply,
    'results' => $rows,
    'download' => $download,
    'matched_keywords' => $trackedKeywords,
    'result_type' => 'teachers',
    'summary' => [
        'total' => $totalMatches,
        'shown' => $shownCount,
        'capped' => $totalMatches > $shownCount,
    ],
]);
