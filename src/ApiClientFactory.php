<?php

declare(strict_types=1);

namespace Scheb\YahooFinanceApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class ApiClientFactory
{
    public static function createApiClient(?ClientInterface $guzzleClient = null, ?CacheItemPoolInterface $cache = null): ApiClient
    {
        $guzzleClient = $guzzleClient ? $guzzleClient : new Client();
        $resultDecoder = new ResultDecoder(new ValueMapper());
        $cache = $cache ?? new FilesystemAdapter();

        return new ApiClient($guzzleClient, $resultDecoder, $cache);
    }
}
