<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Middleware;

use MyPortfolio\Infrastructure\Cache\RedisFactory;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Middleware\RateLimitMiddleware;
use MyPortfolio\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RateLimitMiddlewareTest extends TestCase
{
    public function testSessionPollingHasHighPerSessionLimitWithoutExposingToken(): void
    {
        $firstToken = str_repeat('a', 64);
        $secondToken = str_repeat('b', 64);

        foreach ([
            ['GET', '/api/auth/session/status'],
            ['POST', '/api/auth/session/activity'],
        ] as [$method, $path]) {
            $first = $this->rule($this->request($method, $path, $firstToken));
            $second = $this->rule($this->request($method, $path, $secondToken));

            self::assertSame(120, $first[1]);
            self::assertSame(60, $first[2]);
            self::assertNotSame($first[0], $second[0]);
            self::assertStringNotContainsString($firstToken, $first[0]);
            self::assertStringContainsString(hash('sha256', $firstToken), $first[0]);
        }
    }

    public function testSessionMutationKeepsLowerPerUserLimit(): void
    {
        $rule = $this->rule($this->request(
            'POST',
            '/api/auth/session/lock',
            str_repeat('a', 64),
        ));

        self::assertSame(20, $rule[1]);
        self::assertSame(60, $rule[2]);
        self::assertStringContainsString('user:17', $rule[0]);
    }

    /**
     * @return array{0:string,1:int,2:int}
     */
    private function rule(ServerRequestInterface $request): array
    {
        $config = new Config([]);
        $middleware = new RateLimitMiddleware(
            $config,
            new RedisFactory($config),
            new ResponseFactory(),
            new IpMatcher($config),
        );
        $method = new \ReflectionMethod($middleware, 'ruleFor');
        $rule = $method->invoke(
            $middleware,
            $request->getUri()->getPath(),
            strtoupper($request->getMethod()),
            '127.0.0.1',
            17,
            0,
            $request,
        );
        self::assertIsArray($rule);
        return $rule;
    }

    private function request(string $method, string $path, string $token): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, $token);
    }
}
