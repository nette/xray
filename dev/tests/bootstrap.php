<?php declare(strict_types=1);

$phpstanAutoloader = require_once 'phar://' . __DIR__ . '/../vendor/phpstan/phpstan/phpstan.phar/vendor/autoload.php';
require_once 'phar://' . __DIR__ . '/../vendor/phpstan/phpstan/phpstan.phar/preload.php';
$phpstanAutoloader->unregister();

require_once __DIR__ . '/../vendor/autoload.php';

$phpstanAutoloader->register(true);

Tester\Environment::setup();
