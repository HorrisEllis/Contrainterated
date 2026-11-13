<?php
/**
 * Autoloading, without Composer.
 *
 *   GameMaster\  PSR-4 from src/
 *   Siso\        SISO core 13.1.0, vendored unchanged in siso/ (see
 *                doc/siso-core-13.1.0.sha256). The core's files are loaded by
 *                require_once, as the core itself loads them; they
 *                are not PSR-4 named.
 */

require_once __DIR__ . '/siso/php/code/core/event.php';
require_once __DIR__ . '/siso/php/code/core/gate.php';
require_once __DIR__ . '/siso/php/code/core/rejection.php';
require_once __DIR__ . '/siso/php/code/core/stream.php';
require_once __DIR__ . '/siso/php/code/observer/streamlog.php';

spl_autoload_register(function ($class) {
    $prefix = 'GameMaster\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return false;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
        return true;
    }
    return false;
});
