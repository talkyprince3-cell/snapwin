<?php

if (!function_exists('ps_admin_match_goal_minutes')) {
    function ps_admin_match_goal_minutes(?string $raw, int $total, int $duration): array {
        $minutes = preg_split('/[\s,]+/', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $minutes = array_values(array_unique(array_filter(array_map('intval', $minutes), fn($v) => $v >= 0)));
        sort($minutes);
        if ($total <= 0) return [];
        if (!$minutes) {
            for ($i = 1; $i <= $total; $i++) {
                $minutes[] = (int)round($duration / ($total + 1) * $i);
            }
        }
        if (count($minutes) < $total) {
            $last = $minutes ? (int)end($minutes) : 0;
            $remaining = $total - count($minutes);
            $step = max(1, (int)round(($duration - $last) / ($remaining + 1)));
            for ($i = 1; $i <= $remaining; $i++) {
                $minutes[] = min($duration, $last + ($step * $i));
            }
        }
        return array_slice($minutes, 0, $total);
    }
}

if (!function_exists('ps_admin_match_auto_score')) {
    function ps_admin_match_auto_score(int $finalHome, int $finalAway, int $elapsed, ?string $goalMinutes, int $duration): array {
        $total = $finalHome + $finalAway;
        if ($total <= 0 || $elapsed <= 0) return [0, 0];
        if ($elapsed >= $duration) return [$finalHome, $finalAway];
        $minutes = ps_admin_match_goal_minutes($goalMinutes, $total, $duration);
        $scored = count(array_filter($minutes, fn($minute) => $minute <= $elapsed));
        if ($scored <= 0) return [0, 0];
        if ($scored >= $total) return [$finalHome, $finalAway];
        $home = (int)round($scored * $finalHome / $total);
        return [$home, $scored - $home];
    }
}

if (!function_exists('ps_admin_match_state')) {
    function ps_admin_match_state(array $row, ?int $now = null): array {
        $now ??= time();
        $firstHalf = max(1, (int)($row['first_half_mins'] ?? 45));
        $breakMinutes = max(0, (int)($row['ht_break_mins'] ?? 15));
        $secondHalf = max(1, (int)($row['duration_mins'] ?? $firstHalf));
        $playingMinutes = $firstHalf + $secondHalf;
        $liveAt = !empty($row['live_at']) ? strtotime((string)$row['live_at']) : 0;
        $endAt = !empty($row['end_at']) ? strtotime((string)$row['end_at']) : 0;
        $status = strtoupper(trim((string)($row['status'] ?? 'UPCOMING')));
        $elapsed = max(0, (int)($row['elapsed'] ?? 0));
        $clockSeconds = $elapsed * 60;

        if ($liveAt > 0 && $now >= $liveAt) {
            $wallSeconds = max(0, $now - $liveAt);
            $firstSeconds = $firstHalf * 60;
            $breakSeconds = $breakMinutes * 60;
            $playingEndSeconds = ($firstHalf + $breakMinutes + $secondHalf) * 60;

            if (($endAt > 0 && $now >= $endAt) || ($endAt <= 0 && $wallSeconds >= $playingEndSeconds)) {
                $status = 'FT';
                $clockSeconds = $playingMinutes * 60;
            } elseif ($wallSeconds <= $firstSeconds) {
                $status = '1H';
                $clockSeconds = $wallSeconds;
            } elseif ($wallSeconds <= $firstSeconds + $breakSeconds) {
                $status = 'HT';
                $clockSeconds = $firstSeconds;
            } else {
                $status = '2H';
                $clockSeconds = $firstSeconds + ($wallSeconds - $firstSeconds - $breakSeconds);
            }
            $elapsed = min($playingMinutes, (int)floor($clockSeconds / 60));
        } elseif ($liveAt > 0 && $now < $liveAt) {
            $status = 'UPCOMING';
            $elapsed = 0;
            $clockSeconds = 0;
        }

        $isLive = in_array($status, ['1H', 'HT', '2H', 'ET'], true);
        $isFinished = in_array($status, ['FT', 'AET', 'PEN', 'ENDED', 'CLOSED', 'FINISHED'], true);
        $started = $isLive || $isFinished || ($liveAt > 0 && $now >= $liveAt);
        $finalAvailable = $row['final_score_home'] !== null && $row['final_score_home'] !== ''
            && $row['final_score_away'] !== null && $row['final_score_away'] !== '';
        $liveAvailable = $row['score_home'] !== null && $row['score_home'] !== ''
            && $row['score_away'] !== null && $row['score_away'] !== '';
        $homeScore = null;
        $awayScore = null;

        if ($isFinished) {
            if ($finalAvailable) {
                $homeScore = (int)$row['final_score_home'];
                $awayScore = (int)$row['final_score_away'];
            } elseif ($liveAvailable) {
                $homeScore = (int)$row['score_home'];
                $awayScore = (int)$row['score_away'];
            }
        } elseif ($isLive) {
            $liveEqualsFinal = $finalAvailable && $liveAvailable
                && (int)$row['score_home'] === (int)$row['final_score_home']
                && (int)$row['score_away'] === (int)$row['final_score_away'];
            if ($liveAvailable && !$liveEqualsFinal) {
                $homeScore = (int)$row['score_home'];
                $awayScore = (int)$row['score_away'];
            } elseif ($finalAvailable) {
                [$homeScore, $awayScore] = ps_admin_match_auto_score(
                    (int)$row['final_score_home'],
                    (int)$row['final_score_away'],
                    $elapsed,
                    (string)($row['goal_minutes'] ?? ''),
                    $playingMinutes
                );
            } else {
                $homeScore = $liveAvailable ? (int)$row['score_home'] : 0;
                $awayScore = $liveAvailable ? (int)$row['score_away'] : 0;
            }
        }

        $period = match ($status) {
            '1H' => '1st',
            'HT' => 'HT',
            '2H' => '2nd',
            'ET' => 'ET',
            'AET' => 'AET',
            'PEN' => 'PEN',
            'FT', 'ENDED', 'CLOSED', 'FINISHED' => 'FT',
            default => 'Pre',
        };
        $phaseLabel = in_array($status, ['1H', '2H', 'ET'], true)
            ? $period . ' ' . sprintf('%02d:%02d', intdiv($clockSeconds, 60), $clockSeconds % 60)
            : $period;

        return [
            'status' => $status,
            'period' => $period,
            'phase_label' => $phaseLabel,
            'elapsed' => $elapsed,
            'clock_seconds' => max(0, $clockSeconds),
            'is_live' => $isLive,
            'is_finished' => $isFinished,
            'started' => $started,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'score' => ($homeScore !== null && $awayScore !== null) ? ($homeScore . ' - ' . $awayScore) : null,
        ];
    }
}

if (!function_exists('ps_admin_match_ensure_live_odds_schema')) {
    function ps_admin_match_ensure_live_odds_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        foreach ([
            "ALTER TABLE admin_matches ADD COLUMN odds_auto_enabled TINYINT(1) NOT NULL DEFAULT 1",
            "ALTER TABLE admin_matches ADD COLUMN odds_suspended_until DATETIME NULL",
            "ALTER TABLE admin_matches ADD COLUMN odds_manual_locked TINYINT(1) NOT NULL DEFAULT 0",
        ] as $ddl) {
            try { $pdo->exec($ddl); } catch (Throwable $e) {}
        }
        try {
            $pdo->exec("UPDATE admin_matches SET odds_manual_locked=1 WHERE odds_locked=1 AND COALESCE(odds_auto_enabled, 1)=0");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ps_admin_match_poisson_outcomes')) {
    function ps_admin_match_poisson_outcomes(float $homeLambda, float $awayLambda, int $homeScore, int $awayScore): array {
        $home = [exp(-$homeLambda)];
        $away = [exp(-$awayLambda)];
        for ($i = 1; $i <= 10; $i++) {
            $home[$i] = $home[$i - 1] * $homeLambda / $i;
            $away[$i] = $away[$i - 1] * $awayLambda / $i;
        }

        $result = [0.0, 0.0, 0.0];
        foreach ($home as $hg => $hp) {
            foreach ($away as $ag => $ap) {
                $probability = $hp * $ap;
                $finalHome = $homeScore + $hg;
                $finalAway = $awayScore + $ag;
                if ($finalHome > $finalAway) $result[0] += $probability;
                elseif ($finalHome === $finalAway) $result[1] += $probability;
                else $result[2] += $probability;
            }
        }
        $total = array_sum($result);
        if ($total <= 0) return [1 / 3, 1 / 3, 1 / 3];
        return array_map(fn($value) => $value / $total, $result);
    }
}

if (!function_exists('ps_admin_match_calculate_live_odds')) {
    function ps_admin_match_calculate_live_odds(array $row, array $state): array {
        $baseOdds = [
            max(1.01, (float)($row['odds_home'] ?? 2.10)),
            max(1.01, (float)($row['odds_draw'] ?? 3.40)),
            max(1.01, (float)($row['odds_away'] ?? 3.60)),
        ];
        if (empty($state['is_live']) || (int)($row['odds_auto_enabled'] ?? 1) !== 1) return $baseOdds;

        $raw = [1 / $baseOdds[0], 1 / $baseOdds[1], 1 / $baseOdds[2]];
        $overround = max(1.03, min(1.18, array_sum($raw)));
        $rawTotal = array_sum($raw);
        $baseProb = array_map(fn($value) => $value / $rawTotal, $raw);

        $strengthLogRatio = log(max(0.02, $baseProb[0]) / max(0.02, $baseProb[2]));
        $homeShare = 1 / (1 + exp(-0.72 * $strengthLogRatio));
        $expectedGoals = max(1.70, min(3.40, 3.60 - (4.0 * $baseProb[1])));
        $fullHomeLambda = $expectedGoals * $homeShare;
        $fullAwayLambda = $expectedGoals - $fullHomeLambda;

        $firstHalf = max(1, (int)($row['first_half_mins'] ?? 45));
        $secondHalf = max(1, (int)($row['duration_mins'] ?? 45));
        $playingMinutes = $firstHalf + $secondHalf;
        $elapsed = max(0, min($playingMinutes, (int)($state['elapsed'] ?? 0)));
        $remainingFraction = max(0.0, ($playingMinutes - $elapsed) / $playingMinutes);
        $homeScore = max(0, (int)($state['home_score'] ?? 0));
        $awayScore = max(0, (int)($state['away_score'] ?? 0));

        $initialModel = ps_admin_match_poisson_outcomes($fullHomeLambda, $fullAwayLambda, 0, 0);
        $currentModel = ps_admin_match_poisson_outcomes(
            $fullHomeLambda * $remainingFraction,
            $fullAwayLambda * $remainingFraction,
            $homeScore,
            $awayScore
        );

        $adjusted = [];
        for ($i = 0; $i < 3; $i++) {
            $adjusted[$i] = $baseProb[$i] * ($currentModel[$i] / max(0.000001, $initialModel[$i]));
        }
        $adjustedTotal = array_sum($adjusted);
        if ($adjustedTotal <= 0) $adjusted = $currentModel;
        else $adjusted = array_map(fn($value) => $value / $adjustedTotal, $adjusted);

        return array_map(
            fn($probability) => round(max(1.01, min(99.00, 1 / max(0.0001, $probability * $overround))), 2),
            $adjusted
        );
    }
}

if (!function_exists('ps_admin_match_live_odds')) {
    function ps_admin_match_live_odds(array $row, ?int $now = null, bool $globalLocked = false): array {
        $now ??= time();
        $state = ps_admin_match_state($row, $now);
        $odds = ps_admin_match_calculate_live_odds($row, $state);
        $status = strtoupper((string)($state['status'] ?? 'UPCOMING'));
        $manualLocked = (int)($row['odds_manual_locked'] ?? 0) === 1;
        $reason = '';
        $suspendedUntil = 0;

        if (!empty($row['odds_suspended_until'])) {
            $suspendedUntil = strtotime((string)$row['odds_suspended_until']) ?: 0;
            if ($suspendedUntil > $now) $reason = 'Updating odds';
        }

        $liveAt = !empty($row['live_at']) ? (strtotime((string)$row['live_at']) ?: 0) : 0;
        if (!empty($state['is_live']) && $liveAt > 0) {
            $firstHalf = max(1, (int)($row['first_half_mins'] ?? 45));
            $breakMinutes = max(0, (int)($row['ht_break_mins'] ?? 15));
            $playingMinutes = $firstHalf + max(1, (int)($row['duration_mins'] ?? 45));

            $transitionTimes = [$liveAt, $liveAt + (($firstHalf + $breakMinutes) * 60)];
            foreach ($transitionTimes as $transitionAt) {
                if ($now >= $transitionAt && $now < $transitionAt + 6) {
                    $suspendedUntil = max($suspendedUntil, $transitionAt + 6);
                    $reason = 'Match restarting';
                }
            }

            $finalHome = $row['final_score_home'] ?? null;
            $finalAway = $row['final_score_away'] ?? null;
            if ($finalHome !== null && $finalHome !== '' && $finalAway !== null && $finalAway !== '') {
                $goalMinutes = ps_admin_match_goal_minutes(
                    (string)($row['goal_minutes'] ?? ''),
                    max(0, (int)$finalHome + (int)$finalAway),
                    $playingMinutes
                );
                foreach ($goalMinutes as $goalMinute) {
                    $wallOffsetMinutes = $goalMinute <= $firstHalf
                        ? $goalMinute
                        : $goalMinute + $breakMinutes;
                    $goalAt = $liveAt + ($wallOffsetMinutes * 60);
                    if ($now >= $goalAt && $now < $goalAt + 12) {
                        $suspendedUntil = max($suspendedUntil, $goalAt + 12);
                        $reason = 'Goal check';
                    }
                }
            }
        }

        $locked = false;
        if ($globalLocked) { $locked = true; $reason = 'Global lock'; }
        elseif ($manualLocked) { $locked = true; $reason = 'Manual lock'; }
        elseif (!empty($state['is_finished'])) { $locked = true; $reason = 'Full time'; }
        elseif ($status === 'HT') { $locked = true; $reason = 'Halftime'; }
        elseif ($suspendedUntil > $now) { $locked = true; }

        return [
            'odds' => $odds,
            'locked' => $locked,
            'suspended' => $suspendedUntil > $now && !$manualLocked && !$globalLocked,
            'suspended_until' => $suspendedUntil ?: null,
            'lock_reason' => $reason,
            'state' => $state,
        ];
    }
}
