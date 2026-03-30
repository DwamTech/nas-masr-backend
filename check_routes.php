<?php
// Check what the 'api' middleware group actually contains at runtime
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$ref = new ReflectionClass($kernel);

// Try to get middleware groups
$router = app('router');
$groups = $router->getMiddlewareGroups();
echo "=== API Middleware Group ===" . PHP_EOL;
foreach (($groups['api'] ?? []) as $m) {
    echo "  - " . $m . PHP_EOL;
}

// Also check global middleware
echo PHP_EOL . "=== Global Middleware ===" . PHP_EOL;
$prop = $ref->getProperty('middleware');
$prop->setAccessible(true);
$globalMiddleware = $prop->getValue($kernel);
foreach ($globalMiddleware as $m) {
    echo "  - " . $m . PHP_EOL;
}
