<?php
namespace Helix\Core\Exceptions;

class ServiceNotFoundException extends \RuntimeException {
    protected $message = 'Service Not Found';
}
