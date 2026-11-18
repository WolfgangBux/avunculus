<?php

declare(strict_types=1);

/** Sucht zu Methode und Pfad die passende Zeile aus routes.php */
final class Router
{
    /** @param list<array{0:string,1:string,2:class-string,3:string,4:string}> $routes */
    public function __construct(private readonly array $routes) {}

    /**
     * @return array{class: class-string, action: string, role: string, args: list<int>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $path = trim($path, '/');
        foreach ($this->routes as [$verb, $pattern, $class, $action, $role]) {
            if ($verb !== $method) continue;
            $regex = '#^' . preg_replace('#\\\\\{\w+\\\\\}#', '(\d+)', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $path, $m)) {
                return ['class' => $class, 'action' => $action, 'role' => $role, 'args' => array_map('intval', array_slice($m, 1))];
            }
        }
        return null;
    }
}
