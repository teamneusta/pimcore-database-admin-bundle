<?php
declare(strict_types=1);

namespace Neusta\Pimcore\DatabaseAdminBundle\Controller\Admin {
    use Pimcore\Controller\KernelControllerEventInterface;
    use Pimcore\Controller\UserAwareController;
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
        private const ADMINER_HOME = PIMCORE_COMPOSER_PATH . '/vrana/adminer';

        public function onKernelControllerEvent(ControllerEvent $event): void
        {
            if (!$event->isMainRequest()) {
                return;
            }

            // only for admins
            $this->checkPermission('neusta_database_admin');
        }

        #[Route('/admin/external_adminer/adminer', name: 'neusta_database_admin_adminer_index')]
        public function index(?Profiler $profiler): Response
        {
            $profiler?->disable();

            // disable the debug error handler while including adminer
            set_error_handler(static fn () => true);

            try {
                chdir(self::ADMINER_HOME . '/adminer');

                ob_implicit_flush(false);
                ob_start();

                require self::ADMINER_HOME . '/adminer/index.php';

                $content = ob_get_clean() ?: '';
            } finally {
                restore_error_handler();
            }

            return $this->mergeAdminerHeaders(new Response($content));
        }

        #[Route('/admin/external_adminer/{path}', name: 'neusta_database_admin_adminer_proxy', requirements: ['path' => '.*'])]
        #[Route('/admin/adminer/{path}', name: 'neusta_database_admin_adminer_proxy_1', requirements: ['path' => '.*'])]
        #[Route('/admin/externals/{path}', name: 'neusta_database_admin_adminer_proxy_2', requirements: ['path' => '.*'], defaults: ['type' => 'external'])]
        public function proxy(Request $request): Response
        {
            $path = $request->attributes->getString('path');

            if (!preg_match("@\.(css|js|ico|png|jpg|gif)$@", $path)) {
                return new Response('', Response::HTTP_NOT_FOUND);
            }

            $path = match ($request->attributes->get('type')) {
                'external' => '../' . $path,
                default => 'adminer/' . $path,
            };

            $filePath = self::ADMINER_HOME . '/' . $path;

            if (!is_file($filePath) || !is_readable($filePath)) {
                return new Response('', Response::HTTP_NOT_FOUND);
            }

            $content = file_get_contents($filePath) ?: '';

            if (str_ends_with($path, 'default.css')) {
                // append custom styles, because in Adminer everything is hardcoded
                $content .= file_get_contents(self::ADMINER_HOME . '/designs/konya/adminer.css');
                $content .= file_get_contents(PIMCORE_WEB_ROOT . '/bundles/neustapimcoredatabaseadmin/css/adminer-modifications.css');
            }

            return new Response($content, Response::HTTP_OK, match (pathinfo($path, \PATHINFO_EXTENSION)) {
                'css' => ['Content-Type' => 'text/css'],
                'js' => ['Content-Type' => 'application/javascript'],
                default => [],
            });
        }

        /**
         * Merges HTTP headers set from Adminer via headers function to the Symfony response object.
         */
        private function mergeAdminerHeaders(Response $response): Response
        {
            if (headers_sent()) {
                return $response;
            }

            foreach (headers_list() as $header) {
                if (str_contains($header, ':')) {
                    [$headerKey, $headerValue] = explode(':', $header, 2);
                    $response->headers->set(trim($headerKey), trim($headerValue), false);
                }
            }

            header_remove();

            return $response;
        }
    }
}

namespace {
    use Pimcore\Cache;

    if (!function_exists('adminer_object')) {
        function adminer_object(): Adminer\Plugins
        {
            class AdminerPimcore extends Adminer\Plugin
            {
                public function name(): string
                {
                    return '';
                }

                public function css(): array
                {
                    return ['adminer.css' => 'light'];
                }

                public function loginForm(): void
                {
                    echo Adminer\script(
                        <<<EOJS
                        document.addEventListener(
                            'DOMContentLoaded',
                            function() {
                                document.querySelector('input[name=auth\\\\[db\\\\]]').value='{$this->database()}';
                                document.querySelector('form').submit();
                            },
                            true,
                        );
                        EOJS
                    );
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
                    $params = Pimcore\Db::get()->getParams();

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
                    // database name will be escaped by Adminer
                    return Pimcore\Db::get()->getDatabase();
                }

                public function databases($flush = true): array
                {
                    $cacheKey = 'neusta_database_admin_databases';

                    if (!$databases = Cache::load($cacheKey)) {
                        $databases = Pimcore\Db::get()->fetchFirstColumn('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');

                        Cache::save($databases, $cacheKey);
                    }

                    return $databases;
                }
            }

            $plugins = [
                new AdminerPimcore(),
                new AdminerFrames(true),
                new AdminerDumpDate(),
                new AdminerDumpJson(),
                new AdminerDumpBz2(),
                new AdminerDumpZip(),
                new AdminerDumpXml(),
                new AdminerDumpAlter(),
            ];

            // support for SSL (at least for PDO)
            $driverOptions = Pimcore\Db::get()->getParams()['driverOptions'] ?? [];
            $ssl = array_filter([
                'key' => $driverOptions[PDO::MYSQL_ATTR_SSL_KEY] ?? null,
                'cert' => $driverOptions[PDO::MYSQL_ATTR_SSL_CERT] ?? null,
                'ca' => $driverOptions[PDO::MYSQL_ATTR_SSL_CA] ?? null,
                'verify' => $driverOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null,
            ]);
            if ($ssl) {
                $plugins[] = new AdminerLoginSsl($ssl);
            }

            return new Adminer\Plugins($plugins);
        }
    }
}
