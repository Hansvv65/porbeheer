<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getAssetCatalog(): array
{
    return [
        ['symbol'=>'ASML.AS','name'=>'ASML Holding NV','type'=>'stock','exchange'=>'Euronext Amsterdam','currency'=>'EUR','provider'=>'local','keywords'=>'asml chip semiconductor lithography nederland aex'],
        ['symbol'=>'ADYEN.AS','name'=>'Adyen NV','type'=>'stock','exchange'=>'Euronext Amsterdam','currency'=>'EUR','provider'=>'local','keywords'=>'adyen fintech payment nederland'],
        ['symbol'=>'INGA.AS','name'=>'ING Groep NV','type'=>'stock','exchange'=>'Euronext Amsterdam','currency'=>'EUR','provider'=>'local','keywords'=>'ing bank finance nederland'],
        ['symbol'=>'PHIA.AS','name'=>'Philips NV','type'=>'stock','exchange'=>'Euronext Amsterdam','currency'=>'EUR','provider'=>'local','keywords'=>'philips healthcare medical'],
        ['symbol'=>'SHELL.AS','name'=>'Shell PLC','type'=>'stock','exchange'=>'Euronext Amsterdam','currency'=>'EUR','provider'=>'local','keywords'=>'shell oil gas energy'],

        ['symbol'=>'AAPL','name'=>'Apple Inc.','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'apple iphone mac tech'],
        ['symbol'=>'MSFT','name'=>'Microsoft Corporation','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'microsoft windows azure office'],
        ['symbol'=>'NVDA','name'=>'NVIDIA Corporation','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'nvidia ai gpu chip semiconductor'],
        ['symbol'=>'TSLA','name'=>'Tesla Inc.','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'tesla ev electric car'],
        ['symbol'=>'AMZN','name'=>'Amazon.com Inc.','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'amazon aws ecommerce cloud'],
        ['symbol'=>'GOOGL','name'=>'Alphabet Inc. Class A','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'google alphabet search youtube'],
        ['symbol'=>'META','name'=>'Meta Platforms Inc.','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'meta facebook instagram whatsapp'],
        ['symbol'=>'AMD','name'=>'Advanced Micro Devices Inc.','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'amd chip cpu gpu'],
        ['symbol'=>'INTC','name'=>'Intel Corporation','type'=>'stock','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'intel chip cpu semiconductor'],
        ['symbol'=>'JPM','name'=>'JPMorgan Chase & Co.','type'=>'stock','exchange'=>'NYSE','currency'=>'USD','provider'=>'local','keywords'=>'jpmorgan bank finance'],

        ['symbol'=>'SPY','name'=>'SPDR S&P 500 ETF Trust','type'=>'etf','exchange'=>'NYSE Arca','currency'=>'USD','provider'=>'local','keywords'=>'spy etf sp500 s&p 500'],
        ['symbol'=>'QQQ','name'=>'Invesco QQQ Trust','type'=>'etf','exchange'=>'NASDAQ','currency'=>'USD','provider'=>'local','keywords'=>'qqq etf nasdaq tech'],
        ['symbol'=>'DIA','name'=>'SPDR Dow Jones ETF','type'=>'etf','exchange'=>'NYSE Arca','currency'=>'USD','provider'=>'local','keywords'=>'dia dow jones etf'],
        ['symbol'=>'IWM','name'=>'iShares Russell 2000 ETF','type'=>'etf','exchange'=>'NYSE Arca','currency'=>'USD','provider'=>'local','keywords'=>'iwm russell 2000 small cap'],
        ['symbol'=>'GLD','name'=>'SPDR Gold Shares','type'=>'etf','exchange'=>'NYSE Arca','currency'=>'USD','provider'=>'local','keywords'=>'gold goud etf'],
        ['symbol'=>'SLV','name'=>'iShares Silver Trust','type'=>'etf','exchange'=>'NYSE Arca','currency'=>'USD','provider'=>'local','keywords'=>'silver zilver etf'],
        ['symbol'=>'USO','name'=>'United States Oil Fund','type'=>'etf','exchange'=>'NYSE Arca','currency'=>'USD','provider'=>'local','keywords'=>'oil olie etf crude'],
        ['symbol'=>'UNG','name'=>'United States Natural Gas Fund','type'=>'etf','exchange'=>'NYSE Arca','currency'=>'USD','provider'=>'local','keywords'=>'natural gas aardgas etf'],

        ['symbol'=>'^GSPC','name'=>'S&P 500','type'=>'index','exchange'=>'INDEX','currency'=>'USD','provider'=>'local','keywords'=>'sp500 s&p 500 index usa'],
        ['symbol'=>'^IXIC','name'=>'NASDAQ Composite','type'=>'index','exchange'=>'INDEX','currency'=>'USD','provider'=>'local','keywords'=>'nasdaq composite index'],
        ['symbol'=>'^DJI','name'=>'Dow Jones Industrial Average','type'=>'index','exchange'=>'INDEX','currency'=>'USD','provider'=>'local','keywords'=>'dow jones dji'],
        ['symbol'=>'^AEX','name'=>'AEX Index','type'=>'index','exchange'=>'INDEX','currency'=>'EUR','provider'=>'local','keywords'=>'aex nederland amsterdam'],

        ['symbol'=>'BTC-USD','name'=>'Bitcoin / USD','type'=>'crypto','exchange'=>'CRYPTO','currency'=>'USD','provider'=>'local','keywords'=>'bitcoin btc crypto'],
        ['symbol'=>'ETH-USD','name'=>'Ethereum / USD','type'=>'crypto','exchange'=>'CRYPTO','currency'=>'USD','provider'=>'local','keywords'=>'ethereum eth crypto'],
        ['symbol'=>'SOL-USD','name'=>'Solana / USD','type'=>'crypto','exchange'=>'CRYPTO','currency'=>'USD','provider'=>'local','keywords'=>'solana sol crypto'],
        ['symbol'=>'XRP-USD','name'=>'XRP / USD','type'=>'crypto','exchange'=>'CRYPTO','currency'=>'USD','provider'=>'local','keywords'=>'xrp ripple crypto'],
        ['symbol'=>'ADA-USD','name'=>'Cardano / USD','type'=>'crypto','exchange'=>'CRYPTO','currency'=>'USD','provider'=>'local','keywords'=>'ada cardano crypto'],
        ['symbol'=>'DOGE-USD','name'=>'Dogecoin / USD','type'=>'crypto','exchange'=>'CRYPTO','currency'=>'USD','provider'=>'local','keywords'=>'dogecoin doge crypto'],

        ['symbol'=>'GC=F','name'=>'Gold Futures','type'=>'commodity','exchange'=>'COMEX','currency'=>'USD','provider'=>'local','keywords'=>'gold goud future commodity'],
        ['symbol'=>'SI=F','name'=>'Silver Futures','type'=>'commodity','exchange'=>'COMEX','currency'=>'USD','provider'=>'local','keywords'=>'silver zilver future commodity'],
        ['symbol'=>'HG=F','name'=>'Copper Futures','type'=>'commodity','exchange'=>'COMEX','currency'=>'USD','provider'=>'local','keywords'=>'koper copper future commodity'],
        ['symbol'=>'CL=F','name'=>'Crude Oil Futures','type'=>'commodity','exchange'=>'NYMEX','currency'=>'USD','provider'=>'local','keywords'=>'oil olie crude wti future'],
        ['symbol'=>'BZ=F','name'=>'Brent Crude Oil Futures','type'=>'commodity','exchange'=>'ICE','currency'=>'USD','provider'=>'local','keywords'=>'brent oil olie future'],
        ['symbol'=>'NG=F','name'=>'Natural Gas Futures','type'=>'commodity','exchange'=>'NYMEX','currency'=>'USD','provider'=>'local','keywords'=>'aardgas natural gas future'],
        ['symbol'=>'ZC=F','name'=>'Corn Futures','type'=>'commodity','exchange'=>'CBOT','currency'=>'USD','provider'=>'local','keywords'=>'corn mais grain future'],
        ['symbol'=>'ZW=F','name'=>'Wheat Futures','type'=>'commodity','exchange'=>'CBOT','currency'=>'USD','provider'=>'local','keywords'=>'wheat tarwe grain future'],
        ['symbol'=>'KC=F','name'=>'Coffee Futures','type'=>'commodity','exchange'=>'ICE','currency'=>'USD','provider'=>'local','keywords'=>'coffee koffie future'],

        ['symbol'=>'EURUSD=X','name'=>'EUR/USD','type'=>'forex','exchange'=>'FX','currency'=>'USD','provider'=>'local','keywords'=>'eur usd forex euro dollar'],
        ['symbol'=>'GBPUSD=X','name'=>'GBP/USD','type'=>'forex','exchange'=>'FX','currency'=>'USD','provider'=>'local','keywords'=>'gbp usd forex pond dollar'],
        ['symbol'=>'USDJPY=X','name'=>'USD/JPY','type'=>'forex','exchange'=>'FX','currency'=>'JPY','provider'=>'local','keywords'=>'usd jpy forex dollar yen'],
        ['symbol'=>'EURGBP=X','name'=>'EUR/GBP','type'=>'forex','exchange'=>'FX','currency'=>'GBP','provider'=>'local','keywords'=>'eur gbp forex euro pond'],
    ];
}

function normalizeLookupString(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = str_replace(['&','/','\\','-','_','.',','], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function assetScore(array $asset, string $query): int
{
    $score = 0;

    $symbol = normalizeLookupString((string)($asset['symbol'] ?? ''));
    $name = normalizeLookupString((string)($asset['name'] ?? ''));
    $keywords = normalizeLookupString((string)($asset['keywords'] ?? ''));
    $exchange = normalizeLookupString((string)($asset['exchange'] ?? ''));
    $type = normalizeLookupString((string)($asset['type'] ?? ''));

    if ($query === $symbol) $score += 1000;
    if ($query === $name) $score += 700;
    if (str_starts_with($symbol, $query)) $score += 300;
    if (str_contains($symbol, $query)) $score += 180;
    if (str_contains($name, $query)) $score += 120;
    if (str_contains($keywords, $query)) $score += 80;
    if (str_contains($exchange, $query)) $score += 50;
    if (str_contains($type, $query)) $score += 40;

    return $score;
}

function searchLocalAssets(string $query, ?string $typeFilter = null, int $limit = 50): array
{
    $query = normalizeLookupString($query);
    if ($query === '') return [];

    $rows = [];

    foreach (getAssetCatalog() as $asset) {
        if ($typeFilter && ($asset['type'] ?? '') !== $typeFilter) {
            continue;
        }

        $score = assetScore($asset, $query);
        if ($score > 0) {
            $asset['_score'] = $score;
            $rows[] = $asset;
        }
    }

    usort($rows, static function(array $a, array $b): int {
        return ($b['_score'] <=> $a['_score']) ?: strcmp((string)$a['symbol'], (string)$b['symbol']);
    });

    return array_slice($rows, 0, $limit);
}

function loadApiConfig(): array
{
    $file = __DIR__ . '/../config/api.php';
    if (!is_file($file)) return [];

    $cfg = require $file;
    return is_array($cfg) ? $cfg : [];
}

function mapApiAssetType(string $type): string
{
    $type = strtoupper(trim($type));

    return match ($type) {
        'ETF', 'ETP' => 'etf',
        'INDEX' => 'index',
        'FOREX' => 'forex',
        'CRYPTO' => 'crypto',
        'COMMON STOCK', 'ADR', 'EQS', 'STOCK' => 'stock',
        default => strtolower($type ?: 'unknown'),
    };
}

function dedupeAssets(array $assets): array
{
    $seen = [];
    $out = [];

    foreach ($assets as $asset) {
        $key = strtoupper(trim((string)($asset['symbol'] ?? '')));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $asset;
    }

    return $out;
}

function finnhubSearchAssets(string $query, int $limit = 20): array
{
    $cfg = loadApiConfig();
    $apiKey = trim((string)($cfg['finnhub_api_key'] ?? ''));

    if ($apiKey === '' || trim($query) === '') {
        return [];
    }

    $url = 'https://finnhub.io/api/v1/search?q=' . rawurlencode($query) . '&token=' . rawurlencode($apiKey);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['result']) || !is_array($data['result'])) {
        return [];
    }

    $rows = [];

    foreach ($data['result'] as $item) {
        if (!is_array($item)) continue;

        $symbol = trim((string)($item['symbol'] ?? ''));
        if ($symbol === '') continue;

        $rows[] = [
            'symbol' => $symbol,
            'name' => trim((string)($item['description'] ?? '')),
            'type' => mapApiAssetType((string)($item['type'] ?? '')),
            'exchange' => trim((string)($item['displaySymbol'] ?? ($item['mic'] ?? ''))),
            'currency' => '',
            'provider' => 'finnhub',
            'keywords' => '',
        ];
    }

    return array_slice(dedupeAssets($rows), 0, $limit);
}

function searchAssetsHybrid(string $query, ?string $typeFilter = null, int $limit = 50): array
{
    $local = searchLocalAssets($query, $typeFilter, $limit);
    $api = finnhubSearchAssets($query, 20);

    if ($typeFilter) {
        $api = array_values(array_filter($api, static fn(array $row): bool => ($row['type'] ?? '') === $typeFilter));
    }

    $merged = dedupeAssets(array_merge($local, $api));

    usort($merged, static function(array $a, array $b) use ($query): int {
        $scoreA = assetScore($a, normalizeLookupString($query));
        $scoreB = assetScore($b, normalizeLookupString($query));
        return ($scoreB <=> $scoreA) ?: strcmp((string)$a['symbol'], (string)$b['symbol']);
    });

    return array_slice($merged, 0, $limit);
}

function isValidSymbolFormat(string $symbol): bool
{
    return (bool) preg_match('/^[A-Z0-9^.=\\-]{1,20}$/', $symbol);
}