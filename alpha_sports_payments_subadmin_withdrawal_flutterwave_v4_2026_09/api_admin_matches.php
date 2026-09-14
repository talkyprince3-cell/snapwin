<?php
// api_admin_matches.php — Create / Update / Delete / List admin matches
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
require_once __DIR__ . '/admin_match_state_helper.php';
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────
$uid = $_SESSION['user_id'] ?? 0;
if (empty($_SESSION['main_admin_authenticated'])) {
    try {
        $r2 = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='admin_user_ids'");
        $r2->execute();
        $rawIds   = trim($r2->fetchColumn() ?: '');
        $adminIds = $rawIds ? array_filter(array_map('trim', explode(',', $rawIds))) : [];
    } catch(Exception $e) { $adminIds = []; }
    if (!in_array('1', $adminIds, true)) $adminIds[] = '1';
    if (!in_array((string)$uid, $adminIds, true)) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
    }
}

// ── Ensure extra columns exist (migrations) ──────────────────────
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN home_logo varchar(500) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN away_logo varchar(500) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN league VARCHAR(100) DEFAULT 'Featured'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches MODIFY COLUMN match_time VARCHAR(20) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN match_date DATE DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_home DECIMAL(6,2) DEFAULT 2.10"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_draw DECIMAL(6,2) DEFAULT 3.40"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_away DECIMAL(6,2) DEFAULT 3.60"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN score_home INT(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN score_away INT(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN status VARCHAR(30) DEFAULT 'Not Started'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN elapsed INT(3) DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN is_active TINYINT(1) DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN is_pinned TINYINT(1) DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN pin_order INT(4) DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_locked TINYINT(1) DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_manual_locked TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_auto_enabled TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_suspended_until DATETIME DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN live_at datetime NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN end_at datetime NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN show_in_today tinyint(1) DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN first_half_mins int DEFAULT 45"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN ht_break_mins int DEFAULT 15"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN duration_mins int DEFAULT 50"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN goal_minutes text DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN final_score_home int(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN final_score_away int(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN sub_admin_id INT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
ps_admin_match_ensure_live_odds_schema($pdo);


$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Logo resolver: static map first, then API-Football fallback ──
$TEAM_LOGOS_STATIC = [
    // Premier League
    'Arsenal'=>'https://media.api-sports.io/football/teams/42.png',
    'Aston Villa'=>'https://media.api-sports.io/football/teams/66.png',
    'Bournemouth'=>'https://media.api-sports.io/football/teams/35.png',
    'Brentford'=>'https://media.api-sports.io/football/teams/55.png',
    'Brighton'=>'https://media.api-sports.io/football/teams/51.png',
    'Chelsea'=>'https://media.api-sports.io/football/teams/49.png',
    'Crystal Palace'=>'https://media.api-sports.io/football/teams/52.png',
    'Everton'=>'https://media.api-sports.io/football/teams/45.png',
    'Fulham'=>'https://media.api-sports.io/football/teams/36.png',
    'Liverpool'=>'https://media.api-sports.io/football/teams/40.png',
    'Man City'=>'https://media.api-sports.io/football/teams/50.png',
    'Manchester City'=>'https://media.api-sports.io/football/teams/50.png',
    'Man United'=>'https://media.api-sports.io/football/teams/33.png',
    'Man Utd'=>'https://media.api-sports.io/football/teams/33.png',
    'Manchester United'=>'https://media.api-sports.io/football/teams/33.png',
    'Newcastle'=>'https://media.api-sports.io/football/teams/34.png',
    'Newcastle United'=>'https://media.api-sports.io/football/teams/34.png',
    'Nottingham Forest'=>'https://media.api-sports.io/football/teams/65.png',
    'Nottm Forest'=>'https://media.api-sports.io/football/teams/65.png',
    'Tottenham'=>'https://media.api-sports.io/football/teams/47.png',
    'West Ham'=>'https://media.api-sports.io/football/teams/48.png',
    'Wolves'=>'https://media.api-sports.io/football/teams/39.png',
    'Leeds United'=>'https://media.api-sports.io/football/teams/63.png',
    'Burnley'=>'https://media.api-sports.io/football/teams/44.png',
    // La Liga
    'Barcelona'=>'https://media.api-sports.io/football/teams/529.png',
    'Real Madrid'=>'https://media.api-sports.io/football/teams/541.png',
    'Atletico Madrid'=>'https://media.api-sports.io/football/teams/530.png',
    'Athletic Bilbao'=>'https://media.api-sports.io/football/teams/531.png',
    'Sevilla'=>'https://media.api-sports.io/football/teams/536.png',
    'Valencia'=>'https://media.api-sports.io/football/teams/532.png',
    'Villarreal'=>'https://media.api-sports.io/football/teams/533.png',
    'Real Betis'=>'https://media.api-sports.io/football/teams/543.png',
    'Betis'=>'https://media.api-sports.io/football/teams/543.png',
    'Real Sociedad'=>'https://media.api-sports.io/football/teams/548.png',
    'Girona'=>'https://media.api-sports.io/football/teams/547.png',
    'Osasuna'=>'https://media.api-sports.io/football/teams/727.png',
    'Espanyol'=>'https://media.api-sports.io/football/teams/539.png',
    'Mallorca'=>'https://media.api-sports.io/football/teams/798.png',
    // Bundesliga
    'Bayern Munich'=>'https://media.api-sports.io/football/teams/157.png',
    'Borussia Dortmund'=>'https://media.api-sports.io/football/teams/165.png',
    'Bayer Leverkusen'=>'https://media.api-sports.io/football/teams/168.png',
    'RB Leipzig'=>'https://media.api-sports.io/football/teams/173.png',
    'Eintracht Frankfurt'=>'https://media.api-sports.io/football/teams/169.png',
    'Wolfsburg'=>'https://media.api-sports.io/football/teams/161.png',
    'Werder Bremen'=>'https://media.api-sports.io/football/teams/162.png',
    'Augsburg'=>'https://media.api-sports.io/football/teams/170.png',
    'Stuttgart'=>'https://media.api-sports.io/football/teams/172.png',
    'Hoffenheim'=>'https://media.api-sports.io/football/teams/167.png',
    'Mainz'=>'https://media.api-sports.io/football/teams/164.png',
    'Heidenheim'=>'https://media.api-sports.io/football/teams/176.png',
    'Hamburg'=>'https://media.api-sports.io/football/teams/171.png',
    // Serie A
    'AC Milan'=>'https://media.api-sports.io/football/teams/489.png',
    'Inter Milan'=>'https://media.api-sports.io/football/teams/505.png',
    'Juventus'=>'https://media.api-sports.io/football/teams/496.png',
    'Napoli'=>'https://media.api-sports.io/football/teams/492.png',
    'Atalanta'=>'https://media.api-sports.io/football/teams/499.png',
    'Roma'=>'https://media.api-sports.io/football/teams/497.png',
    'Lazio'=>'https://media.api-sports.io/football/teams/487.png',
    'Fiorentina'=>'https://media.api-sports.io/football/teams/502.png',
    'Bologna'=>'https://media.api-sports.io/football/teams/500.png',
    'Torino'=>'https://media.api-sports.io/football/teams/503.png',
    'Udinese'=>'https://media.api-sports.io/football/teams/494.png',
    'Genoa'=>'https://media.api-sports.io/football/teams/495.png',
    'Lecce'=>'https://media.api-sports.io/football/teams/867.png',
    // Ligue 1
    'PSG'=>'https://media.api-sports.io/football/teams/85.png',
    'Paris Saint-Germain'=>'https://media.api-sports.io/football/teams/85.png',
    'Marseille'=>'https://media.api-sports.io/football/teams/81.png',
    'Monaco'=>'https://media.api-sports.io/football/teams/91.png',
    'Lyon'=>'https://media.api-sports.io/football/teams/80.png',
    'Lille'=>'https://media.api-sports.io/football/teams/79.png',
    'Nice'=>'https://media.api-sports.io/football/teams/84.png',
    'Rennes'=>'https://media.api-sports.io/football/teams/94.png',
    'RC Lens'=>'https://media.api-sports.io/football/teams/116.png',
    'Strasbourg'=>'https://media.api-sports.io/football/teams/95.png',
    'Nantes'=>'https://media.api-sports.io/football/teams/83.png',
    'Brest'=>'https://media.api-sports.io/football/teams/106.png',
    // Champions League / Europe
    'Ajax'=>'https://media.api-sports.io/football/teams/194.png',
    'Benfica'=>'https://media.api-sports.io/football/teams/211.png',
    'Porto'=>'https://media.api-sports.io/football/teams/212.png',
    'Sporting'=>'https://media.api-sports.io/football/teams/228.png',
    'Celtic'=>'https://media.api-sports.io/football/teams/31.png',
    'Rangers'=>'https://media.api-sports.io/football/teams/32.png',
    'Galatasaray'=>'https://media.api-sports.io/football/teams/611.png',
    'PSV'=>'https://media.api-sports.io/football/teams/197.png',
    // African
    'Asante Kotoko'=>'https://media.api-sports.io/football/teams/9994.png',
    'Hearts of Oak'=>'https://media.api-sports.io/football/teams/9993.png',
    'Al Ahly'=>'https://media.api-sports.io/football/teams/440.png',
    'Zamalek'=>'https://media.api-sports.io/football/teams/441.png',
    'Kaizer Chiefs'=>'https://media.api-sports.io/football/teams/451.png',
    'Orlando Pirates'=>'https://media.api-sports.io/football/teams/452.png',
    'Mamelodi Sundowns'=>'https://media.api-sports.io/football/teams/453.png',
    // South American
    'Flamengo'=>'https://media.api-sports.io/football/teams/127.png',
    'Palmeiras'=>'https://media.api-sports.io/football/teams/121.png',
    'River Plate'=>'https://media.api-sports.io/football/teams/440.png',
    'Boca Juniors'=>'https://media.api-sports.io/football/teams/433.png',
];

function resolveLogoByName(string $name): string {
    global $TEAM_LOGOS_STATIC;
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];

    // 1. Exact match in static map (instant, no API call)
    if (isset($TEAM_LOGOS_STATIC[$name])) {
        $cache[$name] = $TEAM_LOGOS_STATIC[$name];
        return $TEAM_LOGOS_STATIC[$name];
    }
    // 2. Case-insensitive partial match
    $lower = strtolower($name);
    foreach ($TEAM_LOGOS_STATIC as $k => $v) {
        if (strtolower($k) === $lower || strpos(strtolower($k), $lower) !== false || strpos($lower, strtolower($k)) !== false) {
            $cache[$name] = $v; return $v;
        }
    }
    // 3. Fallback: API-Football (only if static map missed)
    $apiKey = ps_config('APIFOOTBALL_API_KEY', '');
    if ($apiKey === '') return '';
    $url    = "https://v3.football.api-sports.io/teams?search=" . urlencode($name);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>4,
        CURLOPT_HTTPHEADER=>["x-apisports-key: $apiKey"]]);
    $res = curl_exec($ch);
    if ($res) {
        $d = json_decode($res, true);
        $logo = $d['response'][0]['team']['logo'] ?? '';
        $cache[$name] = $logo; return $logo;
    }
    return '';
}

function safeLogoFilename(string $prefix, string $ext): string {
    $prefix = preg_replace('/[^a-z0-9_-]+/i', '', $prefix) ?: 'logo';
    $ext = preg_replace('/[^a-z0-9]+/i', '', $ext) ?: 'png';
    return $prefix . '_' . str_replace('.', '', uniqid('', true)) . '.' . $ext;
}

function saveUploadedLogo(array $file, string $prefix, string $uploadDir): string {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) return '';
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return '';
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 512 * 1024) return '';
    $name = (string)($file['name'] ?? '');
    $type = strtolower((string)($file['type'] ?? ''));
    $ext = 'png';
    if (str_contains($type, 'jpeg') || str_contains($type, 'jpg')) $ext = 'jpg';
    elseif (str_contains($type, 'png')) $ext = 'png';
    elseif (str_contains($type, 'gif')) $ext = 'gif';
    elseif (str_contains($type, 'webp')) $ext = 'webp';
    else {
        $pathExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($pathExt, ['jpeg','jpg','png','gif','webp'], true)) $ext = $pathExt;
    }
    $safe = safeLogoFilename($prefix, $ext);
    if (!@move_uploaded_file($tmp, rtrim($uploadDir, '/') . '/' . $safe)) return '';
    return '/img/logos/' . $safe;
}

// ── Helpers: compute full match timer window ──────────────────────
function totalMatchDurationMins(?string $durationMins, ?string $firstHalfMins = null, ?string $htBreakMins = null): int {
    $secondHalf = max(1, (int)($durationMins ?: 50));
    $firstHalf  = max(1, (int)($firstHalfMins ?: 45));
    $htBreak    = max(0, (int)($htBreakMins ?: 15));
    return $firstHalf + $htBreak + $secondHalf;
}

function computeTiming(?string $goLiveMins, ?string $durationMins, ?string $firstHalfMins = null, ?string $htBreakMins = null): array {
    if ($goLiveMins === null || $goLiveMins === '') return [null, null];
    $go  = max(0, (int)$goLiveMins);
    $dur = totalMatchDurationMins($durationMins, $firstHalfMins, $htBreakMins);
    $live_at = date('Y-m-d H:i:s', time() + $go * 60);
    $end_at  = date('Y-m-d H:i:s', time() + $go * 60 + $dur * 60);
    return [$live_at, $end_at];
}

switch ($action) {

    case 'list':
        $rows = $pdo->query("SELECT * FROM admin_matches ORDER BY pin_order ASC, created_at DESC")->fetchAll();
        // Add computed state for display
        $now = time();
        foreach ($rows as &$r) {
            $liveOdds = ps_admin_match_live_odds($r, $now, false);
            $r['_odds_effective'] = $liveOdds['odds'];
            $r['_odds_locked_effective'] = $liveOdds['locked'];
            $r['_odds_lock_reason'] = $liveOdds['lock_reason'];
            $r['odds_locked'] = (int)($r['odds_manual_locked'] ?? 0);
            $liveAt = !empty($r['live_at']) ? strtotime($r['live_at']) : null;
            if ($liveAt) {
                if ($now < $liveAt) {
                    $r['_timer_state'] = 'pending';
                    $r['_mins_until']  = (int)(($liveAt - $now) / 60);
                } else {
                    $timerState = ps_admin_match_state($r, $now);
                    if ($timerState['is_finished']) {
                        $r['_timer_state'] = 'ended';
                    } else {
                        $r['_timer_state'] = 'live';
                        $r['_elapsed'] = (int)$timerState['elapsed'];
                        $r['_phase_label'] = (string)$timerState['phase_label'];
                    }
                }
            }
        }
        unset($r);
        echo json_encode(['success'=>true,'matches'=>$rows]); break;

    case 'create':
        $home        = trim($_POST['home_team']  ?? $_POST['home'] ?? '');
        $away        = trim($_POST['away_team']  ?? $_POST['away'] ?? '');
        $league      = trim($_POST['league']     ?? 'Featured');
        $time        = trim($_POST['match_time'] ?? $_POST['time'] ?? '');
        $date        = trim($_POST['match_date'] ?? $_POST['date'] ?? '');
        if ($time === '') $time = date('H:i', strtotime('+2 hours'));
        if ($date === '') $date = date('Y-m-d');
        $o1          = (float)($_POST['odds_home']   ?? 2.10);
        $oX          = (float)($_POST['odds_draw']   ?? 3.40);
        $o2          = (float)($_POST['odds_away']   ?? 3.60);
        // Handle logo upload from either a compact binary file upload or an existing URL/data URL.
        $rawHl = trim($_POST['home_logo'] ?? '');
        $rawAl = trim($_POST['away_logo'] ?? '');

        $uploadDir = __DIR__ . '/img/logos/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

        $saveLogoDataUrl = function(string $dataUrl, string $prefix) use ($uploadDir): string {
            if (!preg_match('/^data:image\/(\w+);base64,(.+)$/s', $dataUrl, $m)) return '';
            $ext  = in_array($m[1],['jpeg','jpg','png','gif','webp']) ? $m[1] : 'png';
            $name = safeLogoFilename($prefix, $ext);
            $bytes = base64_decode($m[2], true);
            if ($bytes === false || strlen($bytes) > 512*1024) return '';
            if (@file_put_contents($uploadDir . $name, $bytes) === false) return '';
            return '/img/logos/' . $name;
        };

        $hl = '';
        $al = '';
        if (!empty($_FILES['home_logo_file']['tmp_name'])) {
            $hl = saveUploadedLogo($_FILES['home_logo_file'], 'home', $uploadDir);
        }
        if (!empty($_FILES['away_logo_file']['tmp_name'])) {
            $al = saveUploadedLogo($_FILES['away_logo_file'], 'away', $uploadDir);
        }
        if ($hl === '' && str_starts_with($rawHl, 'data:image/')) $hl = $saveLogoDataUrl($rawHl, 'home');
        if ($al === '' && str_starts_with($rawAl, 'data:image/')) $al = $saveLogoDataUrl($rawAl, 'away');
        $hl = $hl ?: $rawHl ?: resolveLogoByName($home);
        $al = $al ?: $rawAl ?: resolveLogoByName($away);
        $pinOrder    = (int)($_POST['pin_order']     ?? 0);
        $isPinned    = isset($_POST['is_pinned'])   ? (int)$_POST['is_pinned']   : 1;
        $oddsLocked  = isset($_POST['odds_locked'])  ? (int)$_POST['odds_locked']  : 0;
        $goLiveMins  = $_POST['go_live_mins']  ?? null;
        $durationMin = max(1, (int)($_POST['duration_mins'] ?? 50));

        if (!$home || !$away) { echo json_encode(['success'=>false,'message'=>'Team names required']); break; }

        // Accept a direct live_at datetime string (from datetime-local picker) OR fall back to go_live_mins offset
        $firstHalfMins = max(1, (int)($_POST['first_half_mins'] ?? 45));
        $htBreakMins   = max(0, (int)($_POST['ht_break_mins']   ?? 15));
        if (!empty($_POST['live_at'])) {
            $live_at = $_POST['live_at'];
            $dur = totalMatchDurationMins((string)$durationMin, (string)$firstHalfMins, (string)$htBreakMins);
            $end_at = date('Y-m-d H:i:s', strtotime($live_at) + $dur * 60);
        } else {
            [$live_at, $end_at] = computeTiming($goLiveMins, $durationMin, (string)$firstHalfMins, (string)$htBreakMins);
        }

        $showInToday = isset($_POST['show_in_today']) ? (int)$_POST['show_in_today'] : 1;

        $goalMinutes   = trim((string)($_POST['goal_minutes'] ?? ''));
        $goalMinutes   = $goalMinutes !== '' ? $goalMinutes : null;
        // Parse target score if provided as "1-3" format
        $targetScore = trim($_POST['target_score'] ?? '');
        $finalScoreHome = isset($_POST['final_score_home']) ? (int)$_POST['final_score_home'] : null;
        $finalScoreAway = isset($_POST['final_score_away']) ? (int)$_POST['final_score_away'] : null;
        if ($targetScore && preg_match('/^(\d+)-(\d+)$/', $targetScore, $tm)) {
            $finalScoreHome = (int)$tm[1];
            $finalScoreAway = (int)$tm[2];
        }

        try {
            $pdo->prepare("INSERT INTO admin_matches
                (home_team,away_team,home_logo,away_logo,league,match_time,match_date,
                 odds_home,odds_draw,odds_away,pin_order,is_pinned,odds_locked,odds_manual_locked,live_at,end_at,show_in_today,
                 first_half_mins,ht_break_mins,duration_mins,goal_minutes,is_active,final_score_home,final_score_away)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)")
                ->execute([$home,$away,$hl,$al,$league,$time,$date,$o1,$oX,$o2,
                           $pinOrder,$isPinned,$oddsLocked,$oddsLocked,$live_at,$end_at,$showInToday,
                           $firstHalfMins,$htBreakMins,$durationMin,$goalMinutes,$finalScoreHome,$finalScoreAway]);
            $id = $pdo->lastInsertId();
            echo json_encode(['success'=>true,'id'=>$id,'home_logo'=>$hl,'away_logo'=>$al]); break;
        } catch (Throwable $e) {
            error_log('api_admin_matches create failed: ' . $e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Could not save match. Please try again.']); break;
        }

    case 'update':
        $id     = (int)($_POST['id'] ?? 0);
        $fields = []; $vals = [];
        $previousMatch = null;
        if ($id > 0) {
            $previousStmt = $pdo->prepare("SELECT * FROM admin_matches WHERE id=? LIMIT 1");
            $previousStmt->execute([$id]);
            $previousMatch = $previousStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Pre-process logo fields — save uploaded files or base64 uploads to disk
        $uploadDir2 = __DIR__ . '/img/logos/';
        if (!is_dir($uploadDir2)) @mkdir($uploadDir2, 0755, true);
        foreach (['home_logo','away_logo'] as $lf) {
            $fileKey = $lf . '_file';
            if (!empty($_FILES[$fileKey]['tmp_name'])) {
                $saved = saveUploadedLogo($_FILES[$fileKey], $lf === 'home_logo' ? 'home' : 'away', $uploadDir2);
                if ($saved !== '') {
                    $_POST[$lf] = $saved;
                    continue;
                }
            }
            if (!isset($_POST[$lf])) continue;
            $raw = trim($_POST[$lf]);
            if (str_starts_with($raw, 'data:image/') && preg_match('/^data:image\/(\w+);base64,(.+)$/s', $raw, $lm)) {
                $ext  = in_array($lm[1],['jpeg','jpg','png','gif','webp']) ? $lm[1] : 'png';
                $nm   = safeLogoFilename($lf, $ext);
                $bytes = base64_decode($lm[2]);
                if ($bytes && strlen($bytes) <= 512*1024) {
                    @file_put_contents($uploadDir2 . $nm, $bytes);
                    $_POST[$lf] = '/img/logos/' . $nm;
                }
            }
        }

        if (array_key_exists('target_score', $_POST)) {
            $targetScore = trim((string)$_POST['target_score']);
            if ($targetScore !== '' && preg_match('/^(\d+)-(\d+)$/', $targetScore, $tsm)) {
                $_POST['final_score_home'] = (int)$tsm[1];
                $_POST['final_score_away'] = (int)$tsm[2];
            } elseif ($targetScore === '') {
                $_POST['final_score_home'] = '';
                $_POST['final_score_away'] = '';
            }
        }

        if (($_POST['status'] ?? '') === 'FT' && trim((string)($_POST['score_home'] ?? '')) === '') {
            if (isset($_POST['final_score_home']) && $_POST['final_score_home'] !== '') {
                $_POST['score_home'] = $_POST['final_score_home'];
                $_POST['score_away'] = $_POST['final_score_away'] ?? 0;
            } else {
                $fqr = $pdo->prepare("SELECT final_score_home, final_score_away FROM admin_matches WHERE id=?");
                $fqr->execute([$id]);
                $frow = $fqr->fetch(PDO::FETCH_ASSOC);
                if ($frow && $frow['final_score_home'] !== null && $frow['final_score_home'] !== '') {
                    $_POST['score_home'] = $frow['final_score_home'];
                    $_POST['score_away'] = $frow['final_score_away'];
                }
            }
        }

        if (isset($_POST['first_half_mins'])) $_POST['first_half_mins'] = max(1, (int)$_POST['first_half_mins']);
        if (isset($_POST['ht_break_mins'])) $_POST['ht_break_mins'] = max(0, (int)$_POST['ht_break_mins']);
        if (isset($_POST['duration_mins'])) $_POST['duration_mins'] = max(1, (int)$_POST['duration_mins']);
        if (!empty($_POST['live_at']) && isset($_POST['duration_mins'])) {
            $totalDuration = totalMatchDurationMins(
                (string)$_POST['duration_mins'],
                isset($_POST['first_half_mins']) ? (string)$_POST['first_half_mins'] : null,
                isset($_POST['ht_break_mins']) ? (string)$_POST['ht_break_mins'] : null
            );
            $_POST['end_at'] = date('Y-m-d H:i:s', strtotime((string)$_POST['live_at']) + ($totalDuration * 60));
        }
        if (isset($_POST['odds_locked'])) {
            $_POST['odds_manual_locked'] = (int)$_POST['odds_locked'];
        }

        $map = ['home_team','away_team','league','match_time','match_date','first_half_mins','ht_break_mins','duration_mins','goal_minutes',
                'odds_home','odds_draw','odds_away','score_home','score_away',
                'final_score_home','final_score_away',
                'status','elapsed','odds_locked','odds_manual_locked','odds_auto_enabled','is_pinned','is_active','pin_order',
                'home_logo','away_logo','live_at','end_at','show_in_today'];
        foreach ($map as $f) {
            if (isset($_POST[$f])) {
                $fields[] = "`$f` = ?";
                $vals[]   = $_POST[$f] === '' ? null : $_POST[$f];
            }
        }
        // Auto-set score to FT final when status becomes FT and final scores are set
        if (isset($_POST['status']) && $_POST['status'] === 'FT') {
            // When match is marked FT, if final score set, copy to score_home/score_away
            $fqr = $pdo->prepare("SELECT final_score_home, final_score_away, score_home, score_away FROM admin_matches WHERE id=?");
            $fqr->execute([$id]);
            $frow = $fqr->fetch(PDO::FETCH_ASSOC);
            if ($frow && $frow['final_score_home'] !== null && !isset($_POST['score_home'])) {
                $_POST['score_home'] = $frow['final_score_home'];
                $_POST['score_away'] = $frow['final_score_away'];
                $fields[] = "`score_home` = ?"; $vals[] = $frow['final_score_home'];
                $fields[] = "`score_away` = ?"; $vals[] = $frow['final_score_away'];
            }
        }
        // Handle go_live_mins / duration_mins update
        if (isset($_POST['go_live_mins']) && $_POST['go_live_mins'] !== '') {
            [$live_at, $end_at] = computeTiming(
                $_POST['go_live_mins'],
                $_POST['duration_mins'] ?? null,
                $_POST['first_half_mins'] ?? null,
                $_POST['ht_break_mins'] ?? null
            );
            $fields[] = "`live_at` = ?"; $vals[] = $live_at;
            $fields[] = "`end_at`  = ?"; $vals[] = $end_at;
        }
        if (!$id || empty($fields)) { echo json_encode(['success'=>false,'message'=>'Nothing to update']); break; }
        $vals[] = $id;
        $pdo->prepare("UPDATE admin_matches SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
        if ($previousMatch) {
            $currentStmt = $pdo->prepare("SELECT score_home,score_away,status FROM admin_matches WHERE id=? LIMIT 1");
            $currentStmt->execute([$id]);
            $currentMatch = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $oldScore = (string)($previousMatch['score_home'] ?? '') . ':' . (string)($previousMatch['score_away'] ?? '');
            $newScore = (string)($currentMatch['score_home'] ?? '') . ':' . (string)($currentMatch['score_away'] ?? '');
            $oldStatus = strtoupper(trim((string)($previousMatch['status'] ?? '')));
            $newStatus = strtoupper(trim((string)($currentMatch['status'] ?? '')));
            $liveStatuses = ['1H','HT','2H','ET'];
            $suspendSeconds = ($oldScore !== $newScore && in_array($newStatus, $liveStatuses, true)) ? 12 : 0;
            if ($oldStatus !== $newStatus && in_array($newStatus, ['1H','2H','ET'], true)) $suspendSeconds = max($suspendSeconds, 6);
            if ($suspendSeconds > 0) {
                $pdo->prepare("UPDATE admin_matches SET odds_suspended_until=DATE_ADD(NOW(), INTERVAL {$suspendSeconds} SECOND) WHERE id=?")
                    ->execute([$id]);
            }
        }
        echo json_encode(['success'=>true]); break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM admin_matches WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]); break;

    case 'logo':
        $name = trim($_GET['name'] ?? '');
        $logo = $name ? resolveLogoByName($name) : '';
        echo json_encode(['logo'=>$logo]); break;

    case 'settings_get':
        $keys = ['dashboard_after_admin','odds_global_lock','popular_count','today_count','live_count','live_match_source','live_api_cache_seconds','cashout_locked','deposit_method','default_theme','site_min_stake','site_min_deposit','site_min_deposit_ghs','site_min_deposit_ngn','withdraw_verification_amount','withdraw_verification_amount_ghs','withdraw_submission_amount','withdraw_submission_amount_ghs','support_whatsapp_link','support_telegram_link','usdt_trc20_address','usd_exchange_rates','payment_provider_catalog','payment_default_provider','payment_routing_rules','direct_gateway_provider','main_admin_username','direct_flutterwave_version','direct_flutterwave_v4_environment'];
        $publicKeys = ['direct_paystack_public_key','direct_flutterwave_public_key','direct_flutterwave_v4_client_id','direct_moolre_ghs_account','direct_moolre_ngn_account'];
        $secretKeys = ['apifootball_api_key','techvault_shared_token','direct_paystack_secret_key','direct_flutterwave_secret_key','direct_flutterwave_encryption_key','direct_flutterwave_webhook_secret','direct_flutterwave_v4_client_secret','direct_flutterwave_v4_encryption_key','direct_flutterwave_v4_webhook_secret','direct_moolre_api_user','direct_moolre_public_key','direct_moolre_ghs_webhook_secret','direct_moolre_ngn_webhook_secret'];
        $result = ['success'=>true];
        foreach (array_merge($keys, $publicKeys, $secretKeys) as $k) {
            $s = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`=?");
            $s->execute([$k]); $v = $s->fetchColumn();
            if (in_array($k, $secretKeys, true)) {
                $result[$k . '_configured'] = ($v !== false && trim((string)$v) !== '');
            } elseif (in_array($k, $publicKeys, true)) {
                $result[$k . '_configured'] = ($v !== false && trim((string)$v) !== '');
                $result[$k . '_public'] = $v !== false ? $v : '';
            } else {
                $result[$k] = $v !== false ? $v : null;
            }
        }
        echo json_encode($result); break;

    case 'settings':
        foreach (['dashboard_after_admin','odds_global_lock','popular_count','today_count','live_count','live_match_source','live_api_cache_seconds','apifootball_api_key','cashout_locked','deposit_method','default_theme','site_min_stake','site_min_deposit','site_min_deposit_ghs','site_min_deposit_ngn','withdraw_verification_amount','withdraw_verification_amount_ghs','withdraw_submission_amount','withdraw_submission_amount_ghs','support_whatsapp_link','support_telegram_link','usdt_trc20_address','usd_exchange_rates','payment_provider_catalog','payment_default_provider','payment_routing_rules','direct_gateway_provider','main_admin_username','techvault_shared_token','direct_paystack_public_key','direct_paystack_secret_key','direct_flutterwave_version','direct_flutterwave_public_key','direct_flutterwave_secret_key','direct_flutterwave_encryption_key','direct_flutterwave_webhook_secret','direct_flutterwave_v4_client_id','direct_flutterwave_v4_client_secret','direct_flutterwave_v4_encryption_key','direct_flutterwave_v4_webhook_secret','direct_flutterwave_v4_environment','direct_moolre_api_user','direct_moolre_public_key','direct_moolre_ghs_account','direct_moolre_ngn_account','direct_moolre_ghs_webhook_secret','direct_moolre_ngn_webhook_secret'] as $k) {
            if (isset($_POST[$k]) && trim((string)$_POST[$k]) !== '') {
                $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                    ->execute([$k,$_POST[$k],$_POST[$k]]);
            }
        }
        if (isset($_POST['main_admin_new_password']) && (string)$_POST['main_admin_new_password'] !== '') {
            $password = (string)$_POST['main_admin_new_password'];
            if (strlen($password) < 6) {
                echo json_encode(['success'=>false,'message'=>'Main admin password must be at least 6 characters']);
                break;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('main_admin_password_hash',?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$hash,$hash]);
        }
        // Bust ALL match caches so new counts take effect immediately
        @unlink(__DIR__ . '/cache/sporty_prematch.json');      // api_matches.php cache
        @unlink(__DIR__ . '/cache/apifootball_prematch.json'); // api-football fallback cache
        @unlink(__DIR__ . '/cache/api_football_matches.json');
        @unlink(__DIR__ . '/cache/sporty_live_matches.json');
        echo json_encode(['success'=>true]); break;

    case 'flutterwave_v4_test':
        require_once __DIR__ . '/payment_gateway_helper.php';
        $clientId = ps_flutterwave_v4_client_id($pdo);
        $clientSecret = ps_flutterwave_v4_client_secret($pdo);
        if ($clientId === '' || $clientSecret === '') {
            echo json_encode(['success'=>false,'message'=>'Save the Flutterwave V4 Client ID and Client Secret first.']);
            break;
        }
        $tokenRequest = curl_init('https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token');
        curl_setopt_array($tokenRequest, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['client_id'=>$clientId, 'client_secret'=>$clientSecret, 'grant_type'=>'client_credentials']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $tokenBody = curl_exec($tokenRequest);
        $tokenCode = curl_getinfo($tokenRequest, CURLINFO_HTTP_CODE);
        $tokenError = curl_error($tokenRequest);
        curl_close($tokenRequest);
        $tokenData = json_decode((string)$tokenBody, true) ?: [];
        $accessToken = (string)($tokenData['access_token'] ?? '');
        if ($tokenError !== '' || $tokenCode < 200 || $tokenCode >= 300 || $accessToken === '') {
            $message = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Flutterwave rejected the V4 Client ID or Client Secret.';
            echo json_encode(['success'=>false,'message'=>$message]);
            break;
        }
        $checkRequest = curl_init(rtrim(ps_flutterwave_v4_base_url($pdo), '/') . '/customers?page=1');
        curl_setopt_array($checkRequest, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $checkBody = curl_exec($checkRequest);
        $checkCode = curl_getinfo($checkRequest, CURLINFO_HTTP_CODE);
        $checkError = curl_error($checkRequest);
        curl_close($checkRequest);
        $checkData = json_decode((string)$checkBody, true) ?: [];
        if ($checkError !== '' || $checkCode < 200 || $checkCode >= 300) {
            $message = $checkData['error']['message'] ?? $checkData['message'] ?? 'Flutterwave credentials were accepted, but the selected environment could not be reached.';
            echo json_encode(['success'=>false,'message'=>$message]);
            break;
        }
        echo json_encode(['success'=>true,'message'=>'Flutterwave V4 connection verified for ' . strtoupper(ps_flutterwave_v4_environment($pdo)) . ' mode.']);
        break;

    // ── FT Score helpers for ticket_details auto-pull ──────────────
    case 'get_ft_scores':
        // Return saved ft_scores for all matches on a ticket
        $tid = (int)($_GET['ticket_id'] ?? 0);
        if (!$tid) { echo json_encode(['success'=>false,'scores'=>[]]); break; }

        $ticketStatusStmt = $pdo->prepare("SELECT status FROM tickets WHERE id=? LIMIT 1");
        $ticketStatusStmt->execute([$tid]);
        $ticketStatus = (string)$ticketStatusStmt->fetchColumn();
        if (!in_array($ticketStatus, ['Won', 'Lost', 'Void'], true)) {
            echo json_encode(['success'=>true,'scores'=>[]]);
            break;
        }

        $st = $pdo->prepare("SELECT id, ft_score, home_team, away_team, game_id FROM ticket_matches WHERE ticket_id=?");
        $st->execute([$tid]);
        $rows = $st->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            if (!empty($row['ft_score']) && $row['ft_score'] !== '?') {
                $out[] = ['match_id' => $row['id'], 'ft_score' => $row['ft_score']];
                continue;
            }
            $score = null;

            // Helper: pick best score from an admin_matches row
            // Prefer final_score (what admin explicitly sets), fall back to score_home/away
            $pickScore = function(?array $r): ?string {
                if (!$r) return null;
                // final_score_home/away is what admin sets as the real result
                $fh = $r['final_score_home'] ?? null;
                $fa = $r['final_score_away'] ?? null;
                if ($fh !== null && $fh !== '' && $fa !== null && $fa !== '') {
                    return trim((string)$fh) . ' - ' . trim((string)$fa);
                }
                $sh = $r['score_home'] ?? null;
                $sa = $r['score_away'] ?? null;
                if ($sh !== null && $sh !== '' && $sa !== null && $sa !== '') {
                    return trim((string)$sh) . ' - ' . trim((string)$sa);
                }
                return null;
            };

            // 1. Try by game_id — strip 'adm_' prefix before comparing to integer id column
            if (!$score && !empty($row['game_id'])) {
                $rawGid = trim((string)$row['game_id']);
                // Strip adm_ prefix to get the numeric id
                $numericId = preg_replace('/^adm_/i', '', $rawGid);
                $gq = $pdo->prepare("SELECT score_home, score_away, final_score_home, final_score_away
                    FROM admin_matches
                    WHERE status IN ('FT','Ended','Closed','FINISHED','AET','PEN')
                    AND (id = ? OR CONCAT('adm_', id) = ?) LIMIT 1");
                $gq->execute([$numericId, $rawGid]);
                $score = $pickScore($gq->fetch(PDO::FETCH_ASSOC) ?: null);
            }

            // 2. Exact full name match (case-insensitive)
            if (!$score) {
                $eq = $pdo->prepare("SELECT score_home, score_away, final_score_home, final_score_away
                    FROM admin_matches
                    WHERE status IN ('FT','Ended','Closed','FINISHED','AET','PEN')
                    AND LOWER(TRIM(home_team)) = LOWER(TRIM(?))
                    AND LOWER(TRIM(away_team)) = LOWER(TRIM(?)) LIMIT 1");
                $eq->execute([$row['home_team'], $row['away_team']]);
                $score = $pickScore($eq->fetch(PDO::FETCH_ASSOC) ?: null);
            }

            // 3. Fuzzy first-word fallback
            if (!$score) {
                $homeKey = '%' . strtolower(explode(' ', trim($row['home_team']))[0] ?? '') . '%';
                $awayKey = '%' . strtolower(explode(' ', trim($row['away_team']))[0] ?? '') . '%';
                if (strlen(trim($homeKey, '%')) >= 2) {
                    $am = $pdo->prepare("SELECT score_home, score_away, final_score_home, final_score_away
                        FROM admin_matches
                        WHERE status IN ('FT','Ended','Closed','FINISHED','AET','PEN')
                        AND LOWER(home_team) LIKE ? AND LOWER(away_team) LIKE ? LIMIT 1");
                    $am->execute([$homeKey, $awayKey]);
                    $score = $pickScore($am->fetch(PDO::FETCH_ASSOC) ?: null);
                }
            }

            // 4. Also check ext_match_results (for sportybet/af matches)
            if (!$score && !empty($row['game_id'])) {
                $eq2 = $pdo->prepare("SELECT home_score, away_score FROM ext_match_results
                    WHERE game_id = ? AND home_score IS NOT NULL
                    AND status IN ('FT','AET','PEN','FINISHED','Ended','Closed')
                    ORDER BY updated_at DESC LIMIT 1");
                $eq2->execute([trim((string)$row['game_id'])]);
                $r2 = $eq2->fetch(PDO::FETCH_ASSOC);
                if ($r2 && $r2['home_score'] !== null) {
                    $score = $r2['home_score'] . ' - ' . $r2['away_score'];
                }
            }

            if ($score) {
                // Persist to ticket_matches so next load is instant
                $pdo->prepare("UPDATE ticket_matches SET ft_score=? WHERE id=?")->execute([$score, $row['id']]);
                $out[] = ['match_id' => $row['id'], 'ft_score' => $score];
            }
        }
        echo json_encode(['success'=>true,'scores'=>$out]);
        break;

    case 'save_ft_score':
        // Persist a FT score fetched by the frontend from live API
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $mid  = (int)($body['match_id'] ?? 0);
        $score = trim($body['ft_score'] ?? '');
        if ($mid && $score) {
            $pdo->prepare("UPDATE ticket_matches tm
                JOIN tickets t ON t.id=tm.ticket_id
                SET tm.ft_score=?
                WHERE tm.id=? AND t.status IN ('Won','Lost','Void')")
                ->execute([$score, $mid]);
        }
        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
?>
