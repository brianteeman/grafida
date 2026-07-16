---
name: verify
description: Drive the real Grafida SPA against the real PHP backend in a browser, to observe a change actually working.
---

# Verifying a Grafida change by running it

Grafida is a Boson desktop app: a native webview whose SPA talks to PHP over the
`boson://` scheme. You cannot click a native window from here — but you don't
need to. **The `boson://` scheme handler is the only piece that has to be
replaced.** Everything behind `Kernel::handle()` and everything in
`assets/private` is the shipping code, so an HTTP bridge + Chromium gives you a
genuine end-to-end run.

Build the harness in the scratchpad directory, never in the repo.

## Backend only (fast — a route, a service, an API shape)

`Kernel` is a pure `Request → Response` function, so drive it directly:

```php
$container = ContainerFactory::create([
    'db.path'         => __DIR__ . '/drive.sqlite',   // a file, so it persists across requests
    'base.path'       => '/path/to/grafida',
    'static.provider' => $noopStaticProvider,          // implements findFileByRequest(): ?ResponseInterface
    'dialog'          => null,
    'secret.store'    => false,                        // plaintext fallback: no keychain prompts
]);
$response = $container->get(Kernel::class)->handle(new Request('GET', 'boson://app/api/...'));
```

Seed rows with `$container->get(DatabaseInterface::class)->getConnection()` (a
real PDO; the container has already migrated the DB).

`error_reporting(E_ALL & ~E_DEPRECATED)` — joomla/database trips a PHP 8.5
deprecation that otherwise corrupts JSON output.

## Full stack (the SPA, the editor, the AI panel)

1. **Bridge**: a `php -S 127.0.0.1:8793 -t assets/private bridge.php` router.
   Requests under `/api/` → `Kernel::handle(new Request($method, 'boson://app' . $uri, [], file_get_contents('php://input')))`,
   echo the body with the response's status. Everything else → `return false`
   so the built-in server serves the asset off disk; `/` → `view/index.html`.
2. **Browser**: `npx playwright install chromium` once, then rewrite the scheme
   before app.js loads — this is the whole trick:

```js
await page.addInitScript(() => {
    const f = window.fetch;
    window.fetch = (u, o) => f(typeof u === 'string' && u.startsWith('boson://app') ? u.replace('boson://app', '') : u, o);
});
```

Then drive the real UI: `[data-screen="articles"]`, `.article-item`,
`#ai-input`, `#ai-btn-send`, `#settings-language-select`. Modals open via the
globals (`window.openEditAiServiceModal(1)`); note `State` is a top-level
`const`, so it is **not** on `window` — probe the DOM instead.

### Fake remotes

- **A Joomla site**: `php -S` over a dir with `images/`. Set `sites.base_url` to
  it. Add `usleep()` to the router to simulate real latency — that is how the
  AI panel's image-collection dead time was found.
- **An AI provider**: a `php -S` script that records `php://input` to a file and
  echoes SSE `data: {"choices":[{"delta":{"content":"..."}}]}` + `[DONE]`.
  **It must send `Access-Control-Allow-Origin/Headers` and answer `OPTIONS`** —
  the page origin differs from the provider's, exactly like the real CORS
  constraint. Point an `ai_services` row's `endpoint` at it. The recorded file
  is your proof of what the wire format really was.

## Gotchas

- Seeding an `ai_services` row needs `insecure_key` (with `secret.store => false`).
- A site with no token makes reference/media calls 409 in the console — expected
  noise, not your bug.
- Identical image URLs get HTTP-cached by the browser; use distinct filenames
  when measuring per-image latency.
