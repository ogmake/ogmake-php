# ogmake-php

Framework-agnostic PHP SDK for the [ogmake](https://ogmake.com) OG image API. Requires PHP 8.1+, `ext-curl`, `ext-json`. (Laravel users: see `ogmake/laravel`.)

## Install

```sh
composer config repositories.ogmake vcs https://github.com/ogmake/ogmake-php
composer require ogmake/ogmake-php:dev-main
```

## Examples

Signed URL (computed locally; the signing secret never leaves your process):

```php
use Ogmake\Client;

$url = Client::signedImageUrl('KEY_ID', 'BASE64_SIGNING_SECRET', 'basic', ['title' => 'Hello World'], format: 'png');
```

Public (unsigned) URL for a key marked public with the template allow-listed:

```php
$url = Client::publicImageUrl('KEY_ID', 'basic') . '?title=Hello';
```

Stored render with typed errors:

```php
use Ogmake\{Client, ApiException};

$client = new Client('og_live_...');
try {
    $result = $client->render('basic', ['title' => 'Hello'], format: 'png');
    echo $result['url'];
} catch (ApiException $e) {
    echo $e->errorCode, ' ', $e->status;
}
```

Exceptions: `ApiException` (`errorCode`, `status`, `detail`), `NetworkException`, `RedirectException` (redirects are never followed), all extending `OgmakeException`. Raw HTML render (2 credits): `$client->renderHtml($html, 1200, 630, css: $css, format: 'png')`. Page screenshot (3 credits): `$client->screenshot('https://example.com/', 1280, height: 800, fullPage: false, format: 'png')`. Also `listTemplates()`, `getUsage()`, `freeRender()`. Pass a custom transport callable as the 4th constructor argument to use a PSR-18 client.

Tests: `composer install && vendor/bin/phpunit`.

MIT licensed. Support: support@ogmake.com
