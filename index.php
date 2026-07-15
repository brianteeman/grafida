<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

use Boson\Application;
use Boson\ApplicationCreateInfo;
use Boson\Component\Http\Static\FilesystemStaticProvider;
use Boson\WebView\Api\Schemes\Event\SchemeRequestReceived;
use Boson\WebView\WebViewCreateInfo;
use Boson\Window\WindowCreateInfo;
use Boson\Window\WindowDecoration;
use Grafida\Application\ContainerFactory;
use Grafida\FrontController;

require __DIR__ . '/vendor/autoload.php';

// Windows: grafida.exe runs on a console-subsystem PHP runtime (the phpmicro
// SFX is a CLI build), so Windows hands the process a console window. Grafida is
// a GUI app, so hide it immediately. Crucially, this also stops the "flashing
// CMD window on every click": the console subprocesses the backend spawns
// (registry theme probe, DPAPI secret store — see Grafida\Secret\ProcessRunner)
// INHERIT this now-hidden console instead of each allocating a fresh, visible one.
// Best-effort via FFI (already loaded for Boson); any failure just leaves the
// console as-is. Must run before the first proc_open, i.e. before the app boots.
if (\PHP_OS_FAMILY === 'Windows' && \extension_loaded('ffi')) {
    try {
        $kernel32 = \FFI::cdef('void* GetConsoleWindow(void);', 'kernel32.dll');
        $user32   = \FFI::cdef('int ShowWindow(void *hWnd, int nCmdShow);', 'user32.dll');
        $console  = $kernel32->GetConsoleWindow();

        if (!\FFI::isNull($console)) {
            $user32->ShowWindow($console, 0); // SW_HIDE
        }
    } catch (\Throwable) {
        // No console to hide, or FFI unavailable — nothing to do.
    }
}

$app = new Application(new ApplicationCreateInfo(
    schemes: ['boson'],
    debug: (bool) filter_var(getenv('BOSON_DEBUG'), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE),
    window: new WindowCreateInfo(
        title: 'Grafida',
        width: 1280,
        height: 860,
        decoration: WindowDecoration::DarkMode,
        webview: new WebViewCreateInfo(
            devTools: (bool) filter_var(getenv('BOSON_DEBUG'), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE),
        ),
    ),
));

$static = new FilesystemStaticProvider([
    __DIR__ . '/assets/private',
    __DIR__ . '/assets/public',
]);

$container = ContainerFactory::create([
    'static.provider' => $static,
    'dialog'          => $app->dialog,
]);

$controller = $container->get(FrontController::class);

$app->on(static function (SchemeRequestReceived $e) use ($controller): void {
    $e->response = $controller($e->request);
});

$app->webview->url = 'boson://app/';

$app->run();
