<?php
namespace Helix\Core\Exceptions;

class MissingDependencyException extends \RuntimeException {
    protected $message = 'Dependency Not Found';
}
