<?php

declare(strict_types=1);

namespace RateLimit\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RateLimit\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use RateLimit\Status;

final class RateLimitMiddleware
{
    public const HEADER_LIMIT = 'X-RateLimit-Limit';
    public const HEADER_REMAINING = 'X-RateLimit-Remaining';
    public const HEADER_RESET = 'X-RateLimit-Reset';

    /** @var RateLimiter */
    private $rateLimiter;

    /** @var GetQuotaPolicy */
    private $getQuotaPolicy;

    /** @var ResolveIdentifier */
    private $resolveIdentifier;

    /** @var RequestHandlerInterface */
    private $limitExceededHandler;

    public function __construct(
        RateLimiter $rateLimiter,
        GetQuotaPolicy $getQuotaPolicy,
        ResolveIdentifier $resolveIdentifier,
        RequestHandlerInterface $limitExceededHandler
    ) {
        $this->rateLimiter = $rateLimiter;
        $this->getQuotaPolicy = $getQuotaPolicy;
        $this->resolveIdentifier = $resolveIdentifier;
        $this->limitExceededHandler = $limitExceededHandler;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $quotaPolicy = $this->getQuotaPolicy->forRequest($request);

        if (null === $quotaPolicy) {
            return $handler->handle($request);
        }

        $identifier = $this->resolveIdentifier->fromRequest($request);

        $status = $this->rateLimiter->handle($identifier, $quotaPolicy);

        if ($status->quotaExceeded()) {
            return $this->setRateLimitHeaders($this->limitExceededHandler->handle($request), $status)
                ->withStatus(429);
        }

        return $this->setRateLimitHeaders($handler->handle($request), $status);
    }

    private function setRateLimitHeaders(ResponseInterface $response, Status $rateLimitStatus): ResponseInterface
    {
        return $response
            ->withHeader(self::HEADER_LIMIT, (string) $rateLimitStatus->getQuota())
            ->withHeader(self::HEADER_REMAINING, (string) $rateLimitStatus->getRemainingAttempts())
            ->withHeader(self::HEADER_RESET, (string) $rateLimitStatus->getResetAt()->getTimestamp());
    }
}