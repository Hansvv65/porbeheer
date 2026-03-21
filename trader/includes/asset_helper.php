<?php
declare(strict_types=1);

function getAssetCatalog(): array
{
    return [
        ['symbol' => 'AAPL',    'name' => 'Apple Inc.',           'type' => 'Stock',     'keywords' => 'apple aapl aandeel tech'],
        ['symbol' => 'TSLA',    'name' => 'Tesla Inc.',           'type' => 'Stock',     'keywords' => 'tesla tsla aandeel ev auto'],
        ['symbol' => 'BTC-USD', 'name' => 'Bitcoin / USD',        'type' => 'Crypto',    'keywords' => 'bitcoin btc crypto'],
        ['symbol' => 'ETH-USD', 'name' => 'Ethereum / USD',       'type' => 'Crypto',    'keywords' => 'ethereum eth crypto'],
        ['symbol' => 'GC=F',    'name' => 'Gold Futures',         'type' => 'Commodity', 'keywords' => 'goud gold'],
        ['symbol' => 'SI=F',    'name' => 'Silver Futures',       'type' => 'Commodity', 'keywords' => 'zilver silver'],
        ['symbol' => 'CL=F',    'name' => 'Crude Oil Futures',    'type' => 'Commodity', 'keywords' => 'olie oil crude'],
        ['symbol' => 'NG=F',    'name' => 'Natural Gas Futures',  'type' => 'Commodity', 'keywords' => 'aardgas natural gas'],
        ['symbol' => '^GSPC',   'name' => 'S&P 500 Index',        'type' => 'Index',     'keywords' => 'sp500 s&p 500'],
        ['symbol' => '^IXIC',   'name' => 'NASDAQ Composite',     'type' => 'Index',     'keywords' => 'nasdaq'],
    ];
}

function searchAssets(string $query): array
{
    $query = mb_strtolower(trim($query));
    if ($query === '') {
        return [];
    }

    $results = [];
    foreach (getAssetCatalog() as $asset) {
        $haystack = mb_strtolower(
            $asset['symbol'] . ' ' .
            $asset['name'] . ' ' .
            $asset['type'] . ' ' .
            $asset['keywords']
        );

        if (str_contains($haystack, $query)) {
            $results[] = $asset;
        }
    }

    return $results;
}
