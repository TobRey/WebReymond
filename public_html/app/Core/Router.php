<?php

declare(strict_types=1);

namespace WebAtze\Core;

use Closure;

/**
 * Verteilt eingehende Adressen auf die zuständigen Klassen.
 *
 * Muster: '/projekt/{id}' oder '/vorschau/{token}/{pfad:.*}'
 * Ohne Typangabe passt ein Platzhalter auf alles ausser Schrägstrich.
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,names:list<string>,handler:mixed,middleware:list<string>}> */
    private array $routes = [];

    /** @var list<string> */
    private array $groupMiddleware = [];

    public function get(string $pattern, mixed $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    /** Für Adressen, die sowohl das Formular zeigen als auch entgegennehmen. */
    public function any(string $pattern, mixed $handler): self
    {
        $this->add('GET', $pattern, $handler);
        return $this->add('POST', $pattern, $handler);
    }

    /** Mehrere Routen mit derselben Vorprüfung, z.B. 'auth'. */
    public function group(array $middleware, Closure $definition): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($previous, $middleware);
        $definition($this);
        $this->groupMiddleware = $previous;
    }

    private function add(string $method, string $pattern, mixed $handler): self
    {
        [$regex, $names] = self::compile($pattern);

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'names' => $names,
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
        ];

        return $this;
    }

    /**
     * Passende Route suchen und ausführen.
     * Gibt null zurück, wenn nichts passt – der Aufrufer zeigt dann 404.
     */
    public function dispatch(Request $request): ?Response
    {
        $path = $request->path();
        $method = $request->method();
        $pathMatchedButWrongMethod = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatchedButWrongMethod = true;
                continue;
            }

            $params = [];
            foreach ($route['names'] as $index => $name) {
                $params[$name] = $matches[$index + 1] ?? '';
            }
            $request->setRouteParams($params);

            foreach ($route['middleware'] as $middleware) {
                $result = Middleware::run($middleware, $request);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            return self::call($route['handler'], $request);
        }

        if ($pathMatchedButWrongMethod) {
            return Response::text('Diese Adresse erwartet eine andere Anfrageart.', 405)
                ->header('Allow', 'GET, POST');
        }

        return null;
    }

    /**
     * '/projekt/{id}' -> Regex + Liste der Platzhalternamen
     *
     * Feste Textstücke werden maskiert, Platzhalter zu Fanggruppen.
     * Wichtig: nur die festen Teile maskieren, sonst wird der Platzhalter
     * selbst unbrauchbar.
     */
    private static function compile(string $pattern): array
    {
        $names = [];
        $regex = '';
        $offset = 0;

        preg_match_all(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}/',
            $pattern,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $placeholder = $match[0][0];
            $start = $match[0][1];

            $regex .= preg_quote(substr($pattern, $offset, $start - $offset), '#');

            $names[] = $match[1][0];
            $constraint = isset($match[2]) && $match[2][1] !== -1 && $match[2][0] !== ''
                ? $match[2][0]
                : '[^/]+';
            $regex .= '(' . $constraint . ')';

            $offset = $start + strlen($placeholder);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return ['#^' . $regex . '$#u', $names];
    }

    private static function call(mixed $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request);
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $fqcn = 'WebAtze\\Http\\' . $class;
            $controller = new $fqcn();
            $result = $controller->{$method}($request);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$object, $method] = $handler;
            $result = $object->{$method}($request);
        } else {
            throw new \RuntimeException('Route hat keinen gültigen Zielverweis.');
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::make('', 204);
    }
}
