<?php

namespace Helix\Core\Exceptions;

// Ensure base exception classes are loaded
require_once __DIR__ . '/HttpException.php';

// Re-export the class from HttpException.php for autoloading
if (!class_exists(MethodNotAllowedException::class, false)) {
    // The class is already defined in HttpException.php
    // This file just ensures it's loaded via autoloader
}
