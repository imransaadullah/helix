<?php
namespace Helix\Core\Exceptions;

class RouteNotFoundException extends \RuntimeException {
    protected $message = 'Route Not Found';
}
