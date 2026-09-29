<?php

declare(strict_types=1);

namespace Ogmake\Tests;

use Ogmake\ApiException;
use Ogmake\Client;
use Ogmake\NetworkException;
use Ogmake\RedirectException;
use Ogmake\Signing;
use PHPUnit\Framework\TestCase;

// Shared vectors: packages/core/src/signing.test.ts, packages/laravel-ogmake/tests/SigningTest.php
final class ClientTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
    private const CANONICAL = 'format=png&template=basic&title=Hello%20World';
    private const SIG = 'W8Xvrf9UTFKbVVQtofHq7A2ljK9z-nvJUL3B-rB7MY8';

    public function testFixedVector(): void
    {
        $p = ['template' => 'basic', 'title' => 'Hello World', 'format' => 'png', 'sig' => 'x', 'debug' => '1'];
        $this->assertSame(self::CANONICAL, Signing::canonicalQuery($p));
        $this->assertSame(self::SIG, Signing::signQuery(Signing::decodeSecret(self::SECRET), self::CANONICAL));
    }

    public function testAstralVector(): void
    {
        $c = Signing::canonicalQuery(["\u{1F600}" => 'astral', "\u{E000}" => 'pua']);
        $this->assertSame('%F0%9F%98%80=astral&%EE%80%80=pua', $c);
        $this->assertSame('Zr62H77WBS8WhCLT5Nt-vSrQ1Ldiot7_-W1XE335SPU', Signing::signQuery(Signing::decodeSecret(self::SECRET), $c));
    }

    public function testReservedCharsAndBadSecret(): void
    {
        $this->assertSame('q=a%21b%27c%28d%29e%2Af', Signing::canonicalQuery(['q' => "a!b'c(d)e*f"]));
        $this->expectException(\InvalidArgumentException::class);
        Signing::decodeSecret('!!!');
    }

    public function testSignedAndPublicUrl(): void
    {
        $this->assertSame(
            'https://ogmake.com/i/key1/' . self::SIG . '?' . self::CANONICAL,
            Client::signedImageUrl('key1', self::SECRET, 'basic', ['title' => 'Hello World'], format: 'png'),
        );
        $this->assertSame('https://x.test/p/k/a%20b', Client::publicImageUrl('k', 'a b', 'https://x.test/'));
    }

    private function client(int $status, string $body, array $headers = [], ?string $key = 'og_live_x'): Client
    {
        return new Client($key, 'https://api.test', 5.0, fn () => [$status, $headers, $body]);
    }

    public function testRender(): void
    {
        $seen = null;
        $c = new Client('og_live_x', 'https://api.test/', 5.0, function ($m, $u, $h, $b) use (&$seen) {
            $seen = [$m, $u, $h, $b];

            return [200, [], '{"url":"https://ogmake.com/s/h.png","hash":"h"}'];
        });
        $this->assertSame('h', $c->render('basic', ['title' => 't'], format: 'png')['hash']);
        $this->assertSame('POST', $seen[0]);
        $this->assertSame('https://api.test/v1/images', $seen[1]);
        $this->assertSame('Bearer og_live_x', $seen[2]['authorization']);
        $this->assertSame('{"template":"basic","params":{"title":"t"},"format":"png"}', $seen[3]);
    }

    public function testRenderHtml(): void
    {
        $seen = null;
        $c = new Client('og_live_x', 'https://api.test', 5.0, function ($m, $u, $h, $b) use (&$seen) {
            $seen = [$m, $u, $h, $b];

            return [201, [], '{"url":"https://ogmake.com/s/y.png","credits":2}'];
        });
        $this->assertSame(2, $c->renderHtml('<h1>Hi</h1>', 1200, 630, 'h1{color:red}', 'webp')['credits']);
        $this->assertSame('https://api.test/v1/images', $seen[1]);
        $this->assertSame('Bearer og_live_x', $seen[2]['authorization']);
        $this->assertSame('{"html":"<h1>Hi<\/h1>","width":1200,"height":630,"css":"h1{color:red}","format":"webp"}', $seen[3]);
        $c->renderHtml('<p>x</p>', 100, 100);
        $this->assertSame('{"html":"<p>x<\/p>","width":100,"height":100}', $seen[3]);
    }

    public function testScreenshot(): void
    {
        $seen = null;
        $c = new Client('og_live_x', 'https://api.test', 5.0, function ($m, $u, $h, $b) use (&$seen) {
            $seen = [$m, $u, $h, $b];

            return [201, [], '{"url":"https://ogmake.com/s/x.png","credits":3}'];
        });
        $this->assertSame(3, $c->screenshot('https://example.com/', 1280, 800, true, 'jpg')['credits']);
        $this->assertSame('https://api.test/v1/screenshot', $seen[1]);
        $this->assertSame('{"url":"https:\/\/example.com\/","width":1280,"height":800,"fullPage":true,"format":"jpg"}', $seen[3]);
        $c->screenshot('https://example.com/', 1280);
        $this->assertSame('{"url":"https:\/\/example.com\/","width":1280}', $seen[3]);
    }

    public function testApiError(): void
    {
        try {
            $this->client(400, '{"error":"invalid_input","detail":[{"field":"title","message":"required"}]}')->render('bad');
            $this->fail('no throw');
        } catch (ApiException $e) {
            $this->assertSame('invalid_input', $e->errorCode);
            $this->assertSame(400, $e->status);
            $this->assertSame('title', $e->detail[0]['field']);
        }
        try {
            $this->client(500, '<html>')->getUsage();
            $this->fail('no throw');
        } catch (ApiException $e) {
            $this->assertSame('unknown_error', $e->errorCode);
        }
    }

    public function testUnauthenticatedIsLocal(): void
    {
        $this->expectException(ApiException::class);
        $this->client(200, '{}', [], null)->render('basic');
    }

    public function testRedirectAndFree(): void
    {
        try {
            $this->client(302, '', ['location' => 'https://evil.test'])->listTemplates();
            $this->fail('no throw');
        } catch (RedirectException $e) {
            $this->assertSame('https://evil.test', $e->location);
        }
        $r = $this->client(200, 'PNG', ['content-type' => 'image/png'])->freeRender('hi');
        $this->assertSame('image/png', $r['contentType']);
    }

    public function testCurlNetworkError(): void
    {
        $this->expectException(NetworkException::class);
        (new Client('k', 'http://127.0.0.1:1', 2.0))->render('basic');
    }
}
