<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Kompakter Router mit Platzhaltern: /api/case/{id}/evidence
 */
final class Router
{
    /** @var array<string,array<int,array{pattern:string,params:string[],handler:callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function any(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        /* Platzhalter werden zu Gruppen, alles andere wird woertlich genommen -
           sonst wuerde ein Punkt im Pfad (z. B. "/index.php") jedes Zeichen treffen. */
        $params = [];
        $pattern = preg_replace_callback('~\{([a-zA-Z_]+)\}|[^{]+~', static function (array $m) use (&$params): string {
            if (($m[1] ?? '') !== '') {
                $params[] = $m[1];
                return '([A-Za-z0-9_.\-]+)';
            }
            return preg_quote($m[0], '~');
        }, $path);
        $this->routes[$method][] = [
            'pattern' => '~^' . $pattern . '$~',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $args = [];
                foreach ($route['params'] as $index => $name) {
                    $args[$name] = $matches[$index + 1] ?? '';
                }
                $result = ($route['handler'])($request, $args);
                return $result instanceof Response ? $result : Response::html((string)$result);
            }
        }

        // Existiert der Pfad in einer anderen Methode? -> 405
        foreach ($this->routes as $routeMethod => $routes) {
            if ($routeMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path)) {
                    throw new HttpException(405, 'Methode nicht erlaubt.');
                }
            }
        }

        throw HttpException::notFound();
    }
}
