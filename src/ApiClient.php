<?php

declare(strict_types=1);

namespace Scheb\YahooFinanceApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use Scheb\YahooFinanceApi\Exception\ApiException;
use Scheb\YahooFinanceApi\Results\DividendData;
use Scheb\YahooFinanceApi\Results\HistoricalData;
use Scheb\YahooFinanceApi\Results\Quote;
use Scheb\YahooFinanceApi\Results\SearchResult;
use Scheb\YahooFinanceApi\Results\SplitData;

class ApiClient
{
    public const INTERVAL_1_DAY = '1d';
    public const INTERVAL_1_WEEK = '1wk';
    public const INTERVAL_1_MONTH = '1mo';
    public const CURRENCY_SYMBOL_SUFFIX = '=X';

    private const FILTER_HISTORICAL = 'history';
    private const FILTER_DIVIDENDS = 'div';
    private const FILTER_SPLITS = 'split';

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var ResultDecoder
     */
    private $resultDecoder;

    /**
     * @var string
     */
    private $userAgent;

    private CookieJar $cookieJar;
    private $cookiesCacheFile = __DIR__.'/cookies.txt';
    private $crumbCacheFile = __DIR__.'/crumb.txt';

    public function __construct(ClientInterface $guzzleClient, ResultDecoder $resultDecoder)
    {
        $this->client = $guzzleClient;
        $this->resultDecoder = $resultDecoder;
        $this->userAgent = UserAgent::getRandomUserAgent();
        $this->client = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://finance.yahoo.com/',
                'Origin' => 'https://finance.yahoo.com',
            ],
            'http_errors' => false,
        ]);
    }

    public function getHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            // 'Accept' => 'text/html,application/json;q=0.9;*/*',
        ];
    }

    /**
     * Search for stocks.
     *
     * @return SearchResult[]
     *
     * @throws ApiException
     */
    public function search(string $searchTerm, string $locale = 'en-US', int $limit = 10): array
    {
        $qs = $this->getRandomQueryServer();
        $url = 'https://query'.$qs.'.finance.yahoo.com/v1/finance/search?'
            .'q='.urlencode($searchTerm)
            .'&lang='.urlencode($locale)
            .'&region=US&quotesCount='.$limit
            .'&quotesQueryId=tss_match_phrase_query&multiQuoteQueryId=multi_quote_single_token_query&enableCb=false&enableNavLinks=true&enableCulturalAssets=true&enableNews=false&enableResearchReports=false&enableLists=false&listsCount=0&recommendCount=0&enablePrivateCompany=true';

        $response = $this->client->request('GET', $url, ['headers' => $this->getHeaders(), 'cookies' => $this->getCookies()]);

        $status = $response->getStatusCode();
        if (200 !== $status) {
            $this->registerClient();
            throw new ApiException("Yahoo search failed (HTTP $status)");
        }

        $body = (string) $response->getBody();

        return $this->resultDecoder->transformSearchResult($body);
    }

    /**
     * Get historical data for a symbol (deprecated).
     *
     * @deprecated In future versions, this function will be removed. Please consider using getHistoricalQuoteData() instead.
     *
     * @return HistoricalData[]
     *
     * @throws ApiException
     */
    public function getHistoricalData(string $symbol, string $interval, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        @trigger_error('[MrNamra/yahoo-finance-api] getHistoricalData() is deprecated and will be removed in a future release', \E_USER_DEPRECATED);

        return $this->getHistoricalQuoteData($symbol, $interval, $startDate, $endDate);
    }

    /**
     * Get historical data for a symbol.
     *
     * @return HistoricalData[]
     *
     * @throws ApiException
     */
    public function getHistoricalQuoteData(string $symbol, string $interval, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $this->validateIntervals($interval);
        $this->validateDates($startDate, $endDate);

        $responseBody = $this->getHistoricalDataResponseBodyJson($symbol, $interval, $startDate, $endDate, self::FILTER_HISTORICAL);

        return $this->resultDecoder->transformHistoricalDataResult($responseBody);
    }

    /**
     * Get dividend data for a symbol.
     *
     * @return DividendData[]
     *
     * @throws ApiException
     */
    public function getHistoricalDividendData(string $symbol, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $this->validateDates($startDate, $endDate);

        $responseBody = $this->getHistoricalDataResponseBodyJson($symbol, self::INTERVAL_1_MONTH, $startDate, $endDate, self::FILTER_DIVIDENDS);

        $historicData = $this->resultDecoder->transformDividendDataResult($responseBody);
        usort($historicData, function (DividendData $a, DividendData $b): int {
            // Data is not necessary in order, so ensure ascending order by date
            return $a->getDate() <=> $b->getDate();
        });

        return $historicData;
    }

    /**
     * Get stock split data for a symbol.
     *
     * @return SplitData[]
     *
     * @throws ApiException
     */
    public function getHistoricalSplitData(string $symbol, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $this->validateDates($startDate, $endDate);

        $responseBody = $this->getHistoricalDataResponseBodyJson($symbol, self::INTERVAL_1_MONTH, $startDate, $endDate, self::FILTER_SPLITS);

        $historicData = $this->resultDecoder->transformSplitDataResult($responseBody);
        usort($historicData, function (SplitData $a, SplitData $b): int {
            // Data is not necessary in order, so ensure ascending order by date
            return $a->getDate() <=> $b->getDate();
        });

        return $historicData;
    }

    /**
     * Get quote for a single symbol.
     */
    public function getQuote(string $symbol): ?Quote
    {
        $list = $this->fetchQuotes([$symbol]);

        return isset($list[0]) ? $list[0] : null;
    }

    /**
     * Get quotes for one or multiple symbols.
     *
     * @return Quote[]
     */
    public function getQuotes(array $symbols): array
    {
        return $this->fetchQuotes($symbols);
    }

    /**
     * Get exchange rate for two currencies. Accepts concatenated ISO 4217 currency codes such as "GBP" or "USD".
     */
    public function getExchangeRate(string $currency1, string $currency2): ?Quote
    {
        $list = $this->getExchangeRates([[$currency1, $currency2]]);

        return isset($list[0]) ? $list[0] : null;
    }

    /**
     * Retrieves currency exchange rates. Accepts concatenated ISO 4217 currency codes such as "GBP" or "USD".
     *
     * @param string[][] $currencyPairs List of pairs of currencies, e.g. [["USD", "GBP"]]
     *
     * @return Quote[]
     */
    public function getExchangeRates(array $currencyPairs): array
    {
        $currencySymbols = array_map(function (array $currencies) {
            return implode($currencies).self::CURRENCY_SYMBOL_SUFFIX; // Currency pairs are suffixed with "=X"
        }, $currencyPairs);

        return $this->fetchQuotes($currencySymbols);
    }

    private function getCookies($forceNew = false): CookieJar
    {
        if (!file_exists($this->cookiesCacheFile) || $forceNew) {
            $client = new Client([
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);

            $this->cookieJar = new CookieJar();

            // Request Yahoo Finance to get cookies
            $client->request('GET', 'https://finance.yahoo.com/quote/AAPL', [
                'cookies' => $this->cookieJar,
                'headers' => $this->getHeaders(),
                'http_errors' => false,
            ]);
            $c = null;
            foreach ($this->cookieJar->toArray() as $key => $cookie) {
                $c[$cookie['Name']] = $cookie['Value'];
            }
            file_put_contents($this->cookiesCacheFile, json_encode($c));

            return CookieJar::fromArray(json_decode(json_encode($c), true), '.yahoo.com');
        }
        $data = file_get_contents($this->cookiesCacheFile);
        $data = json_decode($data, true);
        $cookies = $this->cookieJarToArray($data);

        return $cookies;
    }

    private function cookieJarToArray($cookies)
    {
        $cookieData = [];
        foreach ($cookies as $key => $cookie) {
            if (isset($cookie['Name'])) {
                $cookieData[$cookie['Name']] = $cookie['Value'];
            } else {
                $cookieData[$key] = $cookie;
            }
        }
        $this->cookieJar = CookieJar::fromArray($cookieData, '.yahoo.com');

        return $this->cookieJar;
    }

    /**
     * Get the crumb value from the Yahoo Finance API.
     */
    private function getCrumb($qs = 1, $forceRefresh = false)
    {
        if ($forceRefresh || !file_exists($this->crumbCacheFile)) {
            $client = new Client([
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'cookies' => $this->getCookies(),
                'http_errors' => false,
            ]);

            // Then get crumb
            $res = $client->request('GET', 'https://query'.(string) $qs.'.finance.yahoo.com/v1/test/getcrumb');
            $crumb = (string) $res->getBody();

            file_put_contents($this->crumbCacheFile, $crumb);

            return $crumb;
        }

        return file_get_contents($this->crumbCacheFile);
    }

    /**
     * Fetch quote data from API.
     *
     * @return Quote[]
     */
    private function fetchQuotes(array $symbols)
    {
        $qs = $this->getRandomQueryServer();

        $crumb = $this->getCrumb();

        $url = 'https://query'.$qs.'.finance.yahoo.com/v7/finance/quote?symbols='.urlencode(implode(',', $symbols)).'&crumb='.$crumb;

        try {
            $responseBody = (string) $this->client->request('GET', $url, [
                'cookies' => $this->getCookies(),
                'headers' => $this->getHeaders(),
            ])->getBody();

            return $this->resultDecoder->transformQuotes($responseBody);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Retry once if Unauthorized
            if (401 === $e->getResponse()->getStatusCode()) {
                $this->refreshCookiesAndCrumb();
                $crumb = $this->getCrumb();

                $url = 'https://query'.$qs.'.finance.yahoo.com/v7/finance/quote?symbols='.urlencode(implode(',', $symbols)).'&crumb='.$crumb;
                $responseBody = (string) $this->client->request('GET', $url, [
                    'cookies' => $this->getCookies(),
                    'headers' => $this->getHeaders(),
                ])->getBody();

                return $this->resultDecoder->transformQuotes($responseBody);
            }

            throw $e;
        }
    }

    private function getHistoricalDataResponseBodyJson(string $symbol, string $interval, \DateTimeInterface $startDate, \DateTimeInterface $endDate, string $filter): string
    {
        $qs = $this->getRandomQueryServer();
        $dataUrl = 'https://query'.$qs.'.finance.yahoo.com/v8/finance/chart/'.urlencode($symbol).'?period1='.$startDate->getTimestamp().'&period2='.$endDate->getTimestamp().'&interval='.$interval.'&events='.$filter;

        return (string) $this->client->request('GET', $dataUrl, ['headers' => $this->getHeaders()])->getBody();
    }

    private function validateIntervals(string $interval): void
    {
        $allowedIntervals = [self::INTERVAL_1_DAY, self::INTERVAL_1_WEEK, self::INTERVAL_1_MONTH];
        if (!\in_array($interval, $allowedIntervals)) {
            throw new \InvalidArgumentException(\sprintf('Interval must be one of: %s', implode(', ', $allowedIntervals)));
        }
    }

    private function validateDates(\DateTimeInterface $startDate, \DateTimeInterface $endDate): void
    {
        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('Start date must be before end date');
        }
    }

    private function getRandomQueryServer(): int
    {
        return rand(1, 2);
    }

    public function stockSummary(string $symbol): array
    {
        $qs = $this->getRandomQueryServer();

        // Initialize session cookies
        $cookieJar = $this->getCookies();

        // Get crumb value
        $crumb = $this->getCrumb($qs, $cookieJar);

        // Fetch quotes
        $modules = 'financialData,quoteType,defaultKeyStatistics,assetProfile,summaryDetail';
        $url = 'https://query'.$qs.'.finance.yahoo.com/v10/finance/quoteSummary/'.$symbol.'?crumb='.$crumb.'&modules='.$modules;
        $responseBody = (string) $this->client->request('GET', $url, ['cookies' => $this->getCookies(), 'headers' => $this->getHeaders()])->getBody();

        return $this->resultDecoder->transformQuotesSummary($responseBody);
    }

    public function getOptionChain(string $symbol, ?\DateTimeInterface $expiryDate = null): array
    {
        $qs = $this->getRandomQueryServer();

        // Initialize session cookies
        $this->cookieJar = $this->getCookies();

        // Get crumb value
        $crumb = $this->getCrumb($qs);

        // Fetch options
        $url = 'https://query'.$qs.'.finance.yahoo.com/v7/finance/options/'.$symbol.'?crumb='.$crumb;
        if ($expiryDate) {
            $url .= '&date='.(string) $expiryDate->getTimestamp();
        }
        try {
            $responseBody = (string) $this->client->request('GET', $url, ['cookies' => $this->getCookies()])->getBody();

            return $this->resultDecoder->transformOptionChains($responseBody);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if (401 === $e->getResponse()->getStatusCode()) {
                $this->refreshCookiesAndCrumb();

                // Rebuild URL with updated crumb
                $crumb = $this->getCrumb();
                $url = 'https://query'.$qs.'.finance.yahoo.com/v7/finance/options/'.$symbol.'?crumb='.$crumb;
                if ($expiryDate) {
                    $url .= '&date='.$expiryDate->getTimestamp();
                }
                $this->registerClient();

                $responseBody = (string) $this->client->request('GET', $url, [
                    'cookies' => $this->getCookies(),
                    'headers' => $this->getHeaders(),
                ])->getBody();

                return $this->resultDecoder->transformOptionChains($responseBody);
            }

            throw $e;
        } catch(ApiException $e) {
            if($e->getMessage() == "Yahoo Search API returned an invalid result."){
                $this->refreshCookiesAndCrumb();

                $this->registerClient();

                // Rebuild URL with updated crumb
                $crumb = file_get_contents($this->crumbCacheFile);
                $url = 'https://query' . $qs . '.finance.yahoo.com/v7/finance/options/' . $symbol . '?crumb=' . $crumb;
                if ($expiryDate) {
                    $url .= '&date=' . $expiryDate->getTimestamp();
                }

                $responseBody = (string) $this->client->request('GET', $url, [
                    'cookies' => $this->getCookies(),
                    'headers' => $this->getHeaders()
                ])->getBody();

                return $this->resultDecoder->transformOptionChains($responseBody);
            }
            throw $e;
        }
    }

    private function refreshCookiesAndCrumb(): void
    {
        $qs = $this->getRandomQueryServer();

        // Fetch crumb using the same cookieJar
        $this->getCookies(true);
        $this->getCrumb($qs, true);

        return;
    }

    public function registerClient()
    {
        $this->client->request('GET', 'https://finance.yahoo.com/', [
            'headers' => ['User-Agent' => $this->userAgent],
        ]);
    }
}
