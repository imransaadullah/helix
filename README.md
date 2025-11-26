# Helix

![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Tests](https://img.shields.io/badge/tests-passing-brightgreen)

Helix is a modern PHP library designed to simplify web application development by providing a robust set of tools and utilities for common tasks. It focuses on providing a modular, multi-tenant application framework with strong emphasis on security and flexibility.

## Features

- **Plugin Architecture**: Dynamic functionality extensions
- **Role-Based Access Control (RBAC)**: Built-in security with policy engine
- **Hybrid Deployment Support**: Flexible deployment options (SaaS, on-premise, and edge)
- **Modern Architecture**:
  - Custom PSR-11 compliant Dependency Injection Container
  - Event-driven architecture with hooks
  - Multi-tenant support with container-level isolation
  - Phase-based initialization system

## Requirements

- PHP 8.2 or higher
- JSON PHP Extension
- Composer

## Installation

Install Helix using Composer:

```bash
composer require progrmanial/helix
```

## Component Usage Guide

### Dependency Injection Container

The container implements PSR-11 and provides advanced dependency management:

```php
use Helix\Core\Container\HelixContainer;

$container = new HelixContainer();

// Simple binding
$container->add(LoggerInterface::class, FileLogger::class);

// Singleton binding
$container->singleton(Database::class, function() {
    return new Database('connection_string');
});

// Factory binding
$container->factory(Request::class, function() {
    return Request::createFromGlobals();
});

// Contextual binding
$container->when(UserController::class)
    ->needs(LoggerInterface::class)
    ->give(function() {
        return new FileLogger('users.log');
    });

// Resolving dependencies
$userController = $container->get(UserController::class);
```

### Database ORM

The database layer provides an intuitive Active Record pattern implementation:

```php
use Helix\Database\Model;
use Helix\Database\Relations\HasMany;

class User extends Model
{
    protected static string $table = 'users';
    protected static bool $timestamps = true;
    protected static bool $softDeletes = true;

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

// Create
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Read
$user = User::find(1);
$activeUsers = User::where('status', '=', 'active')->get();

// Update
$user->name = 'Jane Doe';
$user->save();

// Delete
$user->delete();

// Relationships
$userPosts = $user->posts()->where('status', '=', 'published')->get();

// Soft deletes & restore
$trashed = User::withTrashed()->find($user->getKey());
$trashed->restore();

// Eager loading
$users = User::query()->with('posts.comments')->get();
```

### Query Builder

For more complex queries, use the fluent query builder:

```php
use Helix\Database\QueryBuilder;

$users = QueryBuilder::table('users')
    ->select(['id', 'name', 'email'])
    ->where('status', '=', 'active')
    ->where(function($query) {
        $query->where('role', '=', 'admin')
              ->orWhere('role', '=', 'moderator');
    })
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Joins
$posts = QueryBuilder::table('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->where('users.status', '=', 'active')
    ->select(['posts.*', 'users.name as author'])
    ->get();
```

### Router & Middleware

The routing system supports clean and flexible route definitions:

```php
use Helix\Routing\Router;
use Helix\Http\Request;
use Helix\Http\Response;

$router = new Router($container);

// Basic routes
$router->get('/', [HomeController::class, 'index']);
$router->post('/users', [UserController::class, 'store']);

// Route groups
$router->group('/api', function(Router $router) {
    $router->group('/v1', function(Router $router) {
        $router->get('/users', [UserApiController::class, 'index']);
        $router->post('/users', [UserApiController::class, 'store']);
    }, ['api.auth']); // Apply middleware to group
});

// Custom middleware
class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!$request->hasValidToken()) {
            return new Response(401, ['Unauthorized']);
        }
        return $next($request);
    }
}

$router->addMiddleware('auth', AuthMiddleware::class);
```

### Event System

The event system enables loose coupling between components:

```php
use Helix\Events\Dispatcher;
use Helix\Events\Event;

class UserRegistered extends Event
{
    public function __construct(public readonly User $user) {}
}

// Register listeners
$dispatcher = new Dispatcher();
$dispatcher->addListener(UserRegistered::class, function(UserRegistered $event) {
    // Send welcome email
    $mailer->sendWelcomeEmail($event->user);
});

// Dispatch events
$dispatcher->dispatch(new UserRegistered($user));
```

### Configuration Management

Handle configuration with environment support:

```php
use Helix\Core\Conf\ConfigLoader;

$config = new ConfigLoader();

// Load configuration
$config->load(
    envFile: '.env',
    configFiles: [
        'config/database.php',
        'config/app.php'
    ],
    useEnvironmentSuffix: true
);

// Access configuration
$dbHost = $config->get('database.host');
$appName = $config->get('app.name', 'Helix App'); // With default value
```

### Multi-tenancy

Handle multiple tenants in your application:

```php
use Helix\Database\ConnectionManager;

// Setup connections
$manager = new ConnectionManager();
$manager->addConnection(
    'tenant1',
    'mysql://localhost/tenant1_db',
    'user',
    'password',
    ['charset' => 'utf8mb4']
);

// Switch connections
Model::setConnection($manager->getConnection('tenant1'));
```

## Testing

Run the automated test suite after installing dependencies:

```bash
composer install
vendor/bin/phpunit
```

> **Note:** The database integration tests rely on the PDO SQLite driver. If it is not available, the suite will automatically skip those tests.

## Dependencies

### Core Dependencies
- firebase/php-jwt: JWT authentication
- guzzlehttp/psr7: PSR-7 HTTP message implementation
- monolog/monolog: Logging
- symfony/event-dispatcher: Event handling
- vlucas/phpdotenv: Environment configuration
- ramsey/uuid: UUID generation

### Development Dependencies
- phpunit/phpunit: Testing
- phpstan/phpstan: Static analysis
- mockery/mockery: Mocking framework
- vimeo/psalm: Static analysis
- friendsofphp/php-cs-fixer: Code style fixing

## Standards Compliance

- PSR-4 (Autoloading)
- PSR-11 (Container Interface)
- PSR-12 (Coding Style)
- OWASP Top 10 security guidelines
- GDPR Article 32 compliance

## License

This project is licensed under the MIT License.

## Author

- **Imran Saadullah** - [imransaadullah@gmail.com](mailto:imransaadullah@gmail.com)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Security

If you discover any security-related issues, please email imransaadullah@gmail.com instead of using the issue tracker.

## Keywords

PHP, Library, Web Application Development, Routing, Middleware, Dependency Injection 