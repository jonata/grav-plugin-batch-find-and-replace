<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\BatchReplace\BatchReplaceTool;
use RocketTheme\Toolbox\Event\Event;

/**
 * Batch Find and Replace plugin.
 *
 * Adds a "Batch Find and Replace" page to the Grav Admin sidebar, providing a
 * UI to find (and optionally replace) text or PCRE patterns across every .md
 * file under user/pages/.
 *
 * @package Grav\Plugin
 */
class BatchFindAndReplacePlugin extends Plugin
{
    /**
     * Subscribed events.
     *
     * @return array<string, mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0],
            ],
        ];
    }

    /**
     * Register the plugin's autoloader.
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Grav\\Plugin\\BatchReplace\\', __DIR__ . '/classes/', true);
        $loader->register();
        return $loader;
    }

    /**
     * Activate admin-only hooks. The plugin does nothing on the front-end.
     */
    public function onPluginsInitialized(): void
    {
        if (!$this->isAdmin()) {
            return;
        }

        $this->enable([
            'onAdminMenu'              => ['onAdminMenu', 0],
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
            'onAdminTaskExecute'       => ['onAdminTaskExecute', 0],
            'onTwigSiteVariables'      => ['onTwigSiteVariables', 0],
        ]);
    }

    /**
     * Inject a "Batch Replace" entry in the admin sidebar.
     */
    public function onAdminMenu(): void
    {
        /** @var \Grav\Common\Twig\Twig $twig */
        $twig = $this->grav['twig'];
        $twig->plugins_hooked_nav['Batch Find and Replace'] = [
            'route'     => 'batch-find-and-replace',
            'icon'      => 'fa-search',
            'authorize' => ['admin.super', 'admin.maintenance'],
            'priority'  => -10,
        ];
    }

    /**
     * Make the plugin's admin templates discoverable to Twig.
     *
     * @param Event $event
     */
    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $paths   = $event['paths'];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    /**
     * Expose plugin config to the Twig template (for the warning banner, etc.).
     */
    public function onTwigSiteVariables(): void
    {
        $twig = $this->grav['twig'];
        $twig->twig_vars['bfr_max_results'] = (int) $this->config->get('plugins.batch-find-and-replace.max_results', 500);
    }

    /**
     * Handle the admin task fired by the Run button. The Admin plugin
     * automatically dispatches `task<TaskName>` methods registered via
     * onAdminTaskExecute, but Grav versions vary, so we listen for the
     * generic event and dispatch ourselves.
     *
     * @param Event $event
     */
    public function onAdminTaskExecute(Event $event): void
    {
        $method = $event['method'] ?? null;
        if ($method === 'taskBatchReplaceRun') {
            $this->runBatchTask();
            $event->stopPropagation();
            return;
        }
        if ($method === 'taskBatchReplaceLine') {
            $this->runLineTask();
            $event->stopPropagation();
            return;
        }
    }

    /**
     * Execute the find / replace task and emit a JSON response.
     */
    protected function runBatchTask(): void
    {
        // CSRF: Admin sets a `admin-nonce` form field (or query param).
        $this->verifyAdminNonce();

        // Authorization check.
        /** @var \Grav\Common\User\Interfaces\UserInterface|null $user */
        $user = $this->grav['user'] ?? null;
        if (!$user || !$user->authenticated || !($user->authorize('admin.super') || $user->authorize('admin.maintenance'))) {
            $this->jsonResponse(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $post = $this->getRequestPost();

        $find          = (string) ($post['find'] ?? '');
        $replace       = (string) ($post['replace'] ?? '');
        $useRegex      = !empty($post['use_regex']);
        $caseSensitive = !empty($post['case_sensitive']);
        $searchOnly    = !empty($post['search_only']);

        if ($find === '') {
            $this->jsonResponse(['success' => false, 'error' => 'The "Find" field is required.'], 400);
        }

        // Validate regex up front so the user gets a clear message.
        if ($useRegex) {
            $pattern = BatchReplaceTool::buildPattern($find, true, $caseSensitive);
            $err     = BatchReplaceTool::validateRegex($pattern);
            if ($err !== null) {
                $this->jsonResponse(['success' => false, 'error' => $err], 400);
            }
        }

        $pagesDir   = $this->grav['locator']->findResource('page://', true) ?: (GRAV_ROOT . '/user/pages');
        $maxResults = (int) $this->config->get('plugins.batch-find-and-replace.max_results', 500);
        $tool       = new BatchReplaceTool($pagesDir, $maxResults);

        $start = microtime(true);
        if ($searchOnly) {
            $result = $tool->findOnly($find, $useRegex, $caseSensitive);
            $mode   = 'search';
        } else {
            $result = $tool->findAndReplace($find, $replace, $useRegex, $caseSensitive);
            $mode   = 'replace';
        }
        $elapsed = round((microtime(true) - $start) * 1000);

        if ((bool) $this->config->get('plugins.batch-find-and-replace.log_operations', true)) {
            try {
                $this->grav['log']->info(sprintf(
                    '[batch-find-and-replace] mode=%s user=%s find=%s regex=%s case=%s files=%d matches=%d errors=%d elapsed=%dms',
                    $mode,
                    $user->username ?? 'unknown',
                    $this->safeForLog($find),
                    $useRegex ? 'yes' : 'no',
                    $caseSensitive ? 'yes' : 'no',
                    $result['summary'][$mode === 'search' ? 'files_with_matches' : 'files_modified'] ?? 0,
                    $result['summary'][$mode === 'search' ? 'total_matches' : 'total_replacements'] ?? 0,
                    count($result['errors']),
                    $elapsed
                ));
            } catch (\Throwable $e) {
                // Logging must never break the response.
            }
        }

        $this->jsonResponse([
            'success' => true,
            'mode'    => $mode,
            'elapsed_ms' => $elapsed,
            'summary' => $result['summary'],
            'files'   => $result['files'],
            'errors'  => $result['errors'],
        ]);
    }

    /**
     * Replace a single occurrence on a specific line of a specific file.
     */
    protected function runLineTask(): void
    {
        $this->verifyAdminNonce();

        /** @var \Grav\Common\User\Interfaces\UserInterface|null $user */
        $user = $this->grav['user'] ?? null;
        if (!$user || !$user->authenticated || !($user->authorize('admin.super') || $user->authorize('admin.maintenance'))) {
            $this->jsonResponse(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $post = $this->getRequestPost();

        $path          = (string) ($post['path'] ?? '');
        $line          = (int) ($post['line'] ?? 0);
        $find          = (string) ($post['find'] ?? '');
        $replace       = (string) ($post['replace'] ?? '');
        $useRegex      = !empty($post['use_regex']);
        $caseSensitive = !empty($post['case_sensitive']);

        if ($path === '' || $line < 1 || $find === '') {
            $this->jsonResponse(['success' => false, 'error' => 'Missing path, line, or find input.'], 400);
        }

        $pagesDir = $this->grav['locator']->findResource('page://', true) ?: (GRAV_ROOT . '/user/pages');
        $tool     = new BatchReplaceTool($pagesDir);

        $result = $tool->replaceInLine($path, $line, $find, $replace, $useRegex, $caseSensitive);

        if ((bool) $this->config->get('plugins.batch-find-and-replace.log_operations', true) && $result['success']) {
            try {
                $this->grav['log']->info(sprintf(
                    '[batch-find-and-replace] mode=line user=%s path=%s line=%d find=%s regex=%s case=%s',
                    $user->username ?? 'unknown',
                    $result['path'],
                    $line,
                    $this->safeForLog($find),
                    $useRegex ? 'yes' : 'no',
                    $caseSensitive ? 'yes' : 'no'
                ));
            } catch (\Throwable $e) {
                // Logging must never break the response.
            }
        }

        $this->jsonResponse($result, $result['success'] ? 200 : 400);
    }

    /**
     * Read posted form data as an associative array.
     *
     * @return array<string, mixed>
     */
    protected function getRequestPost(): array
    {
        $post = $_POST['data'] ?? $_POST ?? [];
        if (is_string($post)) {
            $decoded = json_decode($post, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return [];
        }
        return is_array($post) ? $post : [];
    }

    /**
     * Verify the admin-nonce token. Aborts with 403 on failure.
     */
    protected function verifyAdminNonce(): void
    {
        $nonce = $_POST['admin-nonce']
            ?? ($_POST['data']['admin-nonce'] ?? null)
            ?? ($_GET['admin-nonce'] ?? null);

        if (!$nonce || !\Grav\Common\Utils::verifyNonce($nonce, 'admin-form')) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid security token. Reload the page and try again.'], 403);
        }
    }

    /**
     * Truncate user input for safe logging.
     *
     * @param string $value
     * @return string
     */
    protected function safeForLog(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if (mb_strlen($value) > 80) {
            $value = mb_substr($value, 0, 80) . '…';
        }
        return '"' . $value . '"';
    }

    /**
     * Emit a JSON response and terminate.
     *
     * @param array<string,mixed> $data
     * @param int                 $status
     * @return never
     */
    protected function jsonResponse(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
