<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Shared outbound email transport for HealthBridge.
 *
 * The Gmail app password is stored outside the project, protected with
 * Windows DPAPI for the current Windows user. Apache and the scheduled worker
 * run under that same account on this local installation.
 */
final class HealthBridgeMailService
{
    public const SENDER_ADDRESS = 'llaunchboard@gmail.com';
    public const OPERATIONS_ADDRESS = 'llaunchboard@gmail.com';

    private const SMTP_HOST = 'smtp.gmail.com';
    private const SMTP_PORT = 465;
    private const PASSWORD_FILE = 'C:\\xampp85\\private\\healthbridge-mail\\smtp-password.dpapi';
    private const SECRET_READER = __DIR__ . '/../tools/ReadHealthBridgeMailPassword.ps1';

    /**
     * Sends one message. The caller must supply the real recipient:
     * patient emails go to the patient's address; operational alerts go to
     * OPERATIONS_ADDRESS.
     */
    public static function send(string $recipient, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email recipient is invalid.');
        }
        if (trim($subject) === '' || strlen($subject) > 180 || trim($textBody) === '') {
            throw new InvalidArgumentException('Email content is invalid.');
        }

        $password = self::readProtectedPassword();
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = self::SMTP_HOST;
            $mailer->SMTPAuth = true;
            $mailer->Username = self::SENDER_ADDRESS;
            $mailer->Password = $password;
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mailer->Port = self::SMTP_PORT;
            $mailer->Timeout = 15;
            $mailer->SMTPKeepAlive = false;
            $mailer->SMTPDebug = 0;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->isHTML($htmlBody !== null);
            $mailer->setFrom(self::SENDER_ADDRESS, 'HealthBridge');
            $mailer->addAddress($recipient);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody ?? $textBody;
            $mailer->AltBody = $textBody;

            $mailer->send();
        } catch (Throwable $error) {
            // SMTP exceptions can contain server details or message metadata.
            // Keep the application log free of credentials, addresses and body text.
            throw new RuntimeException('HealthBridge email delivery failed.');
        } finally {
            $mailer->smtpClose();
            $password = str_repeat("\0", strlen($password));
        }
    }

    public static function isConfigured(): bool
    {
        $hasLocalDpapiSecret = PHP_OS_FAMILY === 'Windows'
            && is_file(self::PASSWORD_FILE)
            && is_file(self::SECRET_READER)
            && function_exists('proc_open');

        return $hasLocalDpapiSecret || self::readEnvironmentPassword() !== null;
    }

    /**
     * Hosted runtimes may supply secrets through the platform's protected
     * environment settings. SMTP_USER is optional but, when supplied, must
     * match the one approved sender address.
     */
    private static function readEnvironmentPassword(): ?string
    {
        $smtpUser = self::environmentValue('SMTP_USER') ?? self::SENDER_ADDRESS;
        $smtpPassword = self::environmentValue('SMTP_PASS');

        if ($smtpUser !== self::SENDER_ADDRESS || $smtpPassword === null) {
            return null;
        }

        $normalized = preg_replace('/\\s+/', '', $smtpPassword);
        if (!is_string($normalized) || !preg_match('/^[A-Za-z0-9]{16}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Builds a reset URL from an explicit configured origin, never HTTP_HOST.
     * APP_BASE_URL may include a local application path such as /optimized.
     */
    public static function buildPasswordResetUrl(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new InvalidArgumentException('Password reset token is invalid.');
        }

        $baseUrl = self::environmentValue('APP_BASE_URL') ?? 'http://127.0.0.1/optimized';
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('HealthBridge application URL is invalid.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $appEnvironment = strtolower(self::environmentValue('APP_ENV') ?? 'production');
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback && $appEnvironment !== 'production')) {
            throw new RuntimeException('HealthBridge application URL must use HTTPS.');
        }

        return rtrim($baseUrl, '/') . '/pages/auth/reset-password.html?token=' . rawurlencode(strtolower($token));
    }

    public static function sendPasswordReset(string $recipient, string $token): void
    {
        $resetUrl = self::buildPasswordResetUrl($token);
        $subject = 'HealthBridge password reset';
        $text = "We received a request to reset your HealthBridge password.\n\n"
            . "Use this link within 60 minutes to choose a new password:\n"
            . $resetUrl . "\n\n"
            . "If you did not request this change, you can ignore this email. Your password will not change.";

        $html = self::buildBrandedEmailHtml(
            'Use this secure link to choose a new HealthBridge password.',
            'ACCOUNT SECURITY',
            'Reset your password',
            'We received a request to reset the password for your HealthBridge account.',
            ['If you did not request this change, you can safely ignore this message. Your password will remain unchanged.'],
            'Choose a new password',
            $resetUrl,
            'This secure link expires 60 minutes after the request. For your protection, it can only be used once.',
            'If the button does not open, copy and paste this secure link into your browser:'
        );

        self::send($recipient, $subject, $text, $html);
    }

    /**
     * Build an explicit same-origin confirmation URL. The bearer token is in
     * the fragment, which browsers do not send in the HTTP request or Apache
     * access log. The confirmation page consumes it only after a user POST.
     */
    public static function buildRegistrationVerificationUrl(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new InvalidArgumentException('Registration verification token is invalid.');
        }

        $baseUrl = self::environmentValue('APP_BASE_URL') ?? 'http://127.0.0.1/optimized';
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('HealthBridge application URL is invalid.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $appEnvironment = strtolower(self::environmentValue('APP_ENV') ?? 'production');
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback && $appEnvironment !== 'production')) {
            throw new RuntimeException('HealthBridge application URL must use HTTPS.');
        }

        return rtrim($baseUrl, '/') . '/pages/auth/verify-email.html#token=' . rawurlencode(strtolower($token));
    }

    public static function sendRegistrationVerification(string $recipient, string $token): void
    {
        $verificationUrl = self::buildRegistrationVerificationUrl($token);
        $subject = 'Verify your HealthBridge email address';
        $text = "Thank you for registering with HealthBridge.\n\n"
            . "Confirm that you own this email address by opening the link below and selecting Confirm Email.\n"
            . $verificationUrl . "\n\n"
            . "The link expires in 24 hours. Opening the link alone does not activate an account. "
            . "If you did not start this registration, you can ignore this message.";

        $html = self::buildBrandedEmailHtml(
            'One quick step: confirm your email address to complete registration.',
            'PATIENT ACCOUNT SETUP',
            'Verify your email address',
            'Thank you for choosing HealthBridge. Confirm that you own this email address to continue with your patient registration.',
            ['If you did not start this registration, you can safely ignore this message. No patient account will be created unless the email address is confirmed.'],
            'Confirm email address',
            $verificationUrl,
            'For your security, this link expires 24 hours after the original registration request. Opening the link alone does not activate an account; you must confirm on the page.',
            'If the button does not open, copy and paste this secure link into your browser:'
        );

        self::send($recipient, $subject, $text, $html);
    }

    public static function sendQueueAgeAlert(string $event, int $ageSeconds, string $firstSeenAt): void
    {
        if (!in_array($event, ['OPEN', 'REMINDER', 'RESOLVED'], true) || $ageSeconds < 0 || $ageSeconds > 2147483647) {
            throw new InvalidArgumentException('Queue alert details are invalid.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $firstSeenAt)) {
            throw new InvalidArgumentException('Queue alert timestamp is invalid.');
        }

        $resolved = $event === 'RESOLVED';
        $subject = $resolved
            ? 'HealthBridge: registration queue recovered'
            : 'HealthBridge alert: registration queue delayed';
        $statusText = $resolved
            ? 'The registration queue has returned to a healthy age.'
            : 'The oldest pending registration job has exceeded the 120-second warning threshold.';
        $text = "HealthBridge registration queue status: {$event}\n\n"
            . $statusText . "\n"
            . "Oldest pending age at this check: {$ageSeconds} seconds\n"
            . "Alert first observed: {$firstSeenAt}\n\n"
            . "This message contains operational queue metadata only. It does not contain patient identifiers or registration details.";

        $html = self::buildBrandedEmailHtml(
            'HealthBridge registration queue status: ' . $event . '.',
            'SYSTEM OPERATIONS',
            $resolved ? 'Registration queue recovered' : 'Registration queue delayed',
            $statusText,
            [
                'Oldest pending age: ' . $ageSeconds . ' seconds',
                'First observed: ' . $firstSeenAt,
            ],
            null,
            null,
            'This operational alert contains queue metadata only. No patient identifiers or registration details are included.'
        );

        self::send(self::OPERATIONS_ADDRESS, $subject, $text, $html);
    }

    /**
     * Shared email-safe HealthBridge layout, restyled to the Public V2
     * design language (Batch 2 email stage): ivory background, deep-green
     * header/footer bands echoing the site footer, Georgia serif headline
     * with a gold italic accent word, gold small-caps labels, brass
     * (#B89563) accents and the V2 teal (#17645F) call-to-action. The
     * markup uses nested presentation tables and inline styles only, with
     * bgcolor attributes forcing the light palette in dark-mode clients,
     * so it remains legible in common desktop and mobile email clients
     * without external assets. Presentation only — callers' subjects,
     * wording, links and plain-text bodies are unchanged.
     *
     * @param string[] $details Plain-text paragraphs rendered with HTML escaping.
     */
    private static function buildBrandedEmailHtml(
        string $preheader,
        string $eyebrow,
        string $heading,
        string $intro,
        array $details = [],
        ?string $buttonLabel = null,
        ?string $buttonUrl = null,
        ?string $securityNote = null,
        ?string $fallbackLabel = null
    ): string {
        if (($buttonLabel === null) !== ($buttonUrl === null)) {
            throw new InvalidArgumentException('Email button label and URL must be supplied together.');
        }
        if ($buttonUrl !== null && !preg_match('#^https?://#i', $buttonUrl)) {
            throw new InvalidArgumentException('Email button URL must be absolute.');
        }

        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        // V2 headline treatment: the final word carries the site's gold
        // italic accent. Text content is unchanged — markup only.
        $headingHtml = $escape($heading);
        $headingSplit = strrpos($heading, ' ');
        if ($headingSplit !== false && $headingSplit > 0) {
            $headingHtml = $escape(substr($heading, 0, $headingSplit))
                . ' <span style="color:#B89563;font-style:italic;">'
                . $escape(substr($heading, $headingSplit + 1))
                . '</span>';
        }

        $detailsHtml = '';
        foreach ($details as $detail) {
            if (!is_string($detail)) {
                throw new InvalidArgumentException('Email detail paragraphs must be plain text.');
            }
            $detailsHtml .= '<tr><td style="padding:0 0 13px 0;font-family:Arial,Helvetica,sans-serif;'
                . 'font-size:15px;line-height:24px;color:#2E4038;">'
                . $escape($detail)
                . '</td></tr>';
        }

        $buttonHtml = '';
        $fallbackHtml = '';
        if ($buttonLabel !== null && $buttonUrl !== null) {
            $safeButtonUrl = $escape($buttonUrl);
            $buttonHtml = '<tr><td align="left" style="padding:12px 0 22px 0;">'
                . '<table role="presentation" border="0" cellspacing="0" cellpadding="0">'
                . '<tr><td align="center" bgcolor="#17645F" style="border-radius:7px;">'
                . '<a href="' . $safeButtonUrl . '" target="_blank" '
                . 'style="display:inline-block;padding:14px 25px;font-family:Arial,Helvetica,sans-serif;'
                . 'font-size:15px;font-weight:bold;line-height:20px;color:#FFFFFF;text-decoration:none;'
                . 'border:1px solid #17645F;border-radius:7px;">'
                . $escape($buttonLabel)
                . '</a></td></tr></table></td></tr>';

            if ($fallbackLabel !== null && $fallbackLabel !== '') {
                $fallbackHtml = '<tr><td style="padding:0 0 18px 0;font-family:Arial,Helvetica,sans-serif;'
                    . 'font-size:13px;line-height:21px;color:#5C6F66;">'
                    . $escape($fallbackLabel)
                    . '<br><a href="' . $safeButtonUrl . '" target="_blank" '
                    . 'style="color:#17645F;text-decoration:underline;word-break:break-all;">'
                    . $safeButtonUrl
                    . '</a></td></tr>';
            }
        }

        $securityHtml = '';
        if ($securityNote !== null && $securityNote !== '') {
            $securityHtml = '<tr><td style="padding:0 0 24px 0;">'
                . '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" '
                . 'bgcolor="#F6F1E3" style="width:100%;background-color:#F6F1E3;border-left:3px solid #B89563;">'
                . '<tr><td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;'
                . 'font-size:13px;line-height:21px;color:#4F4632;">'
                . $escape($securityNote)
                . '</td></tr></table></td></tr>';
        }

        // Header brand mark: the shield (logo-3) as inline SVG in a
        // gold-bordered tile, ivory strokes on deep green — the email
        // counterpart of the site's navbar tile. Clients that strip SVG
        // fall back to the serif wordmark alone, which still reads as a
        // complete header.
        return '<!doctype html>'
            . '<html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
            . '<meta name="x-apple-disable-message-reformatting">'
            . '<meta name="color-scheme" content="light">'
            . '<meta name="supported-color-schemes" content="light">'
            . '<title>' . $escape($heading) . '</title></head>'
            . '<body style="margin:0;padding:0;background-color:#F6F7F3;">'
            . '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;'
            . 'opacity:0;overflow:hidden;mso-hide:all;color:transparent;">'
            . $escape($preheader) . '</div>'
            . '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" '
            . 'bgcolor="#F6F7F3" style="width:100%;background-color:#F6F7F3;">'
            . '<tr><td align="center" style="padding:32px 14px;">'
            . '<table role="presentation" width="600" border="0" cellspacing="0" cellpadding="0" '
            . 'style="width:100%;max-width:600px;background-color:#FFFFFF;border:1px solid #DDD6C2;'
            . 'border-radius:12px;overflow:hidden;">'
            . '<tr><td bgcolor="#163734" style="padding:25px 30px;background-color:#163734;'
            . 'border-bottom:4px solid #B89563;">'
            . '<table role="presentation" border="0" cellspacing="0" cellpadding="0"><tr>'
            . '<td width="54" valign="middle" style="width:54px;padding:0 12px 0 0;">'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" viewBox="-4 -4 32 32" '
            . 'fill="none" stroke="#F6F7F3" stroke-width="1.9" stroke-linecap="round" '
            . 'stroke-linejoin="round" style="display:block;background-color:#1E453F;'
            . 'border:1px solid #B89563;border-radius:9px;">'
            . '<path d="M12 3.4 18.8 5.9v5.1c0 4.5-2.9 7.4-6.8 9.6C8.1 18.4 5.2 15.5 5.2 11V5.9Z"/>'
            . '<path d="M12 7.6v6.2"/><path d="M8.9 10.7h6.2"/>'
            . '<path d="M9.3 16.9c.8-1 1.7-1.4 2.7-1.4s1.9.4 2.7 1.4"/></svg>'
            . '</td><td valign="middle">'
            . '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:25px;'
            . 'letter-spacing:-0.2px;line-height:31px;color:#F6F7F3;">Health<span style="color:#D9BF96;font-style:italic;">Bridge</span></div>'
            . '<div style="padding-top:5px;font-family:Arial,Helvetica,sans-serif;font-size:10px;'
            . 'font-weight:bold;letter-spacing:1.5px;line-height:16px;color:#D9BF96;">PATIENT SERVICES</div>'
            . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td style="padding:34px 30px 12px 30px;">'
            . '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">'
            . '<tr><td style="padding:0 0 11px 0;font-family:Arial,Helvetica,sans-serif;'
            . 'font-size:11px;font-weight:bold;letter-spacing:1.3px;line-height:16px;color:#8A7658;">'
            . $escape($eyebrow)
            . '</td></tr>'
            . '<tr><td style="padding:0 0 13px 0;font-family:Georgia,\'Times New Roman\',serif;'
            . 'font-size:27px;letter-spacing:-0.3px;line-height:34px;color:#102B2B;">'
            . $headingHtml
            . '</td></tr>'
            . '<tr><td style="padding:0 0 20px 0;font-family:Arial,Helvetica,sans-serif;'
            . 'font-size:15px;line-height:25px;color:#2E4038;">'
            . $escape($intro)
            . '</td></tr>'
            . $detailsHtml
            . $buttonHtml
            . $fallbackHtml
            . $securityHtml
            . '</table></td></tr>'
            . '<tr><td bgcolor="#102B2B" style="padding:19px 30px;background-color:#102B2B;">'
            . '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:14px;line-height:20px;'
            . 'color:#F6F7F3;">Health<span style="color:#D9BF96;font-style:italic;">Bridge</span></div>'
            . '<div style="padding-top:6px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:19px;'
            . 'color:#C1D1C9;">This is an automated message from HealthBridge. Please do not reply to this email.</div>'
            . '<div style="padding-top:6px;font-family:Arial,Helvetica,sans-serif;font-size:11px;'
            . 'line-height:17px;color:#7D968A;">HealthBridge · Patient Services</div>'
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    /**
     * DPAPI decrypts only for the current Windows user. The password is read
     * through a private pipe and is never placed in a command argument.
     */
    private static function readProtectedPassword(): string
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('HealthBridge email transport is not configured.');
        }

        $hasLocalDpapiSecret = PHP_OS_FAMILY === 'Windows'
            && is_file(self::PASSWORD_FILE)
            && is_file(self::SECRET_READER)
            && function_exists('proc_open');

        if (!$hasLocalDpapiSecret) {
            $environmentPassword = self::readEnvironmentPassword();
            if ($environmentPassword === null) {
                throw new RuntimeException('HealthBridge email secret could not be loaded.');
            }
            return $environmentPassword;
        }

        $command = [
            'powershell.exe',
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-WindowStyle',
            'Hidden',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            self::SECRET_READER,
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('HealthBridge email secret could not be loaded.');
        }

        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 8);
        stream_set_timeout($pipes[2], 8);
        $password = trim((string)stream_get_contents($pipes[1]));
        $diagnostic = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unset($diagnostic);

        if ($exitCode !== 0 || !preg_match('/^[A-Za-z0-9]{16}$/', $password)) {
            $password = str_repeat("\0", strlen($password));
            throw new RuntimeException('HealthBridge email secret could not be loaded.');
        }

        return $password;
    }
}
