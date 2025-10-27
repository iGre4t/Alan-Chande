<?php
// Simple Rubika bot webhook that replies with the USD price on /start
// Configuration
// - Prefer environment variables for secrets in production.

declare(strict_types=1);

// Read token from env, fallback to inline string (replace as needed)
$BOT_TOKEN = getenv('RUBIKA_BOT_TOKEN') ?: 'ECFAE0ANSIPVCLZNGRSELXOSRYXMTPGGDKONVEXCZQEOGJLBLGZODJDHZEAABMFZ';

// Base URL for Rubika Bot API. If your Rubika gateway differs, set RUBIKA_API_BASE env.
// Many Rubika setups mirror Telegram-like endpoints: https://api.rubika.ir/bot<token>/<method>
// If your provider gives a different base, set RUBIKA_API_BASE accordingly.
$RUBIKA_API_BASE = rtrim(getenv('RUBIKA_API_BASE') ?: 'https://api.rubika.ir', '/');

// Ensure cURL errors are visible in logs
ini_set('log_errors', '1');
ini_set('display_errors', '0');

// Fetch price helper (reused from index.php logic)
function fetchPrice(string $symbol = 'USD'): array
{
    $symbol = strtoupper($symbol);
    $url = sprintf('https://baha24.com/api/v1/price/%s', rawurlencode($symbol));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: RubikaBot/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('cURL error: ' . $curlError);
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('API returned HTTP status ' . $statusCode);
    }
    $data = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
    }
    return $data;
}

// Send a message via Rubika Bot API (Telegram-like schema). Adjust base via RUBIKA_API_BASE if needed.
function sendRubikaMessage(string $token, $chatId, string $text, ?string $apiBase = null): array
{
    $apiBase = $apiBase ? rtrim($apiBase, '/') : $GLOBALS['RUBIKA_API_BASE'];
    $endpoint = $apiBase . '/bot' . $token . '/sendMessage';

    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        // If Rubika supports parse_mode similar to Telegram, you can enable it
        // 'parse_mode' => 'HTML',
        // 'disable_web_page_preview' => true,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: RubikaBot/1.0',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Rubika sendMessage cURL error: ' . $curlError);
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('Rubika sendMessage HTTP status ' . $statusCode);
    }
    $data = json_decode((string)$response, true);
    // If API does not return JSON, return raw text
    return is_array($data) ? $data : ['raw' => $response];
}

// Build a friendly message with price info
function buildPriceMessage(array $price): string
{
    $title = $price['title'] ?? 'USD Price';
    $symbol = $price['symbol'] ?? 'USD';
    $sell = $price['sell'] ?? 'N/A';
    $updated = $price['last_update'] ?? 'N/A';
    return sprintf("%s (%s)\nSell: %s\nLast update: %s", $title, $symbol, $sell, $updated);
}

// Basic webhook router
http_response_code(200); // Always 200 so Rubika considers webhook delivered
header('Content-Type: application/json; charset=UTF-8');

if (!$BOT_TOKEN || $BOT_TOKEN === 'YOUR_TOKEN_HERE') {
    echo json_encode(['ok' => false, 'error' => 'Missing RUBIKA_BOT_TOKEN']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);

// If not JSON or empty, allow a simple health check via GET
if (!is_array($update)) {
    // Optional: respond with current USD price for quick manual checks
    try {
        $p = fetchPrice('USD');
        echo json_encode(['ok' => true, 'hint' => 'Webhook ready', 'price' => $p]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Try to extract chat id and text from common structures
$message = $update['message'] ?? $update['edited_message'] ?? null;
$chatId = null;
$text = null;

if (is_array($message)) {
    $text = $message['text'] ?? '';
    // Telegram-like: message.chat.id
    if (isset($message['chat']['id'])) {
        $chatId = $message['chat']['id'];
    }
}

// Fallbacks for other Rubika payload shapes (best-effort)
if (!$chatId && isset($update['peer']['id'])) {
    $chatId = $update['peer']['id'];
}
if (!$text && isset($update['text'])) {
    $text = $update['text'];
}

if (!$chatId) {
    // Can't respond without a chat id
    echo json_encode(['ok' => false, 'error' => 'No chat id in update', 'update_sample' => $update]);
    exit;
}

// Normalize command detection
$normalized = trim(strtolower((string)$text));
$isStart = ($normalized === '/start' || $normalized === 'start' || $normalized === 'شروع');

try {
    if ($isStart) {
        $price = fetchPrice('USD');
        $msg = buildPriceMessage($price);
        $res = sendRubikaMessage($BOT_TOKEN, $chatId, $msg, $RUBIKA_API_BASE);
        echo json_encode(['ok' => true, 'action' => 'start', 'sent' => $res]);
    } else {
        // Optional: handle other inputs – reply with help and price shortcut
        $help = "Send /start to receive the latest USD price.";
        $res = sendRubikaMessage($BOT_TOKEN, $chatId, $help, $RUBIKA_API_BASE);
        echo json_encode(['ok' => true, 'action' => 'help', 'sent' => $res]);
    }
} catch (Throwable $e) {
    // Report error back to chat and to webhook response
    try {
        sendRubikaMessage($BOT_TOKEN, $chatId, 'Error: ' . $e->getMessage(), $RUBIKA_API_BASE);
    } catch (Throwable $inner) {
        // ignore secondary errors
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

