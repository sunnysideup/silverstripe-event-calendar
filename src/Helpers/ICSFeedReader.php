<?php

namespace UncleCheese\EventCalendar\Helpers;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Safely downloads external ICS feeds.
 *
 * Only http(s) URLs that point to public hosts are fetched, redirects are validated,
 * the download is limited in time and size, and the result is cached so that visitors
 * can not be used to hammer the remote server (or to slow down your own website).
 */
class ICSFeedReader
{
    use Configurable;
    use Injectable;

    /**
     * Seconds a downloaded feed is cached for
     *
     * @var int
     */
    private static $cache_lifetime = 3600;

    /**
     * Seconds a failed download is remembered for
     *
     * @var int
     */
    private static $failure_cache_lifetime = 300;

    /**
     * Network timeout in seconds
     *
     * @var int
     */
    private static $timeout = 5;

    /**
     * Maximum size of a feed in bytes
     *
     * @var int
     */
    private static $max_bytes = 5242880;

    /**
     * Maximum number of redirects that are followed
     *
     * @var int
     */
    private static $max_redirects = 3;

    /**
     * Allow feeds on private / reserved IP ranges (e.g. for an intranet). Only enable this if you trust
     * everyone who can edit feeds in the CMS.
     *
     * @var bool
     */
    private static $allow_private_hosts = false;

    /**
     * Turn webcal:// links into https:// links and trim the URL
     */
    public static function normalise_url(?string $url): string
    {
        $url = trim((string) $url);
        if (preg_match('#^webcals?://#i', $url)) {
            $url = preg_replace('#^webcals?://#i', 'https://', $url);
        }

        return $url;
    }

    /**
     * Is this a URL that we are happy to fetch from the server?
     */
    public static function is_safe_url(?string $url): bool
    {
        $url = self::normalise_url($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (static::config()->get('allow_private_hosts')) {
            return true;
        }

        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
            $ips = array_filter($ips);
            if (!$ips) {
                $resolved = @gethostbynamel($host);
                $ips = $resolved ?: [];
            }
        }
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the (cached) ICS body for a feed URL. Returns null if the feed can not be used.
     */
    public function read(?string $url): ?string
    {
        $url = self::normalise_url($url);
        if ($url === '') {
            return null;
        }

        $cache = $this->getCache();
        $key = 'feed' . sha1($url);
        if ($cache->has($key)) {
            $cached = $cache->get($key);
            return is_string($cached) && $cached !== '' ? $cached : null;
        }

        $body = $this->download($url);
        $cache->set(
            $key,
            (string) $body,
            $body === null ? (int) static::config()->get('failure_cache_lifetime') : (int) static::config()->get('cache_lifetime')
        );

        return $body;
    }

    protected function getCache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.EventCalendarFeeds');
    }

    protected function download(string $url): ?string
    {
        $maxBytes = (int) static::config()->get('max_bytes');
        $maxRedirects = (int) static::config()->get('max_redirects');

        for ($i = 0; $i <= $maxRedirects; $i++) {
            if (!self::is_safe_url($url)) {
                return null;
            }
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => (int) static::config()->get('timeout'),
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'header' => "Accept: text/calendar, text/plain\r\nUser-Agent: silverstripe-event-calendar\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $handle = @fopen($url, 'rb', false, $context);
            if (!$handle) {
                return null;
            }
            $meta = stream_get_meta_data($handle);
            $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            $status = 0;
            $location = null;
            foreach ($headers as $header) {
                if (!is_string($header)) {
                    continue;
                }
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $matches)) {
                    $status = (int) $matches[1];
                    $location = null;
                } elseif (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
            }

            if ($status >= 300 && $status < 400 && $location) {
                fclose($handle);
                $url = $this->resolveRedirect($url, $location);
                continue;
            }

            $body = $status === 200 ? stream_get_contents($handle, $maxBytes + 1) : false;
            fclose($handle);
            if ($body === false || strlen($body) > $maxBytes) {
                return null;
            }

            return $body;
        }

        return null;
    }

    protected function resolveRedirect(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($current);
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $base . $location;
        }

        return $base . rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . $location;
    }
}
