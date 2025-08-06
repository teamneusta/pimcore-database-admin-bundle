<?php
declare(strict_types=1);

namespace Neusta\Pimcore\DatabaseAdminBundle\Controller\Admin {
    use Pimcore\Controller\KernelControllerEventInterface;
    use Pimcore\Controller\UserAwareController;
    use Pimcore\Tool\Session;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Event\ControllerEvent;
    use Symfony\Component\HttpKernel\Profiler\Profiler;
    use Symfony\Component\Routing\Attribute\Route;

    /**
     * @internal
     */
    final class AdminerController extends UserAwareController implements KernelControllerEventInterface
    {
        private string $adminerHome = '';

        #[Route('/admin/external_adminer/adminer', name: 'neusta_database_admin_adminer_index')]
        public function index(?Profiler $profiler): Response
        {
            if ($profiler) {
                $profiler->disable();
            }

            // disable debug error handler while including adminer
            $errorHandler = set_error_handler(function () {
            });

            chdir($this->adminerHome . 'adminer');
            include($this->adminerHome . 'adminer/index.php');

            set_error_handler($errorHandler);

            // empty fake response, unfortunately Adminer uses flush() very heavily so we're not able to buffer, rewrite
            // and put the into a proper response object :(
            $response = new Response();

            return $this->mergeAdminerHeaders($response);
        }

        #[Route('/admin/external_adminer/{path}', name: 'neusta_database_admin_adminer_proxy', requirements: ['path' => '.*'])]
        #[Route('/admin/adminer/{path}', name: 'neusta_database_admin_adminer_proxy_1', requirements: ['path' => '.*'])]
        #[Route('/admin/externals/{path}', name: 'neusta_database_admin_adminer_proxy_2', requirements: ['path' => '.*'], defaults: ['type' => 'external'])]
        public function proxy(Request $request): Response
        {
            $response = new Response();
            $content = '';

            // proxy for resources
            $path = $request->get('path');
            if (preg_match("@\.(css|js|ico|png|jpg|gif)$@", $path)) {
                if ('external' === $request->get('type')) {
                    $path = '../' . $path;
                }

                if (str_starts_with($path, 'static/')) {
                    $path = 'adminer/' . $path;
                }

                $filePath = $this->adminerHome . '/' . $path;

                // it seems that css files need the right content-type (Chrome)
                if (preg_match('@.css$@', $path)) {
                    $response->headers->set('Content-Type', 'text/css');
                } elseif (preg_match('@.js$@', $path)) {
                    $response->headers->set('Content-Type', 'text/javascript');
                }

                if (is_file($filePath)) {
                    $content = file_get_contents($filePath);

                    if (preg_match('@default.css$@', $path)) {
                        // append custom styles, because in Adminer everything is hardcoded
                        $content .= file_get_contents($this->adminerHome . 'designs/konya/adminer.css');
                        $content .= file_get_contents(PIMCORE_WEB_ROOT . '/bundles/neustapimcoredatabaseadmin/css/adminer-modifications.css');
                    }
                }
            }

            $response->setContent($content);

            return $this->mergeAdminerHeaders($response);
        }

        public function onKernelControllerEvent(ControllerEvent $event): void
        {
            if (!$event->isMainRequest()) {
                return;
            }

            // PHP 7.0 compatibility of adminer (throws some warnings)
            ini_set('display_errors', 0);

            // only for admins
            $this->checkPermission('neusta_database_admin');

            // call this to keep the session 'open' so that Adminer can write to it
//            $session = Session::get();

            $this->adminerHome = PIMCORE_COMPOSER_PATH . '/vrana/adminer/';
        }

        /**
         * Merges http-headers set from Adminer via headers function to the Symfony Response Object.
         */
        private function mergeAdminerHeaders(Response $response): Response
        {
            if (!headers_sent()) {
                $headersRaw = headers_list();

                foreach ($headersRaw as $header) {
                    [$headerKey, $headerValue] = explode(':', $header, 2);

                    if ($headerKey && $headerValue) {
                        $response->headers->set($headerKey, $headerValue);
                    }
                }

                header_remove();
            }

            return $response;
        }
    }
}

namespace {
    use Pimcore\Cache;

    if (!function_exists('adminer_object')) {
        // adminer plugin
        function adminer_object(): AdminerPimcore
        {
            $pluginDir = PIMCORE_COMPOSER_PATH . '/vrana/adminer/plugins';

            // required to run any plugin
            include_once $pluginDir . '/plugin.php';

            // autoloader
            foreach (glob($pluginDir . '/*.php') as $filename) {
                include_once $filename;
            }

            $plugins = [
                new \AdminerFrames(),
                new \AdminerDumpDate,
                new \AdminerDumpJson,
                new \AdminerDumpBz2,
                new \AdminerDumpZip,
                new \AdminerDumpXml,
                new \AdminerDumpAlter,
            ];

            // support for SSL (at least for PDO)
            $driverOptions = \Pimcore\Db::get()->getParams()['driverOptions'] ?? [];
            $ssl = [
                'key' => $driverOptions[\PDO::MYSQL_ATTR_SSL_KEY] ?? null,
                'cert' => $driverOptions[\PDO::MYSQL_ATTR_SSL_CERT] ?? null,
                'ca' => $driverOptions[\PDO::MYSQL_ATTR_SSL_CA] ?? null,
            ];
            if (null !== $ssl['key'] || null !== $ssl['cert'] || null !== $ssl['ca']) {
                $plugins[] = new \AdminerLoginSsl($ssl);
            }

            class AdminerPimcore extends \AdminerPlugin
            {
                public function name(): string
                {
                    return '';
                }

                public function loginForm(): void
                {
                    parent::loginForm();
                    echo '<script' . nonce() . ">document.querySelector('input[name=auth\\\\[db\\\\]]').value='" . $this->database() . "'; document.querySelector('form').submit()</script>";
                }

                /**
                 * @param bool $create
                 */
                public function permanentLogin($create = false): string
                {
                    // key used for permanent login
                    return session_id();
                }

                /**
                 * @param string $login
                 * @param string $password
                 */
                public function login($login, $password): bool
                {
                    return true;
                }

                public function credentials(): array
                {
                    $params = \Pimcore\Db::get()->getParams();

                    $host = $params['host'] ?? null;
                    if ($port = $params['port'] ?? null) {
                        $host .= ':' . $port;
                    }

                    // server, username and password for connecting to database
                    return [
                        $host,
                        $params['user'] ?? null,
                        $params['password'] ?? null,
                    ];
                }

                public function database(): string
                {
                    // database name, will be escaped by Adminer
                    return \Pimcore\Db::get()->getDatabase();
                }

                public function databases($flush = true): array
                {
                    $cacheKey = 'neusta_database_admin_databases';

                    if (!$return = Cache::load($cacheKey)) {
                        $return = Pimcore\Db::get()->fetchAllAssociative('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');

                        foreach ($return as &$ret) {
                            $ret = $ret['SCHEMA_NAME'];
                        }
                        unset($ret);

                        Cache::save($return, $cacheKey);
                    }

                    return $return;
                }
            }

            return new AdminerPimcore($plugins);
        }
    }
}
