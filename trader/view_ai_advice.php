<?php
declare(strict_types=1);

/* view_ai_advice.php
   Toont AI-adviezen voor actieve assets met uitklapbare details en een top 10 van hete assets.
*/

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/functions.php';
require_once __DIR__ . '/includes/layout.php';

// Functie om AI-adviezen op te halen uit de database
function getAIAdvices(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT id, symbol, display_name, asset_type, exchange_name, currency, advice, evaluation, created_at
        FROM ai_advice
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Functie om de top 10 hete assets op te halen via AI
function getTopHotAssets(): array {
    // Controleer of de API-sleutel gedefinieerd is
    if (!defined('AI_API_KEY') || empty(AI_API_KEY)) {
        error_log("AI_API_KEY is niet gedefinieerd of leeg.");
        return [];
    }

    $api_key = AI_API_KEY;
    $url = "https://api.mistral.ai/v1/chat/completions";

    $prompt = "
        Geef een lijst van 10 hete assets (aandelen, commodities, of crypto) die momenteel interessant zijn om te kopen.
        Geef voor elke asset het symbool, de volledige naam, het type (aandeel, commodity, crypto),
        en een korte motivatie waarom deze asset interessant is.
        Geef de lijst in JSON-formaat met de volgende structuur:
        [
            {
                'symbol': 'SYMBOOL',
                'name': 'Volledige Naam',
                'type': 'aandeel/commodity/crypto',
                'reason': 'Korte motivatie'
            },
            ...
        ]
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
        error_log("cURL-fout bij ophalen top 10 hete assets: $curlError");
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("API-fout bij ophalen top 10 hete assets: HTTP-code $httpCode, Response: $response");
        return [];
    }

    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        error_log("Ongeldig API-antwoord bij ophalen top 10 hete assets: " . $response);
        return [];
    }

    $content = $result['choices'][0]['message']['content'];
    $jsonStart = strpos($content, '[');
    $jsonEnd = strrpos($content, ']') + 1;
    $jsonString = substr($content, $jsonStart, $jsonEnd - $jsonStart);

    $hotAssets = json_decode($jsonString, true);
    return is_array($hotAssets) ? $hotAssets : [];
}

// Hoofdlogica
renderPageStart("AI Adviezen", "Overzicht van gegenereerde AI-adviezen voor actieve assets");

// Haal de top 10 hete assets op
$hotAssets = getTopHotAssets();

// Toon de top 10 hete assets
echo '<div class="hot-assets-container">';
echo '<h2>Top 10 Hete Assets om te Kopen</h2>';

if (!empty($hotAssets)) {
    echo '<div class="hot-assets-list">';
    foreach ($hotAssets as $asset) {
        echo '<div class="hot-asset-card">';
        echo '<h3>' . h($asset['name']) . ' (' . h($asset['symbol']) . ')</h3>';
        echo '<p><strong>Type:</strong> ' . h(ucfirst($asset['type'])) . '</p>';
        echo '<p><strong>Motivatie:</strong> ' . h($asset['reason']) . '</p>';
        echo '</div>';
    }
    echo '</div>';
} else {
    echo '<p>Kon de top 10 hete assets niet ophalen. Controleer de API-sleutel en verbinding.</p>';
}
echo '</div>';

// Haal AI-adviezen op
$advices = getAIAdvices($pdo);

if (empty($advices)) {
    echo "<p>Geen AI-adviezen gevonden in de database.</p>";
} else {
    echo '<div class="advice-container">';
    echo '<h2>AI Adviezen voor Actieve Assets</h2>';

    foreach ($advices as $advice) {
        $createdAt = new DateTime($advice['created_at']);
        $formattedDate = $createdAt->format('d-m-Y H:i:s');

        echo '<div class="advice-card">';
        echo '<div class="advice-header">';
        echo '<h3>' . h($advice['display_name']) . ' (' . h($advice['symbol']) . ')</h3>';
        echo '<p class="advice-time"><strong>Tijd:</strong> ' . h($formattedDate) . '</p>';
        echo '</div>';

        // Toon een samenvatting van de adviezen
        echo '<div class="advice-summary">';
        echo '<p><strong>Advies:</strong> ' . h(substr($advice['advice'], 0, 100)) . (strlen($advice['advice']) > 100 ? '...' : '') . '</p>';
        echo '<p><strong>Beoordeling:</strong> ' . h(substr($advice['evaluation'], 0, 100)) . (strlen($advice['evaluation']) > 100 ? '...' : '') . '</p>';
        echo '</div>';

        // Knop om details te tonen/verbergen
        echo '<button class="toggle-details" data-id="' . h($advice['id']) . '">Toon volledige details</button>';

        // Uitklapbare sectie met volledige details
        echo '<div class="advice-details" id="details-' . h($advice['id']) . '" style="display: none;">';
        echo '<div class="advice-full">';
        echo '<p><strong>Volledig Advies:</strong></p>';
        echo '<div class="advice-text">' . nl2br(h($advice['advice'])) . '</div>';
        echo '<p><strong>Volledige Beoordeling:</strong></p>';
        echo '<div class="advice-text">' . nl2br(h($advice['evaluation'])) . '</div>';
        echo '</div>';

        // Extra details
        echo '<div class="advice-meta">';
        echo '<p><strong>Asset Type:</strong> ' . h($advice['asset_type']) . '</p>';
        echo '<p><strong>Beurs:</strong> ' . h($advice['exchange_name']) . '</p>';
        echo '<p><strong>Valuta:</strong> ' . h($advice['currency']) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}

renderPageEnd();
?>

<script>
document.querySelectorAll('.toggle-details').forEach(button => {
    button.addEventListener('click', () => {
        const detailsId = 'details-' + button.dataset.id;
        const detailsElement = document.getElementById(detailsId);
        if (detailsElement.style.display === 'none') {
            detailsElement.style.display = 'block';
            button.textContent = 'Verberg details';
        } else {
            detailsElement.style.display = 'none';
            button.textContent = 'Toon volledige details';
        }
    });
});
</script>

<style>
.hot-assets-container {
    margin-bottom: 30px;
    padding: 15px;
    background-color: #f0f8ff;
    border-radius: 8px;
    border: 1px solid #add8e6;
}

.hot-assets-container h2 {
    margin-top: 0;
    color: #0066cc;
}

.hot-assets-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.hot-asset-card {
    padding: 15px;
    background-color: white;
    border-radius: 8px;
    border: 1px solid #add8e6;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.hot-asset-card h3 {
    margin-top: 0;
    color: #0066cc;
}

.advice-container {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.advice-card {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    background-color: #f9f9f9;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.advice-header {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.advice-header h3 {
    margin: 0 0 5px 0;
    color: #333;
}

.advice-time {
    margin: 0;
    color: #666;
    font-size: 0.9em;
}

.advice-summary {
    margin-bottom: 10px;
    padding: 10px;
    background-color: #fff;
    border-radius: 4px;
    border: 1px solid #eee;
}

.advice-summary p {
    margin: 5px 0;
}

.toggle-details {
    background-color: #4CAF50;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 4px;
    cursor: pointer;
    margin: 10px 0;
    width: 100%;
}

.advice-details {
    margin-top: 10px;
    padding: 15px;
    background-color: #fff;
    border-radius: 4px;
    border: 1px solid #eee;
}

.advice-full {
    margin-bottom: 15px;
}

.advice-text {
    padding: 10px;
    background-color: #f5f5f5;
    border-radius: 4px;
    margin-bottom: 10px;
    white-space: pre-line;
}

.advice-meta {
    padding-top: 10px;
    border-top: 1px solid #eee;
}

.advice-meta p {
    margin: 5px 0;
}
</style>