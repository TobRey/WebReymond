<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

/**
 * Kleiner Router mit Pfadparametern ({id}) und Middleware-Kennzeichen.
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, handler:callable|array, opts:array}> */
    private array $routes = [];

    /** @param callable|array{0:class-string,1:string} $handler */
    public function add(string $method, string $pattern, callable|array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'regex' => $regex, 'handler' => $handler, 'opts' => $opts];
    }

    public function get(string $p, callable|array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable|array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }
    public function put(string $p, callable|array $h, array $o = []): void { $this->add('PUT', $p, $h, $o); }
    public function patch(string $p, callable|array $h, array $o = []): void { $this->add('PATCH', $p, $h, $o); }
    public function delete(string $p, callable|array $h, array $o = []): void { $this->add('DELETE', $p, $h, $o); }

    /** @return array{handler:callable|array, params:array<string,string>, opts:array}|null */
    public function match(string $method, string $path): ?array
    {
        $methodMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($route['method'] === 'GET' && $method === 'HEAD')) {
                $methodMatched = true;
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            return ['handler' => $route['handler'], 'params' => $params, 'opts' => $route['opts']];
        }
        if ($methodMatched) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        return null;
    }
}
