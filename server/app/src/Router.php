<?php
declare(strict_types=1);
namespace LTI;
final class Router {
    private array $routes = [];
    public function add(string $method, string $pattern, callable $h): void { $this->routes[] = [$method, '#^' . preg_replace_callback('#\{(\w+)\}#', function ($m) { return $m[1] === 'token' ? '(?P<token>[a-f0-9]{32})' : '(?P<' . $m[1] . '>[0-9]+)'; }, str_replace('.', '\.', $pattern)) . '$#', $h]; }
    public function dispatch(string $method, string $path) {
        $allowed = false;
        foreach ($this->routes as [$m, $re, $h]) {
            if (preg_match($re, $path, $mm)) { $allowed = true; if ($m === $method) return $h(array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY)); }
        }
        throw new HttpError($allowed ? 405 : 404, $allowed ? 'Method not allowed' : 'Not found');
    }
}
