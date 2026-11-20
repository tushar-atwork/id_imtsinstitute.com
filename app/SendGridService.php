<?php
final class SendGridService
{
    private static function secret(): string
    {
        global $cfg;
        $password = (string)($cfg['crm_db']['password'] ?? '');
        if ($password === '') throw new RuntimeException('CRM configuration is incomplete.');
        return hash_hmac('sha256', 'imts-sendgrid-key-v1', $password, true);
    }
    public static function encrypt(string $key): string
    {
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL extension is required.');
        $iv = random_bytes(12);
        $tag = '';
        $data = openssl_encrypt($key, 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($data === false) throw new RuntimeException('Could not encrypt the API key.');
        return base64_encode($iv . $tag . $data);
    }
    private static function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Stored SendGrid key is invalid.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Stored SendGrid key cannot be decrypted. Re-enter it in admin settings.');
        return $plain;
    }
    public static function settings(PDO $db): ?array
    {
        $q = $db->query('SELECT * FROM crm_sendgrid_settings WHERE id=1');
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public static function send(PDO $db, string $recipient, string $subject, string $body): ?string
    {
        $settings = self::settings($db);
        if (!$settings || !$settings['api_key_cipher'] || !filter_var($settings['sender_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SendGrid is not configured. Ask an administrator to complete Email settings.');
        if (!function_exists('curl_init')) throw new RuntimeException('The server requires the PHP cURL extension.');
        $personalization = ['to' => [['email' => $recipient]]];
        $bcc = trim((string)($settings['bcc_email'] ?? ''));
        $replyTo = trim((string)($settings['reply_to_email'] ?? ''));
        foreach (['BCC' => $bcc, 'Reply-To' => $replyTo] as $label => $address) {
            if ($address !== '' && (strlen($address) > 254 || !filter_var($address, FILTER_VALIDATE_EMAIL))) throw new RuntimeException('The ' . $label . ' address in Email settings is invalid.');
        }
        // SendGrid does not accept the same address in both To and BCC.
        if ($bcc !== '' && strcasecmp($bcc, $recipient) !== 0) $personalization['bcc'] = [['email' => $bcc]];
        $message = ['personalizations' => [$personalization], 'from' => ['email' => $settings['sender_email'], 'name' => $settings['sender_name']], 'subject' => $subject, 'content' => [['type' => 'text/plain', 'value' => $body]]];
        if ($replyTo !== '') $message['reply_to'] = ['email' => $replyTo];
        $payload = json_encode($message, JSON_THROW_ON_ERROR);
        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::decrypt($settings['api_key_cipher']), 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HEADER => true]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $headerLength = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($response === false || $code !== 202) {
            error_log('SendGrid delivery request failed; HTTP ' . $code . ' ' . $error);
            throw new RuntimeException('SendGrid did not accept this email (HTTP ' . $code . '). Please check sender verification and API settings.');
        }
        preg_match('/^x-message-id:\s*([^\r\n]+)/im', substr($response, 0, $headerLength), $match);
        return isset($match[1]) ? substr(trim($match[1]), 0, 255) : null;
    }
}
