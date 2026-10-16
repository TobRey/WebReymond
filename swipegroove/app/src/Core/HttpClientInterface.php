<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

interface HttpClientInterface
{
    /**
     * @param array<string,string> $headers
     * @param array{timeout?:int} $options
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse;
}
