<?php
$symbol = strtoupper($_GET['symbol'] ?? 'USD');
$apiUrl = sprintf('https://baha24.com/api/v1/price/%s', urlencode($symbol));

function fetchPrice(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: PriceFetcher/1.0',
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

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg());
    }

    return $data;
}

$error = null;
$priceData = null;

try {
    $priceData = fetchPrice($apiUrl);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price for <?php echo htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 2rem;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        h1 {
            margin-top: 0;
        }
        dl {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.5rem 1rem;
        }
        dt {
            font-weight: bold;
        }
        .error {
            padding: 1rem;
            background-color: #fbeaea;
            color: #c0392b;
            border: 1px solid #e74c3c;
            border-radius: 6px;
        }
        form {
            margin-top: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
        }
        input[type="text"] {
            padding: 0.5rem;
            width: 100%;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1rem;
        }
        button {
            margin-top: 0.75rem;
            padding: 0.5rem 1rem;
            font-size: 1rem;
            border: none;
            border-radius: 4px;
            color: #fff;
            background-color: #007bff;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Price Lookup</h1>
        <p>Showing the latest price for <strong><?php echo htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

        <?php if ($error !== null): ?>
            <div class="error">Failed to fetch price data: <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif ($priceData !== null): ?>
            <dl>
                <dt>Title</dt>
                <dd><?php echo htmlspecialchars($priceData['title'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></dd>

                <dt>Symbol</dt>
                <dd><?php echo htmlspecialchars($priceData['symbol'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></dd>

                <dt>Sell</dt>
                <dd><?php echo htmlspecialchars($priceData['sell'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></dd>

                <dt>Last Update</dt>
                <dd><?php echo htmlspecialchars($priceData['last_update'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></dd>
            </dl>
        <?php else: ?>
            <p>No data available.</p>
        <?php endif; ?>

        <form method="get">
            <label for="symbol">Check another symbol (uppercase)</label>
            <input
                type="text"
                id="symbol"
                name="symbol"
                value="<?php echo htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8'); ?>"
                pattern="[A-Z0-9]+"
                required
            >
            <button type="submit">Get Price</button>
        </form>
    </div>
</body>
</html>
