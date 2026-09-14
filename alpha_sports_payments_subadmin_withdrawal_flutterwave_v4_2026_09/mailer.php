<?php
/**
 * mailer.php — Alpha Sports Central Email Engine (SendGrid)
 * -----------------------------------------------------------------
 * Powered by SendGrid Web API v3.
 * The API key is stored in db.php as:
 *   define('SENDGRID_API_KEY', 'SG.xxx...');
 *
 * -- SendGrid Ban-Prevention Safety Protocols ----------------------
 *  1. All recipients validated with filter_var before sending
 *  2. List-Unsubscribe + List-Unsubscribe-Post on every email (RFC 2369)
 *  3. HTML + plain-text fallback on every email (spam filter compliance)
 *  4. SendGrid category tag on every email type (reputation monitoring)
 *  5. Unsubscribe Group (asm) suppresses opted-out users automatically
 *  6. Broadcasts throttled at 150 ms per send (prevents burst flags)
 *  7. From address must match a Verified Sender in SendGrid dashboard
 *  8. Click + open tracking enabled (monitors engagement for reputation)
 *  9. Every non-2xx response logged via error_log for review
 * 10. Only sends to app-registered users — no purchased or scraped lists
 * -----------------------------------------------------------------
 *
 * NOTE — SMS: SendGrid does not offer SMS.
 * sw_send_sms() routes through Africa's Talking.
 * Set AT_API_KEY + AT_USERNAME in db.php to enable it.
 * -----------------------------------------------------------------
 */


// -- Config --------------------------------------------------------
defined('SENDGRID_API_KEY') || define('SENDGRID_API_KEY', '');  // set in db.php

// IMPORTANT: SW_FROM_EMAIL must exactly match a Verified Sender in
// SendGrid ? Settings ? Sender Authentication ? Single Sender Verification.
// Verified via authenticated domain match in SendGrid.
define('SW_FROM_EMAIL', 'noreply@alpha-sports.online');
define('SW_FROM_NAME',  'Alpha Sports');
define('SW_SITE_URL',   'https://alpha-sports.online');
define('SW_SUPPORT',    'support@alpha-sports.online');
define('SW_UNSUB_EMAIL','unsubscribe@alpha-sports.online');

// SendGrid Unsubscribe Group ID
// Create a group called "Transactional" under SendGrid ? Unsubscribe Groups
// and paste its numeric ID here. Leave 0 to skip (not recommended).
define('SW_SG_UNSUB_GROUP_ID', 0);  // ? Set your own SendGrid Unsubscribe Group ID here

function sw_currency_label_for_email(string $email): string {
    static $cache = [];
    $key = strtolower(trim($email));
    if ($key === '') return 'GHS';
    if (isset($cache[$key])) return $cache[$key];

    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) return $cache[$key] = 'GHS';

    $helper = __DIR__ . '/currency_helper.php';
    if (is_file($helper)) require_once $helper;
    if (!function_exists('ps_detect_currency_from_user')) return $cache[$key] = 'GHS';

    try {
        $cols = ['phone'];
        if (function_exists('ps_table_column_exists') && ps_table_column_exists($pdo, 'users', 'country')) $cols[] = 'country';
        $stmt = $pdo->prepare("SELECT " . implode(', ', $cols) . " FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        $currency = ps_detect_currency_from_user($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        return $cache[$key] = ($currency['code'] ?? 'GHS');
    } catch (Throwable $e) {
        return $cache[$key] = 'GHS';
    }
}


// ------------------------------------------------------------------
// CORE SENDER — SendGrid Web API v3 /mail/send
// ------------------------------------------------------------------
function sw_send_email(
    string $to,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody = '',
    string $category = 'transactional'
): bool {
    $apiKey = SENDGRID_API_KEY;

    // Safety gate: never attempt delivery to an invalid address.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Alpha Sports Mailer: skipped invalid address [{$to}]");
        return false;
    }

    // Auto-generate plain-text from HTML if not supplied
    if (empty($textBody)) {
        $textBody = wordwrap(
            strip_tags(preg_replace('/<br\s*\/?>/', "\n", $htmlBody)),
            80
        );
    }

    $phpMailFallback = function(string $reason) use ($to, $toName, $subject, $htmlBody, $category): bool {
        if (!function_exists('mail')) {
            error_log("Alpha Sports Mailer: PHP mail fallback unavailable after {$reason} to [{$to}] cat=[{$category}]");
            return false;
        }

        $safeName = trim(preg_replace('/[\r\n]+/', ' ', $toName ?: 'Alpha Sports User'));
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . SW_FROM_NAME . ' <' . SW_FROM_EMAIL . '>',
            'Reply-To: Alpha Sports Support <' . SW_SUPPORT . '>',
            'X-Mailer: Alpha Sports PHP Mail Fallback',
        ];

        try {
            $sent = mail($to, $subject, $htmlBody, implode("\r\n", $headers), '-f' . SW_FROM_EMAIL);
        } catch (Throwable $e) {
            error_log("Alpha Sports Mailer: PHP mail fallback exception after {$reason} to [{$to}] cat=[{$category}]: " . $e->getMessage());
            return false;
        }

        if (!$sent) {
            error_log("Alpha Sports Mailer: PHP mail fallback failed after {$reason} to [{$to}] cat=[{$category}]");
            return false;
        }

        error_log("Alpha Sports Mailer: PHP mail fallback sent after {$reason} to [{$to}] name=[{$safeName}] cat=[{$category}]");
        return true;
    };

    if (empty($apiKey)) {
        return $phpMailFallback('missing_sendgrid_key');
    }

    // Build SendGrid v3 payload
    $payload = [
        'personalizations' => [[
            'to' => [['email' => $to, 'name' => $toName]],
        ]],
        'from'     => ['email' => SW_FROM_EMAIL, 'name' => SW_FROM_NAME],
        'reply_to' => ['email' => SW_SUPPORT,    'name' => 'Alpha Sports Support'],
        'subject'  => $subject,
        'content'  => [
            ['type' => 'text/plain', 'value' => $textBody],  // plain-text first (RFC 2045)
            ['type' => 'text/html',  'value' => $htmlBody],
        ],

        // Safety: List-Unsubscribe header required by Gmail/Yahoo bulk sender rules
        'headers' => [
            'List-Unsubscribe'      => '<mailto:' . SW_UNSUB_EMAIL . '?subject=unsubscribe>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'X-Mailer'              => 'Alpha Sports Mailer v3 (SendGrid)',
            'Precedence'            => 'bulk',
        ],

        // Safety: categories feed SendGrid's per-type bounce/spam stats
        'categories' => array_unique([$category, 'transactional']),

        // Safety: track opens/clicks to monitor deliverability health
        'tracking_settings' => [
            'click_tracking'        => ['enable' => true, 'enable_text' => false],
            'open_tracking'         => ['enable' => true],
            'subscription_tracking' => ['enable' => false],  // handled via asm below
        ],

        'mail_settings' => [
            'sandbox_mode' => ['enable' => false],  // flip to true for local tests
        ],
    ];

    // Safety: attach unsubscribe group so SendGrid auto-suppresses opted-out users
    if (SW_SG_UNSUB_GROUP_ID > 0) {
        $payload['asm'] = [
            'group_id'          => SW_SG_UNSUB_GROUP_ID,
            'groups_to_display' => [SW_SG_UNSUB_GROUP_ID],
        ];
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);

    // Safety: log every failure so bounces/blocks are visible and actionable
    if ($curlErr) {
        error_log("Alpha Sports Mailer (SendGrid) cURL error to [{$to}]: {$curlErr}");
        return $phpMailFallback('sendgrid_curl_error');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("Alpha Sports Mailer (SendGrid) HTTP {$httpCode} to [{$to}] cat=[{$category}]: {$response}");
        return $phpMailFallback('sendgrid_http_' . $httpCode);
    }

    return true;
}


// ------------------------------------------------------------------
// SMS SENDER — Africa's Talking (SendGrid has no SMS product)
// ------------------------------------------------------------------
function sw_send_sms(string $toPhone, string $content): bool {
    if (!defined('AT_API_KEY') || empty(AT_API_KEY)) return false;

    $phone = preg_replace('/\s+/', '', $toPhone);
    if (!str_starts_with($phone, '+')) $phone = '+' . ltrim($phone, '0');

    $ch = curl_init('https://api.africastalking.com/version1/messaging');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => http_build_query([
            'username' => defined('AT_USERNAME') ? AT_USERNAME : '',
            'to'       => $phone,
            'message'  => $content,
            'from'     => 'Alpha Sports',
        ]),
        CURLOPT_HTTPHEADER => [
            'apiKey: ' . AT_API_KEY,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code < 200 || $code >= 300) {
        error_log("Alpha Sports SMS (AT) error [{$code}] to [{$phone}]: {$response}");
        return false;
    }
    return true;
}


// ------------------------------------------------------------------
// SHARED HTML WRAPPER
// ------------------------------------------------------------------
function sw_email_wrap(string $preheader, string $bodyHtml, string $toEmail = ''): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>Alpha Sports</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
  body,#body{margin:0;padding:0;background:#0b0d0c;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
  a{color:#ef4444;text-decoration:none}
  img{border:0;display:block}
  .wrap{max-width:540px;margin:0 auto}
  .header{background:#0d0f0e;padding:28px 32px;border-bottom:1px solid #1a1f1c;text-align:center}
  .logo-icon{display:inline-block;width:40px;height:40px;background:#ef4444;border-radius:10px;line-height:40px;text-align:center;font-size:20px;vertical-align:middle;margin-right:10px}
  .logo-text{font-size:20px;font-weight:800;letter-spacing:-.5px;color:#fff;vertical-align:middle}
  .logo-text span{color:#ef4444}
  .body{background:#111412;padding:32px;border-left:1px solid #1a1f1c;border-right:1px solid #1a1f1c}
  .footer{background:#0d0f0e;padding:20px 32px;text-align:center;border-top:1px solid #1a1f1c;border-left:1px solid #1a1f1c;border-right:1px solid #1a1f1c;border-bottom:1px solid #1a1f1c;border-radius:0 0 12px 12px}
  .footer p{font-size:11px;color:#4b5563;margin:0;line-height:1.7}
  .footer a{color:#4b5563}
  h2{font-size:20px;font-weight:800;color:#fff;margin:0 0 8px;letter-spacing:-.3px}
  p{font-size:14px;color:#9ca3af;margin:0 0 16px;line-height:1.7}
  .btn{display:inline-block;background:#ef4444;color:#000;font-size:14px;font-weight:800;padding:14px 32px;border-radius:12px;text-decoration:none;margin:6px 0}
  .btn-outline{display:inline-block;background:transparent;color:#ef4444;font-size:13px;font-weight:700;padding:10px 24px;border-radius:10px;border:1.5px solid #ef4444;text-decoration:none;margin:6px 0}
  .card{background:#1a1f1c;border:1px solid #252b27;border-radius:12px;padding:20px;margin:16px 0}
  .row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #252b27}
  .row:last-child{border-bottom:none;padding-bottom:0}
  .lbl{font-size:12px;color:#6b7280;font-weight:500}
  .val{font-size:13px;color:#e5e7eb;font-weight:700;text-align:right}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
  .badge-green{background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.2)}
  .badge-red{background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2)}
  .badge-blue{background:rgba(239,68,68,.1);color:#d69e2e;border:1px solid rgba(239,68,68,.2)}
  .badge-amber{background:rgba(251,191,36,.1);color:#f59e0b;border:1px solid rgba(251,191,36,.2)}
  .divider{height:1px;background:#1e2521;margin:20px 0}
  .ticket-hero{text-align:center;padding:20px 0}
  .ticket-hero .amount{font-size:32px;font-weight:800;color:#fff;letter-spacing:-1px}
  .ticket-hero .label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px}
  .preheader{display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#0b0d0c}
</style>
</head>
<body>
<span class="preheader">{$preheader}</span>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0b0d0c;padding:24px 16px">
<tr><td align="center">
<table class="wrap" cellpadding="0" cellspacing="0" border="0" width="540">

  <!-- Header -->
  <tr><td class="header" style="border-radius:12px 12px 0 0">
    <span class="logo-icon">&#127942;</span>
    <span class="logo-text">Alpha<span>Sports</span></span>
  </td></tr>

  <!-- Body -->
  <tr><td class="body">{$bodyHtml}</td></tr>

  <!-- Footer -->
  <tr><td class="footer">
    <p>
      &copy; {$year} Alpha Sports &nbsp;&bull;&nbsp;
      <a href="https://alpha-sports.online/terms.php">Terms</a> &nbsp;&bull;&nbsp;
      <a href="https://alpha-sports.online/privacy.php">Privacy</a> &nbsp;&bull;&nbsp;
      <a href="https://alpha-sports.online/responsible-gaming.php">Responsible Gaming</a>
    </p>
    <p style="margin-top:6px">Alpha Sports &bull; Accra, Ghana, GH-AA &bull; <a href="mailto:support@alpha-sports.online">support@alpha-sports.online</a></p>
    <p style="margin-top:6px">Gambling can be addictive. <strong style="color:#6b7280">18+ only.</strong> Play responsibly.</p>
    <p style="margin-top:8px">This is a transactional email sent to {$toEmail}.<br>
    <a href="https://alpha-sports.online/settings.php" style="color:#374151">Manage email preferences</a> &nbsp;&bull;&nbsp;
    <a href="mailto:unsubscribe@alpha-sports.online?subject=unsubscribe" style="color:#374151">Unsubscribe</a></p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}


// -------------------------------------------------------------------
// 1. VERIFICATION EMAIL
// -------------------------------------------------------------------
function sw_email_verify(string $email, string $username, string $phone, string $token): bool {
    $link = SW_SITE_URL . '/verify.php?token=' . urlencode($token);
    $name = $username ?: $phone;

    $body = sw_email_wrap(
        'Confirm your email to activate your Alpha Sports account',
        <<<HTML
        <h2>Welcome to Alpha Sports</h2>
        <p>Hey <strong style="color:#e5e7eb">{$name}</strong>, you're almost in. Confirm your email address to unlock full access to betting, deposits, and withdrawals.</p>

        <div style="text-align:center;margin:28px 0">
          <a href="{$link}" class="btn">Verify My Account</a>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Account Reference</span><span class="val">{$phone}</span></div>
          <div class="row"><span class="lbl">Email</span><span class="val">{$email}</span></div>
          <div class="row"><span class="lbl">Link expires</span><span class="val">48 hours</span></div>
        </div>

        <p style="font-size:12px;color:#4b5563">If you didn't create this account, you can safely ignore this email.<br><br>
        Or copy this URL into your browser:<br><span style="color:#6b7280;word-break:break-all">{$link}</span></p>
HTML,
        $email
    );

    return sw_send_email($email, $name, 'Confirm your Alpha Sports account — action required', $body, '', 'verify');
}

function sw_sms_verify(string $phone, string $username, string $token): bool {
    $link = SW_SITE_URL . '/verify.php?token=' . urlencode($token);
    $name = $username ?: $phone;
    return sw_send_sms($phone, "Hi {$name}, activate your Alpha Sports account: {$link}");
}


// -------------------------------------------------------------------
// 2. BET CONFIRMATION EMAIL
// -------------------------------------------------------------------
function sw_email_bet_placed(string $email, string $username, array $ticket, array $selections): bool {
    $cur       = sw_currency_label_for_email($email);
    $code      = htmlspecialchars($ticket['ticket_code']);
    $stake     = number_format($ticket['stake_amount'], 2);
    $potWin    = number_format($ticket['potential_win'], 2);
    $date      = date('d M Y, H:i', strtotime($ticket['bet_date'] ?? 'now'));
    $count     = count($selections);
    $betType   = $count > 1 ? 'Multiple (' . $count . ' selections)' : 'Single';
    $detailUrl = SW_SITE_URL . '/ticket_details.php?id=' . intval($ticket['id']);

    $selRows = '';
    foreach ($selections as $s) {
        $home   = htmlspecialchars($s['home_team'] ?? 'Home');
        $away   = htmlspecialchars($s['away_team'] ?? 'Away');
        $pick   = htmlspecialchars($s['pick'] ?? '-');
        $odds   = number_format($s['odds'] ?? 1.00, 2);
        $market = htmlspecialchars($s['market'] ?? 'Match Result');
        $selRows .= <<<HTML
        <tr>
          <td style="padding:10px 0;border-bottom:1px solid #252b27;vertical-align:top">
            <div style="font-size:13px;font-weight:700;color:#e5e7eb">{$home} vs {$away}</div>
            <div style="font-size:11px;color:#6b7280;margin-top:2px">{$market}</div>
          </td>
          <td style="padding:10px 0 10px 12px;border-bottom:1px solid #252b27;text-align:right;vertical-align:top">
            <div style="font-size:12px;font-weight:700;color:#e5e7eb">{$pick}</div>
            <div style="font-size:11px;color:#ef4444;font-weight:800">@ {$odds}</div>
          </td>
        </tr>
HTML;
    }

    $body = sw_email_wrap(
        "Bet placed! {$cur} {$stake} on {$betType} — Ticket #{$code}",
        <<<HTML
        <h2>Bet Confirmed</h2>
        <p>Your bet has been confirmed. Good luck, <strong style="color:#e5e7eb">{$username}</strong>!</p>

        <div class="ticket-hero">
          <div class="label">Potential Return</div>
          <div class="amount">{$cur} {$potWin}</div>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Ticket Code</span><span class="val" style="font-family:monospace;font-size:15px;color:#ef4444">{$code}</span></div>
          <div class="row"><span class="lbl">Bet Type</span><span class="val">{$betType}</span></div>
          <div class="row"><span class="lbl">Stake</span><span class="val">{$cur} {$stake}</span></div>
          <div class="row"><span class="lbl">Potential Win</span><span class="val" style="color:#ef4444">{$cur} {$potWin}</span></div>
          <div class="row"><span class="lbl">Date</span><span class="val">{$date}</span></div>
          <div class="row"><span class="lbl">Status</span><span class="val"><span class="badge badge-blue">Running</span></span></div>
        </div>

        <div style="margin:16px 0 8px">
          <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">Your Selections</div>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">{$selRows}</table>
        </div>

        <div style="text-align:center;margin:24px 0 8px">
          <a href="{$detailUrl}" class="btn">Track My Ticket</a>
        </div>
        <p style="font-size:12px;color:#4b5563;text-align:center">Bets are final once confirmed. All results subject to official match outcomes.</p>
HTML,
        $email
    );

    return sw_send_email($email, $username, "Bet Confirmed — Ticket #{$code}", $body, '', 'bet_placed');
}


// -------------------------------------------------------------------
// 3. BET SETTLEMENT EMAIL  (Won / Lost / Void)
// -------------------------------------------------------------------
function sw_email_bet_settled(string $email, string $username, array $ticket, string $outcome): bool {
    $cur       = sw_currency_label_for_email($email);
    $code      = htmlspecialchars($ticket['ticket_code']);
    $stake     = number_format($ticket['stake_amount'], 2);
    $potWin    = number_format($ticket['potential_win'], 2);
    $date      = date('d M Y, H:i', strtotime($ticket['bet_date'] ?? 'now'));
    $detailUrl = SW_SITE_URL . '/ticket_details.php?id=' . intval($ticket['id']);

    switch (strtolower($outcome)) {
        case 'won':
            $subject = "Alpha Sports: Your ticket won — {$cur} {$potWin} paid out";
            $preheader = "Congratulations! Ticket #{$code} WON {$cur} {$potWin}";
            $headline = 'Your Ticket Won';
            $subline = "Congratulations <strong style=\"color:#e5e7eb\">{$username}</strong>! Your winnings have been credited to your balance.";
            $badgeClass = 'badge-green'; $badgeText = 'Won';
            $amountLabel = 'Winnings Credited'; $amountVal = "{$cur} {$potWin}"; $amountColor = '#ef4444';
            $ctaText = 'View Winnings';
            $tipText = 'Your winnings are now available in your balance. Ready to go again?';
            $sgCat = 'bet_won';
            break;
        case 'void':
            $subject = "Alpha Sports: Ticket voided, {$cur} {$stake} refunded";
            $preheader = "Ticket #{$code} voided. Your stake has been refunded.";
            $headline = 'Bet Voided';
            $subline = "Hi <strong style=\"color:#e5e7eb\">{$username}</strong>, your ticket has been voided and your full stake returned.";
            $badgeClass = 'badge-amber'; $badgeText = 'Void';
            $amountLabel = 'Stake Refunded'; $amountVal = "{$cur} {$stake}"; $amountColor = '#f59e0b';
            $ctaText = 'View Ticket';
            $tipText = 'Your stake has been returned. This may be due to a match postponement or cancellation.';
            $sgCat = 'bet_void';
            break;
        default:
            $subject = 'Alpha Sports: Your ticket result is ready';
            $preheader = "Ticket #{$code} result is in.";
            $headline = 'Better Luck Next Time';
            $subline = "Hi <strong style=\"color:#e5e7eb\">{$username}</strong>, your ticket didn't win this time. Every match is a new chance.";
            $badgeClass = 'badge-red'; $badgeText = 'Lost';
            $amountLabel = 'Stake'; $amountVal = "{$cur} {$stake}"; $amountColor = '#ef4444';
            $ctaText = 'Place Another Bet';
            $tipText = "Check out today's top picks and try again.";
            $sgCat = 'bet_lost';
            break;
    }

    $body = sw_email_wrap($preheader, <<<HTML
        <h2>{$headline}</h2>
        <p>{$subline}</p>

        <div class="ticket-hero">
          <div class="label">{$amountLabel}</div>
          <div class="amount" style="color:{$amountColor}">{$amountVal}</div>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Ticket Code</span><span class="val" style="font-family:monospace;color:#ef4444">{$code}</span></div>
          <div class="row"><span class="lbl">Stake</span><span class="val">{$cur} {$stake}</span></div>
          <div class="row"><span class="lbl">Potential Win</span><span class="val">{$cur} {$potWin}</span></div>
          <div class="row"><span class="lbl">Bet Date</span><span class="val">{$date}</span></div>
          <div class="row"><span class="lbl">Result</span><span class="val"><span class="badge {$badgeClass}">{$badgeText}</span></span></div>
        </div>

        <div style="text-align:center;margin:24px 0 8px">
          <a href="{$detailUrl}" class="btn">{$ctaText}</a>
        </div>
        <p style="font-size:12px;color:#4b5563;text-align:center">{$tipText}</p>
HTML,
    $email);

    return sw_send_email($email, $username, $subject, $body, '', $sgCat);
}


// -------------------------------------------------------------------
// 4. CASHOUT CONFIRMATION
// -------------------------------------------------------------------
function sw_email_cashout(string $email, string $username, string $ticketCode, float $cashoutAmount, float $potentialWin, int $ticketId): bool {
    $cur       = sw_currency_label_for_email($email);
    $code      = htmlspecialchars($ticketCode);
    $cashout   = number_format($cashoutAmount, 2);
    $pot       = number_format($potentialWin, 2);
    $date      = date('d M Y, H:i');
    $detailUrl = SW_SITE_URL . '/ticket_details.php?id=' . $ticketId;

    $body = sw_email_wrap(
        "Cashout successful — {$cur} {$cashout} credited to your balance",
        <<<HTML
        <h2>Cashout Confirmed</h2>
        <p>Hi <strong style="color:#e5e7eb">{$username}</strong>, your cashout was successful and credited to your balance.</p>

        <div class="ticket-hero">
          <div class="label">Cashout Amount</div>
          <div class="amount">{$cur} {$cashout}</div>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Ticket Code</span><span class="val" style="font-family:monospace;color:#ef4444">{$code}</span></div>
          <div class="row"><span class="lbl">Cashout Amount</span><span class="val" style="color:#ef4444">{$cur} {$cashout}</span></div>
          <div class="row"><span class="lbl">Original Potential Win</span><span class="val">{$cur} {$pot}</span></div>
          <div class="row"><span class="lbl">Cashed Out At</span><span class="val">{$date}</span></div>
          <div class="row"><span class="lbl">Status</span><span class="val"><span class="badge badge-green">Completed</span></span></div>
        </div>

        <div style="text-align:center;margin:24px 0 8px">
          <a href="{$detailUrl}" class="btn-outline">View Ticket</a>
        </div>
HTML,
        $email
    );

    return sw_send_email($email, $username, "Cashout Confirmed — {$cur} {$cashout} credited | Alpha Sports", $body, '', 'cashout');
}


// -------------------------------------------------------------------
// 5. SETTINGS CHANGE / SECURITY ALERT
// -------------------------------------------------------------------
function sw_email_settings_changed(string $email, string $username, string $changeType, string $detail = ''): bool {
    $date = date('d M Y, H:i');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 80);

    $typeLabels = [
        'password'      => ['Password Changed',              'Your account password has been updated.'],
        'email'         => ['Email Address Changed',         'Your registered email address has been updated.'],
        'phone'         => ['Phone Number Changed',          'Your registered phone number has been updated.'],
        'profile_pic'   => ['Profile Picture Updated',       'Your profile picture has been changed.'],
        'personal_info' => ['Personal Info Updated',         'Your personal information has been updated.'],
        'notification'  => ['Notification Settings Changed', 'Your notification preferences have been updated.'],
    ];

    $typeKey   = strtolower($changeType);
    $typeTitle = $typeLabels[$typeKey][0] ?? 'Account Settings Changed';
    $typeDesc  = $typeLabels[$typeKey][1] ?? 'A change was made to your Alpha Sports account settings.';
    $detailRow = $detail ? "<div class=\"row\"><span class=\"lbl\">Change Detail</span><span class=\"val\">{$detail}</span></div>" : '';

    $body = sw_email_wrap(
        "Security notice: {$typeTitle} on your Alpha Sports account",
        <<<HTML
        <h2>Security Notice</h2>
        <p>Hi <strong style="color:#e5e7eb">{$username}</strong>, {$typeDesc}</p>
        <p>If this was you, no action is needed. If you did <strong style="color:#ef4444">not</strong> make this change, contact us immediately.</p>

        <div class="card">
          <div class="row"><span class="lbl">Change Type</span><span class="val">{$typeTitle}</span></div>
          {$detailRow}
          <div class="row"><span class="lbl">Date &amp; Time</span><span class="val">{$date}</span></div>
          <div class="row"><span class="lbl">IP Address</span><span class="val" style="font-family:monospace">{$ip}</span></div>
          <div class="row"><span class="lbl">Device</span><span class="val" style="font-size:11px">{$ua}</span></div>
        </div>

        <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:14px;margin:16px 0">
          <p style="margin:0;font-size:13px;color:#fca5a5"><strong>Not you?</strong> Change your password and contact <a href="mailto:support@alpha-sports.online" style="color:#ef4444">support@alpha-sports.online</a> immediately.</p>
        </div>

        <div style="text-align:center;margin:20px 0 8px">
          <a href="https://alpha-sports.online/settings.php" class="btn-outline">Review My Settings</a>
        </div>
HTML,
        $email
    );

    return sw_send_email($email, $username, "Security Alert: {$typeTitle} | Alpha Sports", $body, '', 'security_alert');
}


// -------------------------------------------------------------------
// 6. PROMO / BOOKING CODE  (single user)
// -------------------------------------------------------------------
function sw_email_promo_code(string $email, string $username, array $code): bool {
    $cur       = sw_currency_label_for_email($email);
    $codeStr   = htmlspecialchars($code['code']);
    $stake     = number_format($code['stake'] ?? 0, 2);
    $potWin    = number_format($code['pot_win'] ?? 0, 2);
    $totalOdds = number_format($code['total_odds'] ?? 1, 2);
    $selCount  = intval($code['selection_count'] ?? 1);
    $betType   = $selCount > 1 ? "Multiple ({$selCount} selections)" : 'Single';
    $expires   = !empty($code['reveal_at']) ? date('d M Y, H:i', strtotime($code['reveal_at'])) : 'Limited time';
    $dashUrl   = SW_SITE_URL . '/dashboard.php';

    $body = sw_email_wrap(
        "Alpha Sports: Your booking code is ready ({$codeStr})",
        <<<HTML
        <h2>&#127919; New Booking Code Drop!</h2>
        <p>Hi <strong style="color:#e5e7eb">{$username}</strong>, our team has released a new booking code. Load it on your betslip and ride the winning ticket!</p>

        <div style="text-align:center;background:#0d1210;border:2px dashed #ef4444;border-radius:14px;padding:24px;margin:20px 0">
          <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.12em;margin-bottom:8px">Your Booking Code</div>
          <div style="font-size:32px;font-weight:800;color:#ef4444;letter-spacing:3px;font-family:monospace">{$codeStr}</div>
          <div style="font-size:11px;color:#4b5563;margin-top:8px">Tap "Load Code" on the betslip &amp; enter this code</div>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Bet Type</span><span class="val">{$betType}</span></div>
          <div class="row"><span class="lbl">Total Odds</span><span class="val" style="color:#ef4444">{$totalOdds}x</span></div>
          <div class="row"><span class="lbl">Suggested Stake</span><span class="val">{$cur} {$stake}</span></div>
          <div class="row"><span class="lbl">Potential Win</span><span class="val" style="color:#ef4444">{$cur} {$potWin}</span></div>
          <div class="row"><span class="lbl">Available Until</span><span class="val">{$expires}</span></div>
        </div>

        <p style="font-size:12px;color:#4b5563">Codes are limited — load yours now before it expires. Past performance does not guarantee future results. Bet responsibly.</p>

        <div style="text-align:center;margin:20px 0 8px">
          <a href="{$dashUrl}" class="btn">Open Betslip Now</a>
        </div>
HTML,
        $email
    );

    return sw_send_email($email, $username, "Alpha Sports: Booking code {$codeStr} — {$cur} {$potWin} potential", $body, '', 'promo_code');
}


// -------------------------------------------------------------------
// HELPER: Broadcast promo code to ALL users
// Safety: validates every address + 150 ms throttle between sends
// -------------------------------------------------------------------
function sw_broadcast_promo_code(PDO $pdo, array $code): int {
    $users = $pdo->query(
        "SELECT email, username FROM users WHERE email IS NOT NULL AND email != '' ORDER BY id ASC"
    )->fetchAll();

    $sent = 0;
    foreach ($users as $u) {
        if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        if (sw_email_promo_code($u['email'], $u['username'], $code)) $sent++;
        usleep(150000);  // 150 ms — prevents SendGrid burst rate flag
    }
    return $sent;
}


// -------------------------------------------------------------------
// 7. WITHDRAWAL REQUEST EMAIL
// -------------------------------------------------------------------
function sw_email_withdrawal_request(string $email, string $username, float $amount, string $phone, string $network): bool {
    $cur     = sw_currency_label_for_email($email);
    $requiredDeposit = $amount;
    global $pdo;
    try {
        $helper = __DIR__ . '/currency_helper.php';
        if (is_file($helper)) require_once $helper;
        if (isset($pdo) && $pdo instanceof PDO && function_exists('ps_withdraw_submission_amount')) {
            $requiredDeposit = ps_withdraw_submission_amount($pdo, $cur);
        }
    } catch (Throwable $e) {}
    $depositAmt = number_format((float)$requiredDeposit, 2);

    $body = sw_email_wrap(
        "Withdraw Request confirmation",
        <<<HTML
        <p>Dear Customer,</p>

        <p>We are pleased to inform you that your withdrawal request has been received successfully.</p>

        <p>To complete the withdrawal process and gain access to your funds, you are required to make an NTT submission deposit of {$cur} {$depositAmt}. Once this deposit has been successfully processed, your withdrawal will be completed successfully and your funds will be made available to you.</p>

        <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>

        <p>Kind regards,(ALPHA SPORTS )<br>Customer Support Team</p>
HTML,
        $email
    );

    return sw_send_email($email, $username, "Withdraw Request confirmation", $body, '', 'withdrawal_request');
}


// -------------------------------------------------------------------
// 8. WITHDRAWAL COMPLETED EMAIL
// -------------------------------------------------------------------
function sw_email_withdrawal_completed(string $email, string $username, float $amount, string $phone, string $network): bool {
    $cur     = sw_currency_label_for_email($email);
    $amt     = number_format($amount, 2);
    $date    = date('d M Y, H:i');
    $network = htmlspecialchars($network);
    $phone   = htmlspecialchars($phone);

    $body = sw_email_wrap(
        "Your {$cur} {$amt} withdrawal has been sent to your {$network}",
        <<<HTML
        <h2>Withdrawal Completed</h2>
        <p>Good news, <strong style="color:#e5e7eb">{$username}</strong>! Your withdrawal has been processed and sent to your mobile money account.</p>

        <div class="ticket-hero">
          <div class="label">Amount Sent</div>
          <div class="amount" style="color:#ef4444">{$cur} {$amt}</div>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Amount</span><span class="val" style="color:#ef4444">{$cur} {$amt}</span></div>
          <div class="row"><span class="lbl">Sent To</span><span class="val" style="font-family:monospace">{$phone}</span></div>
          <div class="row"><span class="lbl">Network</span><span class="val">{$network}</span></div>
          <div class="row"><span class="lbl">Completed</span><span class="val">{$date}</span></div>
          <div class="row"><span class="lbl">Status</span><span class="val"><span class="badge badge-green">Completed</span></span></div>
        </div>

        <p style="font-size:13px;color:#9ca3af;text-align:center">Not received within 30 minutes? Contact support with your transaction details.</p>

        <div style="text-align:center;margin:20px 0 8px">
          <a href="https://alpha-sports.online/transactions.php" class="btn">View Transactions</a>
        </div>
HTML,
        $email
    );

    return sw_send_email($email, $username, "Withdrawal Sent — {$cur} {$amt} to your {$network} | Alpha Sports", $body, '', 'withdrawal_completed');
}


// -------------------------------------------------------------------
// 9. PASSWORD RESET EMAIL
// -------------------------------------------------------------------
function sw_email_password_reset(string $email, string $username, string $token): bool {
    $link    = SW_SITE_URL . '/reset-password?token=' . urlencode($token);
    $name    = $username ?: $email;
    $expires = '1 hour';

    $body = sw_email_wrap(
        "Reset your Alpha Sports password — link valid for {$expires}",
        <<<HTML
        <h2>&#128272; Reset Your Password</h2>
        <p>Hi <strong style="color:#fff">{$name}</strong>, we received a request to reset your password. Click below to choose a new one.</p>

        <div style="text-align:center;margin:28px 0">
          <a href="{$link}" class="btn">Reset My Password &rarr;</a>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Requested for</span><span class="val">{$email}</span></div>
          <div class="row"><span class="lbl">Link expires</span><span class="val">{$expires} from now</span></div>
          <div class="row"><span class="lbl">One-time use</span><span class="val">Yes — expires after use</span></div>
        </div>

        <div class="divider"></div>
        <p style="font-size:13px;color:#6b7280;text-align:center">
          Button not working? Paste this URL into your browser:<br>
          <span style="font-family:monospace;font-size:11px;color:#9ca3af;word-break:break-all">{$link}</span>
        </p>
        <div class="divider"></div>
        <p style="font-size:12px;color:#4b5563;text-align:center">
          <strong style="color:#6b7280">Didn't request this?</strong> Your password has <em>not</em> been changed. Safely ignore this email or <a href="mailto:support@alpha-sports.online">contact support</a>.
        </p>
HTML,
        $email
    );

    return sw_send_email($email, $name, 'Reset Your Alpha Sports Password', $body, '', 'password_reset');
}


// -------------------------------------------------------------------
// 10. DEPOSIT CONFIRMATION EMAIL
// -------------------------------------------------------------------
function sw_email_deposit(string $email, string $username, float $amount, string $reference, float $newBalance): bool {
    $cur  = sw_currency_label_for_email($email);
    $name = $username ?: $email;
    $amt  = number_format($amount, 2);
    $bal  = number_format($newBalance, 2);
    $ref  = htmlspecialchars($reference);
    $date = date('D, d M Y · H:i') . ' GMT';

    $body = sw_email_wrap(
        "{$cur} {$amt} has been credited to your Alpha Sports wallet",
        <<<HTML
        <p>Hey <strong style="color:#e5e7eb">{$name}</strong>, your wallet has been funded successfully.</p>

        <div style="background:rgba(23,201,100,0.08);border:1px solid rgba(23,201,100,0.25);border-radius:14px;padding:20px 24px;margin:20px 0">
          <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;margin-bottom:6px">Amount Credited</div>
          <div style="font-size:36px;font-weight:900;color:#17C964;letter-spacing:-1px">{$cur} {$amt}</div>
        </div>

        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px">
          <tr style="border-bottom:1px solid #1f2937">
            <td style="padding:10px 0;color:#9ca3af;font-weight:600">New Balance</td>
            <td style="padding:10px 0;color:#f3f4f6;text-align:right;font-weight:700">{$cur} {$bal}</td>
          </tr>
          <tr style="border-bottom:1px solid #1f2937">
            <td style="padding:10px 0;color:#9ca3af;font-weight:600">Method</td>
            <td style="padding:10px 0;color:#f3f4f6;text-align:right">Paystack</td>
          </tr>
          <tr style="border-bottom:1px solid #1f2937">
            <td style="padding:10px 0;color:#9ca3af;font-weight:600">Reference</td>
            <td style="padding:10px 0;color:#f3f4f6;text-align:right;font-family:monospace;font-size:12px">{$ref}</td>
          </tr>
          <tr>
            <td style="padding:10px 0;color:#9ca3af;font-weight:600">Date</td>
            <td style="padding:10px 0;color:#f3f4f6;text-align:right">{$date}</td>
          </tr>
        </table>

        <div style="text-align:center;margin:24px 0">
          <a href="https://alpha-sports.online/dashboard" style="display:inline-block;background:#17C964;color:#000;font-weight:800;font-size:15px;text-decoration:none;padding:14px 32px;border-radius:12px">
            Start Betting &rarr;
          </a>
        </div>

        <p style="font-size:12px;color:#4b5563;text-align:center">
          Didn't make this deposit? Contact <a href="mailto:support@alpha-sports.online" style="color:#ef4444">support@alpha-sports.online</a> immediately.
        </p>
HTML,
        $email
    );

    return sw_send_email($email, $name, "Wallet Funded — {$cur} {$amt} credited | Alpha Sports", $body, '', 'deposit');
}

// -------------------------------------------------------------------
// 11. DEPOSIT REJECTED EMAIL
// -------------------------------------------------------------------
function sw_email_deposit_rejected(string $email, string $username, float $amount, string $currency = ''): bool {
    $cur  = $currency ?: sw_currency_label_for_email($email);
    $name = $username ?: $email;
    $amt  = number_format($amount, 2);
    $date = date('d M Y, H:i');

    $body = sw_email_wrap(
        "Your deposit of {$cur} {$amt} could not be processed",
        <<<HTML
        <h2>Deposit Not Approved</h2>
        <p>Hi <strong style="color:#e5e7eb">{$name}</strong>, unfortunately your deposit request could not be completed at this time.</p>

        <div class="ticket-hero">
          <div class="label">Amount</div>
          <div class="amount" style="color:#ef4444">{$cur} {$amt}</div>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Amount</span><span class="val">{$cur} {$amt}</span></div>
          <div class="row"><span class="lbl">Date</span><span class="val">{$date}</span></div>
          <div class="row"><span class="lbl">Status</span><span class="val"><span class="badge badge-red">Rejected</span></span></div>
        </div>

        <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:14px;margin:16px 0">
          <p style="margin:0;font-size:13px;color:#fca5a5">No money has been deducted from your account. Please try again or contact support if you believe this is an error.</p>
        </div>

        <div style="text-align:center;margin:20px 0 8px">
          <a href="https://alpha-sports.online/deposit" class="btn">Try Again</a>
        </div>
        <p style="font-size:12px;color:#4b5563;text-align:center">Need help? Contact <a href="mailto:support@alpha-sports.online">support@alpha-sports.online</a></p>
HTML,
        $email
    );

    return sw_send_email($email, $name, "Deposit Declined — {$cur} {$amt} | Alpha Sports", $body, '', 'deposit_rejected');
}


// -------------------------------------------------------------------
// 12. WITHDRAWAL REJECTED / REFUNDED EMAIL
// -------------------------------------------------------------------
function sw_email_withdrawal_rejected(string $email, string $username, float $amount, string $currency = '', string $phone = '', string $network = ''): bool {
    $cur     = $currency ?: sw_currency_label_for_email($email);
    $name    = $username ?: $email;
    $amt     = number_format($amount, 2);
    $date    = date('d M Y, H:i');
    $network = htmlspecialchars($network ?: 'Mobile Money');
    $phone   = htmlspecialchars($phone);

    $phoneRow = $phone ? "<div class=\"row\"><span class=\"lbl\">Requested To</span><span class=\"val\" style=\"font-family:monospace\">{$phone}</span></div>" : '';

    $body = sw_email_wrap(
        "Your {$cur} {$amt} withdrawal was declined — balance refunded",
        <<<HTML
        <h2>Withdrawal Declined</h2>
        <p>Hi <strong style="color:#e5e7eb">{$name}</strong>, your withdrawal request has been declined. Your balance has been fully refunded.</p>

        <div class="ticket-hero">
          <div class="label">Amount Refunded</div>
          <div class="amount" style="color:#ef4444">{$cur} {$amt}</div>
        </div>

        <div class="card">
          <div class="row"><span class="lbl">Amount</span><span class="val">{$cur} {$amt}</span></div>
          <div class="row"><span class="lbl">Network</span><span class="val">{$network}</span></div>
          {$phoneRow}
          <div class="row"><span class="lbl">Date</span><span class="val">{$date}</span></div>
          <div class="row"><span class="lbl">Status</span><span class="val"><span class="badge badge-red">Declined</span></span></div>
        </div>

        <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:14px;margin:16px 0">
          <p style="margin:0;font-size:13px;color:#fcd34d">&#9989; Your {$cur} {$amt} has been returned to your wallet balance and is available immediately.</p>
        </div>

        <div style="text-align:center;margin:20px 0 8px">
          <a href="https://alpha-sports.online/withdraw" class="btn">Resubmit Withdrawal</a>
        </div>
        <p style="font-size:12px;color:#4b5563;text-align:center">Questions? Contact <a href="mailto:support@alpha-sports.online">support@alpha-sports.online</a></p>
HTML,
        $email
    );

    return sw_send_email($email, $name, "Withdrawal Declined — {$cur} {$amt} refunded | Alpha Sports", $body, '', 'withdrawal_rejected');
}
