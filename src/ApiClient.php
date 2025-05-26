<?php

declare(strict_types=1);

namespace Scheb\YahooFinanceApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Cookie\SessionCookieJar;
use Psr\Cache\CacheItemPoolInterface;
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

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var CookieJarInterface
     */
    private $cookieJar;

    public function __construct(ClientInterface $guzzleClient, ResultDecoder $resultDecoder, CacheItemPoolInterface $cache)
    {
        $this->client = $guzzleClient;
        $this->resultDecoder = $resultDecoder;
        $this->cache = $cache;
        $this->userAgent = $this->getAgent();
        $this->cookieJar = $this->getCookies();
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

        $response = $this->client->request('GET', $url, ['headers' => $this->getHeader(), 'cookies' => $this->cookieJar]);

        $status = $response->getStatusCode();
        if (200 !== $status) {
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
        @trigger_error('[scheb/yahoo-finance-api] getHistoricalData() is deprecated and will be removed in a future release', \E_USER_DEPRECATED);

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

    private function getCookies(bool $force = false): CookieJar
    {
        $cacheItem = $this->cache->getItem('yahoo_cookies_tmp');

        if (!$cacheItem->isHit() || $force) {
            $jar = new SessionCookieJar('YAHOO_SESSION_TMP', true);
            $this->client->request('GET', 'https://finance.yahoo.com/quote/AAPL', [
                'cookies' => $jar,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.8',
                    'Referer' => 'https://finance.yahoo.com/',
                    'Origin' => 'https://finance.yahoo.com',
                ],
            ]);

            $cacheItem->set(serialize($jar))->expiresAfter(3600);
            $this->cache->save($cacheItem);
            $this->cookieJar = $jar;
        } else {
            $jar = unserialize($cacheItem->get());
        }

        return $jar;
    }

    /**
     * Get the crumb value from the Yahoo Finance API.
     */
    private function getCrumb(int $qs = 1, bool $forceRefresh = false): string
    {
        $cacheItem = $this->cache->getItem('yahoo_crumb');

        if ($forceRefresh || !$cacheItem->isHit()) {
            $jar = new SessionCookieJar('YAHOO_SESSION_TMP', true);

            $client = new Client([
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'cookies' => $this->getCookies(),
            ]);

            $res = $client->request('GET', 'https://query'.$qs.'.finance.yahoo.com/v1/test/getcrumb');
            $crumb = (string) $res->getBody();

            // Overwrite the existing cookies with the new ones obtained during the crumb process
            $cacheItem->set(serialize($jar))->expiresAfter(3600);
            $this->cache->save($cacheItem);
            $this->cookieJar = $jar;

            $cacheItem->set($crumb)->expiresAfter(3600);
            $this->cache->save($cacheItem);

            return $crumb;
        }

        return $cacheItem->get();
    }

    /**
     * Fetch quote data from API.
     *
     * @return Quote[]
     */
    private function fetchQuotes(array $symbols): array
    {
        $qs = $this->getRandomQueryServer();

        $crumb = $this->getCrumb();

        $url = 'https://query'.$qs.'.finance.yahoo.com/v7/finance/quote?symbols='.urlencode(implode(',', $symbols)).'&crumb='.$crumb;

        for ($retries = 0; $retries <= 1; ++$retries) {
            if (1 === $retries) {
                $this->refreshCookiesAndCrumb();
            }
            try {
                $responseBody = (string) $this->client->request('GET', $url, [
                    'cookies' => $this->cookieJar,
                    'headers' => $this->getHeader(),
                ])->getBody();

                return $this->resultDecoder->transformQuotes($responseBody);
            } catch (\Exception $e) {
                // Retry once, if Still Error
                if (1 === $retries) {
                    throw new ApiException("Fail to Fetch data from Yahoo (ERROR {$e->getMessage()})");
                }
            }
        }
        throw new ApiException('Something Want Wrong!');
    }

    private function getHistoricalDataResponseBodyJson(string $symbol, string $interval, \DateTimeInterface $startDate, \DateTimeInterface $endDate, string $filter): string
    {
        $qs = $this->getRandomQueryServer();
        $dataUrl = 'https://query'.$qs.'.finance.yahoo.com/v8/finance/chart/'.urlencode($symbol).'?period1='.$startDate->getTimestamp().'&period2='.$endDate->getTimestamp().'&interval='.$interval.'&events='.$filter;

        return (string) $this->client->request('GET', $dataUrl, ['headers' => $this->getHeader()])->getBody();
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
        $cookieJar = $this->cookieJar;

        // Get crumb value
        $crumb = $this->getCrumb();

        // Fetch quotes
        $modules = 'financialData,quoteType,defaultKeyStatistics,assetProfile,summaryDetail';
        $url = 'https://query'.$qs.'.finance.yahoo.com/v10/finance/quoteSummary/'.$symbol.'?crumb='.$crumb.'&modules='.$modules;
        $responseBody = (string) $this->client->request('GET', $url, [
            'cookies' => $this->cookieJar,
            'headers' => $this->getHeader(),
        ])->getBody();

        return $this->resultDecoder->transformQuotesSummary($responseBody);
    }

    public function getOptionChain(string $symbol, ?\DateTimeInterface $expiryDate = null): array
    {
        $qs = $this->getRandomQueryServer();

        // Get crumb value
        $crumb = $this->getCrumb($qs);

        // Fetch options
        $url = 'https://query'.$qs.'.finance.yahoo.com/v7/finance/options/'.$symbol.'?crumb='.$crumb;
        if ($expiryDate) {
            $url .= '&date='.(string) $expiryDate->getTimestamp();
        }
        for ($try = 0; $try < 2; ++$try) {
            try {
                $responseBody = (string) $this->client->request('GET', $url, ['cookies' => $this->cookieJar])->getBody();

                return $this->resultDecoder->transformOptionChains($responseBody);
            } catch (\Exception $e) {
                if (1 === $try) {
                    throw new ApiException("Fail to Fetch data from Yahoo (ERROR {$e->getMessage()})");
                }
                $this->refreshCookiesAndCrumb();
            }
        }
        throw new ApiException('Something Want Wrong!');
    }

    private function refreshCookiesAndCrumb(): void
    {
        $qs = $this->getRandomQueryServer();

        // Fetch crumb using the same cookieJar
        $this->getAgent(true);
        $this->getCrumb($qs, true);
        // hold for 3 seconds for save from quickly calls api
        sleep(3);

        return;
    }

    // if all working good without this function we will remove it
    // private function registerClient(): void
    // {
    //     $this->client->request('GET', 'https://finance.yahoo.com/', ['headers' => $this->getHeader()]);
    // }

    private function getAgent(bool $forceNew = false): string
    {
        $cacheItem = $this->cache->getItem('yahoo_user_agent');

        if ($forceNew || !$cacheItem->isHit()) {
            $agent = UserAgent::getRandomUserAgent();
            $cacheItem->set($agent)->expiresAfter(3600);
            $this->cache->save($cacheItem);

            return $agent;
        }

        return $cacheItem->get();
    }

    private function getHeader(): array
    {
        return [
            'User-Agent' => $this->userAgent,
        ];
    }
}
