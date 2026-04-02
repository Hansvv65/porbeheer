<?php
declare(strict_types=1);

/* advice_generator.php
   Genereert en beoordeelt koop/verkoopadviezen voor actieve assets die nog niet in open posities staan.
*/

require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';
require __DIR__ . '/includes/layout.php';

// Controleer of de API-sleutel gedefinieerd is
if (!defined('AI_API_KEY') || empty(AI_API_KEY)) {
    die("Fout: AI_API_KEY is niet gedefinieerd in het configuratiebestand.");
}

// Debug: Toon database- en API-fouten
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Functie om actieve assets op te halen die niet in open posities staan
function getActiveAssetsNotInPositions($pdo) {
    try {
        $query = "
            SELECT a.symbol, a.display_name, a.asset_type, a.exchange_name, a.currency
            FROM asset_universe a
            WHERE a.status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM positions p
                WHERE p.symbol = a.symbol AND p.status = 'OPEN'
            )
            ORDER BY a.symbol
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("Aantal actieve assets (niet in posities): " . count($assets));

        return $assets;
    } catch (PDOException $e) {
        error_log("Databasefout bij ophalen actieve assets: " . $e->getMessage());
        return [];
    }
}

// Functie om recente nieuwsfeeds op te halen voor een symbool
function getRecentNews($pdo, $symbol, $limit = 5) {
    try {
        $stmt = $pdo->prepare("
            SELECT title, summary, published_at, sentiment_score, sentiment_label, importance_score
            FROM asset_news
            WHERE symbol = :symbol
            ORDER BY published_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':symbol', $symbol, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $news = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("Aantal nieuwsitems voor $symbol: " . count($news));

        return $news;
    } catch (PDOException $e) {
        error_log("Databasefout bij ophalen nieuws voor $symbol: " . $e->getMessage());
        return [];
    }
}

// Functie om advies te genereren met AI-model 1
function getAIAdvice($symbol, $assetInfo, $newsItems) {
    $api_key = AI_API_KEY;
    $url = "https://api.mistral.ai/v1/chat/completions";

    $newsSummary = count($newsItems) > 0
        ? implode("\n- ", array_map(fn($item) => $item['title'] . " (" . $item['sentiment_label'] . ")", $newsItems))
        : "Geen recent nieuws gevonden.";

    $prompt = "
        Geef een advies (koop, niet kopen, in de gaten houden) voor aandeel $symbol.
        Asset informatie: {$assetInfo['display_name']} ({$assetInfo['asset_type']}), beurs: {$assetInfo['exchange_name']}, valuta: {$assetInfo['currency']}.
        Relevant nieuws:
        $newsSummary
        Geef een kort, duidelijk advies met motivatie.
    ";

    $data = [
        "model" => "mistral-tiny",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $api_key"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        error_log("cURL-fout bij genereren advies voor $symbol: $curlError");
        return "Kon geen advies genereren: cURL-fout.";
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("API-fout bij genereren advies voor $symbol: HTTP-code $httpCode, Response: $response");
        return "Kon geen advies genereren: API-fout (HTTP $httpCode).";
    }

    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    } else {
        error_log("Ongeldig API-antwoord voor $symbol: " . $response);
        return "Geen advies gegenereerd.";
    }
}

// Functie om advies te beoordelen met AI-model 2
function evaluateAdvice($symbol, $advice) {
    $api_key = AI_API_KEY;
    $url = "https://api.mistral.ai/v1/chat/completions";

    $prompt = "
        Beoordeel het volgende advies voor aandeel $symbol: '$advice'.
        Geef een gewogen beslissing (koop, niet kopen, in de gaten houden) en een betrouwbaarheidsscore (0-100).
        Motiveer je beslissing.
    ";

    $data = [
        "model" => "mistral-tiny",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $api_key"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError) {
        error_log("cURL-fout bij beoordelen advies voor $symbol: $curlError");
        return "Kon het advies niet beoordelen: cURL-fout.";
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("API-fout bij beoordelen advies voor $symbol: HTTP-code $httpCode, Response: $response");
        return "Kon het advies niet beoordelen: API-fout (HTTP $httpCode).";
    }

    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    } else {
        error_log("Ongeldig API-antwoord bij beoordeling voor $symbol: " . $response);
        return "Geen beoordeling gegenereerd.";
    }
}

// Hoofdlogica
renderPageStart("AI Advies Generator", "Genereert en beoordeelt adviezen voor actieve assets die nog niet in open posities staan");

// Haal actieve assets op die niet in open posities staan
$assets = getActiveAssetsNotInPositions($pdo);

if (empty($assets)) {
    echo "<p>Geen actieve assets gevonden die nog niet in open posities staan.</p>";
} else {
    foreach ($assets as $asset) {
        $symbol = $asset['symbol'];
        echo "<div class='debug-info'><p>Bezig met verwerken van $symbol...</p></div>";

        $news = getRecentNews($pdo, $symbol);
        $advice = getAIAdvice($symbol, $asset, $news);
        $evaluation = evaluateAdvice($symbol, $advice);

        // Resultaten weergeven
        echo "<div class='advice-card'>";
        echo "<h2>Symbool: " . h($symbol) . "</h2>";
        echo "<p><strong>Asset informatie:</strong> " . h($asset['display_name']) .
             " (" . h($asset['asset_type']) . "), Beurs: " . h($asset['exchange_name']) .
             ", Valuta: " . h($asset['currency']) . "</p>";
        echo "<p><strong>Advies:</strong> " . nl2br(h($advice)) . "</p>";
        echo "<p><strong>Beoordeling:</strong> " . nl2br(h($evaluation)) . "</p>";
        echo "</div><hr>";
    }
}

renderPageEnd();