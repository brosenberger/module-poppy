<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace BroCode\Poppy\Model\Http;

use BroCode\Poppy\Exception\FetchException;
use Magento\Framework\App\DeploymentConfig;
use Laminas\Uri\Http as HttpUri;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Fetches a JSON object from an agent-controlled https URL without exposing internal addresses.
 *
 * https only, no redirects, 3 s timeout, 64 KB cap. Only LDH host names and IP literals are
 * accepted, so the name checked here is the name curl connects to (no percent-encoding). The host
 * is resolved once, every address must be public, and the connection is pinned to the first one
 * with CURLOPT_CONNECT_TO for any host curl parses, so a second DNS answer (rebinding) or a parser
 * difference cannot point curl elsewhere.
 */
class SafeFetcher
{
    public const CONFIG_ALLOW_PRIVATE = 'brocode_poppy/allow_private_addresses';
    public const MAX_BYTES = 65536;
    public const TIMEOUT_SECONDS = 3;
    public const DEFAULT_MAX_AGE = 300;
    public const MIN_MAX_AGE = 60;
    public const MAX_MAX_AGE = 3600;
    public const USER_AGENT = 'BroCode-Poppy/0.1 (+https://brocode.at)';

    /**
     * One LDH label (RFC 1123): letters, digits and inner hyphens, 1 to 63 characters.
     */
    private const LDH_LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * @param CurlFactory $curlFactory
     * @param HostResolver $hostResolver
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly HostResolver $hostResolver,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Fetch the URL and decode its body as a JSON object.
     *
     * @param string $url
     * @return FetchResult
     * @throws FetchException
     */
    public function fetchJson(string $url): FetchResult
    {
        // Control characters and spaces: curl refuses NUL with a ValueError, the others split parsers.
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new FetchException('URL contains control characters or spaces.');
        }
        try {
            $uri = new HttpUri($url);
        } catch (\Throwable $e) {
            throw new FetchException('Malformed URL.', 0, $e);
        }
        if (strtolower((string)$uri->getScheme()) !== 'https') {
            throw new FetchException('Only absolute https URLs may be fetched.');
        }
        $host = $this->checkedHost((string)$uri->getHost());
        $port = (int)($uri->getPort() ?: 443);

        $address = $this->pinnableAddress($host);
        $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;

        try {
            $curl = $this->curlFactory->create();
            $curl->setOptions([
                // Empty host and port: every connection curl makes goes to the checked address.
                CURLOPT_CONNECT_TO => ['::' . $pinned . ':' . $port],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                // MAXFILESIZE is not enforced for every response on every libcurl version; this is.
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static fn ($handle, $total, $downloaded): int =>
                    $downloaded > self::MAX_BYTES ? 1 : 0,
            ]);
            $curl->addHeader('Accept', 'application/json');
            $curl->addHeader('User-Agent', self::USER_AGENT);
            $curl->get($url);
        } catch (\Throwable $e) {
            throw new FetchException('Fetch failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $curl->getStatus();
        if ($status !== 200) {
            throw new FetchException(
                $status >= 300 && $status < 400
                    ? 'Redirects are not followed (status ' . $status . ').'
                    : 'Unexpected status ' . $status . '.'
            );
        }

        $body = (string)$curl->getBody();
        if (strlen($body) > self::MAX_BYTES) {
            throw new FetchException('Response body exceeds ' . self::MAX_BYTES . ' bytes.');
        }

        return new FetchResult($this->decodeObject($body), $this->maxAge($curl->getHeaders()));
    }

    /**
     * The lowercase host if it is an IP literal or an LDH host name; anything else is refused.
     *
     * Percent-encoding, underscores, empty labels and a trailing dot are refused, and so is a
     * numeric last label (127.1, 0x7f.1), which resolvers read as an IPv4 address.
     *
     * @param string $host Host as the URI parser returned it, IPv6 in brackets
     * @return string Without brackets
     * @throws FetchException
     */
    private function checkedHost(string $host): string
    {
        $host = strtolower($host);
        if (preg_match('/^\[([0-9a-f:.]+)\]\z/', $host, $match)) {
            if (filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new FetchException('Malformed IPv6 literal.');
            }

            return $match[1];
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        $labels = explode('.', $host);
        if (strlen($host) > 253
            || !preg_match('/^' . self::LDH_LABEL . '(?:\.' . self::LDH_LABEL . ')*\z/', $host)
            || preg_match('/^(?:[0-9]+|0x[0-9a-f]*)\z/', (string)end($labels))
        ) {
            throw new FetchException('Host is not a valid host name or IP address.');
        }

        return $host;
    }

    /**
     * Resolve the host and return the address to pin, refusing any non-public answer.
     *
     * @param string $host
     * @return string
     * @throws FetchException
     */
    private function pinnableAddress(string $host): string
    {
        try {
            $addresses = $this->hostResolver->resolve($host);
        } catch (\Throwable $e) {
            throw new FetchException('Host could not be resolved.', 0, $e);
        }
        if ($addresses === []) {
            throw new FetchException('Host does not resolve.');
        }

        if (!$this->deploymentConfig->get(self::CONFIG_ALLOW_PRIVATE)) {
            foreach ($addresses as $address) {
                // Magento2 standard checks 8.1 compatibility; this module requires PHP >= 8.2 (composer.json).
                // phpcs:ignore PHPCompatibility.Constants.NewConstants.filter_flag_global_rangeFound
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false
                    || $this->isNat64($address)
                    || $this->isNat64LocalUse($address)
                ) {
                    throw new FetchException('Host resolves to a non-public address.');
                }
            }
        }

        return $addresses[0];
    }

    /**
     * Whether the address is in the NAT64 well-known prefix 64:ff9b::/96 (RFC 6052).
     *
     * FILTER_FLAG_GLOBAL_RANGE accepts it, but a NAT64 gateway maps it onto any IPv4 address,
     * private ones included.
     *
     * @param string $address
     * @return bool
     */
    private function isNat64(string $address): bool
    {
        $packed = inet_pton($address);

        return $packed !== false && strlen($packed) === 16
            && substr($packed, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\x00", 8);
    }

    /**
     * Whether the address is in the NAT64 local-use prefix 64:ff9b:1::/48 (RFC 8215).
     *
     * Like 64:ff9b::/96 it passes FILTER_FLAG_GLOBAL_RANGE and maps onto IPv4 addresses.
     *
     * @param string $address
     * @return bool
     */
    private function isNat64LocalUse(string $address): bool
    {
        $packed = inet_pton($address);

        return $packed !== false && strlen($packed) === 16 && substr($packed, 0, 6) === "\x00\x64\xff\x9b\x00\x01";
    }

    /**
     * Decode the body, which must be a JSON object.
     *
     * @param string $body
     * @return array<string,mixed>
     * @throws FetchException
     */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FetchException('Response is not valid JSON.', 0, $e);
        }
        if (!$decoded instanceof \stdClass) {
            throw new FetchException('Response is not a JSON object.');
        }

        /** @var array<string,mixed> $object */
        $object = json_decode($body, true, 64, JSON_THROW_ON_ERROR);

        return $object;
    }

    /**
     * Cache lifetime from Cache-Control, clamped to 60..3600 s; 300 s when absent.
     *
     * @param array<string,mixed> $headers
     * @return int
     */
    private function maxAge(array $headers): int
    {
        $cacheControl = null;
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'cache-control' && is_string($value)) {
                $cacheControl = $value;
            }
        }
        if ($cacheControl === null) {
            return self::DEFAULT_MAX_AGE;
        }
        if (preg_match('/(?:^|[\s,])no-(?:store|cache)(?:$|[\s,=])/i', $cacheControl)) {
            return self::MIN_MAX_AGE;
        }
        if (!preg_match('/(?:^|[\s,])max-age\s*=\s*"?(\d+)/i', $cacheControl, $match)) {
            return self::DEFAULT_MAX_AGE;
        }

        return max(self::MIN_MAX_AGE, min(self::MAX_MAX_AGE, (int)$match[1]));
    }
}
