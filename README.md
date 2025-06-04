# Helix

Helix is a modern PHP library designed to simplify web application development by providing a robust set of tools and utilities for common tasks. It focuses on providing a modular, multi-tenant application framework with strong emphasis on security and flexibility.

## Features

- **Plugin Architecture**: Dynamic functionality extensions
- **Role-Based Access Control (RBAC)**: Built-in security with policy engine
- **Hybrid Deployment Support**: Flexible deployment options (SaaS, on-premise, and edge)
- **Modern Architecture**:
  - PSR-11 compliant Dependency Injection
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

## Key Components

- **Bootstrapper**: Priority-based phase execution system with critical failure handling
- **Service Container**: PSR-11 compliant dependency injection container
- **Event System**: Flexible event-driven architecture
- **Module System**: Declarative module manifests with dependency resolution
- **Security**: JWT authentication and policy-based RBAC

## Dependencies

### Core Dependencies
- firebase/php-jwt: JWT authentication
- guzzlehttp/psr7: PSR-7 HTTP message implementation
- league/container: Dependency injection container
- monolog/monolog: Logging
- symfony/event-dispatcher: Event handling
- vlucas/phpdotenv: Environment configuration

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