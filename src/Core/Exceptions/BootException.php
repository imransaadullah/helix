<?php
namespace Helix\Core\Exceptions;

class BootException extends \RuntimeException {
    protected $message = 'System bootstrap failed';
}
