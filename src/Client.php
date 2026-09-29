<?php

declare(strict_types=1);

namespace Ogmake;

/**
 * Thin client. Transport is a callable
 * `fn(string $method, string $url, array $headers, ?string $body, float $timeout): array{0:int,1:array<string,string>,2:string}`
 * (status, lower-cased response headers, body); defaults to curl. Inject your own (PSR-18 adapter, tests).
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://ogmake.com';
    private const MAX_FREE_RENDER_BYTES = 5 * 1024 * 1024;

    private string $baseUrl;
    /** @var callable */
    private $transport;

    public function __construct(
        private readonly ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 20.0,
        ?callable $transport = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /**
     * Builds `{base}/i/{keyId}/{sig}?{canonical}`. `$signingSecret` is the key's base64 signing secret.
     *
     * @param array<string, string|int|float> $params
     */
    public static function signedImageUrl(
        string $keyId,
        string $signingSecret,
        string $template,
        array $params = [],
        ?string $format = null,
        ?string $preset = null,
        ?string $scale = null,
        ?string $v = null,
        ?string $bg = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ): string {
        $query = $params;
        $query['template'] = $template;
        foreach (['format' => $format, 'preset' => $preset, 'scale' => $scale, 'v' => $v, 'bg' => $bg] as $k => $val) {
            if ($val !== null && $val !== '') {
                $query[$k] = $val;
            }
        }
        $canonical = Signing::canonicalQuery($query);
        $sig = Signing::signQuery(Signing::decodeSecret($signingSecret), $canonical);

        return rtrim($baseUrl, '/') . '/i/' . rawurlencode($keyId) . '/' . $sig . '?' . $canonical;
    }

    /** Unsigned `{base}/p/{keyId}/{templateId}` (key must be public and allow-list the template). */
    public static function publicImageUrl(string $keyId, string $templateId, string $baseUrl = self::DEFAULT_BASE_URL): string
    {
        return rtrim($baseUrl, '/') . '/p/' . rawurlencode($keyId) . '/' . rawurlencode($templateId);
    }

    /**
     * POST /v1/images (stored mode).
     *
     * @param array<string, string|int|list<string>> $params
     * @return array<string, mixed> url, hash, cached, width, height, format, bytes, templateVersion
     */
    public function render(
        string $template,
        array $params = [],
        ?string $format = null,
        ?string $preset = null,
        ?string $scale = null,
        ?string $v = null,
        ?string $bg = null,
    ): array {
        $body = ['template' => $template];
        if ($params !== []) {
            $body['params'] = $params;
        }
        foreach (['format' => $format, 'preset' => $preset, 'scale' => $scale, 'v' => $v, 'bg' => $bg] as $k => $val) {
            if ($val !== null && $val !== '') {
                $body[$k] = $val;
            }
        }
        [, , $raw] = $this->request('POST', '/v1/images', json_encode($body, JSON_THROW_ON_ERROR), true);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * POST /v1/images with raw HTML (2 credits). JavaScript never runs server-side.
     *
     * @return array<string, mixed> url, hash, width, height, format, bytes, credits
     */
    public function renderHtml(string $html, int $width, int $height, ?string $css = null, ?string $format = null): array
    {
        $body = ['html' => $html, 'width' => $width, 'height' => $height];
        if ($css !== null && $css !== '') {
            $body['css'] = $css;
        }
        if ($format !== null && $format !== '') {
            $body['format'] = $format;
        }
        [, , $raw] = $this->request('POST', '/v1/images', json_encode($body, JSON_THROW_ON_ERROR), true);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * POST /v1/screenshot of a public page (3 credits). $format: png, jpg or webp.
     *
     * @return array<string, mixed> url, hash, width, height, fullPage, format, bytes, credits
     */
    public function screenshot(string $url, int $width, ?int $height = null, ?bool $fullPage = null, ?string $format = null): array
    {
        $body = ['url' => $url, 'width' => $width];
        if ($height !== null) {
            $body['height'] = $height;
        }
        if ($fullPage !== null) {
            $body['fullPage'] = $fullPage;
        }
        if ($format !== null && $format !== '') {
            $body['format'] = $format;
        }
        [, , $raw] = $this->request('POST', '/v1/screenshot', json_encode($body, JSON_THROW_ON_ERROR), true);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function listTemplates(): array
    {
        [, , $raw] = $this->request('GET', '/v1/templates', null, false);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function getUsage(): array
    {
        [, , $raw] = $this->request('GET', '/v1/usage', null, true);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** GET /v1/free/og. @return array{bytes: string, contentType: string} */
    public function freeRender(string $title, ?string $description = null, ?string $site = null): array
    {
        $q = ['title' => $title];
        if ($description) {
            $q['description'] = $description;
        }
        if ($site) {
            $q['site'] = $site;
        }
        [, $headers, $raw] = $this->request('GET', '/v1/free/og?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986), null, false);
        $ct = $headers['content-type'] ?? '';
        if (!str_starts_with(strtolower($ct), 'image/')) {
            throw new OgmakeException("ogmake free render returned an unexpected content-type: \"{$ct}\"");
        }
        if (strlen($raw) > self::MAX_FREE_RENDER_BYTES) {
            throw new OgmakeException('ogmake free render exceeds ' . self::MAX_FREE_RENDER_BYTES . ' bytes');
        }

        return ['bytes' => $raw, 'contentType' => $ct];
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    private function request(string $method, string $path, ?string $body, bool $auth): array
    {
        $headers = ['user-agent' => 'ogmake-php/' . self::VERSION];
        if ($auth) {
            if ($this->apiKey === null || $this->apiKey === '') {
                throw new ApiException('unauthenticated', 0);
            }
            $headers['authorization'] = 'Bearer ' . $this->apiKey;
        }
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
        }
        [$status, $respHeaders, $raw] = ($this->transport)($method, $this->baseUrl . $path, $headers, $body, $this->timeout);
        if ($status >= 300 && $status < 400) {
            throw new RedirectException($status, $respHeaders['location'] ?? null);
        }
        if ($status < 200 || $status >= 300) {
            throw self::toApiException($raw, $status);
        }

        return [$status, $respHeaders, $raw];
    }

    private static function toApiException(string $raw, int $status): ApiException
    {
        $parsed = json_decode($raw, true);
        $code = 'unknown_error';
        $detail = null;
        if (is_array($parsed) && !array_is_list($parsed)) {
            if (isset($parsed['error']) && is_string($parsed['error']) && $parsed['error'] !== '') {
                $code = $parsed['error'];
            }
            if (isset($parsed['detail']) && is_array($parsed['detail'])) {
                $detail = array_values(array_filter(
                    $parsed['detail'],
                    static fn ($d) => is_array($d) && is_string($d['field'] ?? null) && is_string($d['message'] ?? null),
                ));
                $detail = $detail === [] ? null : $detail;
            }
        }

        return new ApiException($code, $status, $detail);
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            throw new NetworkException($err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $respHeaders, (string) $raw];
    }
}
