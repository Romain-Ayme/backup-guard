<?php
/**
 * Karom Hub client — one file to drop into any Karom plugin or theme.
 *
 * What it does:
 *  - offers updates to WordPress from the Karom Hub (plugins and themes),
 *  - activates the site with the customer's licence key (proof of site included),
 *  - shows the licence status on a settings page and warns admins when it lapses.
 *
 * What it never does: switch features off. The hub only reports a status; whatever
 * the plugin does with it is up to the plugin. On a network error it keeps the last
 * known status.
 *
 * Setup: copy this file into the plugin or theme, add an `Update URI` header with the
 * hub address (e.g. https://hub.karom.studio), then in the main file (plugin) or
 * functions.php (theme):
 *
 *     require_once __DIR__ . '/class-karom-hub-client.php';
 *     karom_hub_client();
 *
 * The folder name must match the product slug created on the hub.
 *
 * @version 1.0.0
 * @requires PHP 8.3
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Karom_Hub_Client')) {

    final class Karom_Hub_Client {

        public const VERSION = '1.0.0';

        private const CHALLENGE_OPTION = 'karom_hub_challenge';
        private const CHALLENGE_TTL = 600;
        private const REFRESH_AFTER = 12 * HOUR_IN_SECONDS;
        private const PROBLEM_STATUSES = ['expired', 'revoked', 'site_inactive', 'invalid_key'];
        private const STALE_AFTER = 3 * DAY_IN_SECONDS;

        /** @var array<string, self> */
        private static array $instances = [];
        private static bool $shared_hooks = false;

        /** @var array{hub_url:string,slug:string,type:string,basename:string,version:string,name:string} */
        private array $config;

        // ------------------------------------------------------------------ boot

        /**
         * @param array $args caller_file, hub_url, slug, type, basename, version, name (all optional).
         */
        public static function boot(array $args = []): self {
            $config = self::resolve_config($args);
            if (isset(self::$instances[$config['slug']])) {
                return self::$instances[$config['slug']];
            }
            $client = new self($config);
            self::$instances[$config['slug']] = $client;
            $client->register_hooks();
            self::register_shared_hooks();
            return $client;
        }

        public static function instance(string $slug): ?self {
            return self::$instances[$slug] ?? null;
        }

        /** Forget every instance (tests). */
        public static function reset(): void {
            self::$instances = [];
            self::$shared_hooks = false;
        }

        private function __construct(array $config) {
            $this->config = $config;
        }

        /** @return array{hub_url:string,slug:string,type:string,basename:string,version:string,name:string} */
        public function config(): array {
            return $this->config;
        }

        private static function resolve_config(array $args): array {
            $caller = self::normalize_path((string) ($args['caller_file'] ?? self::detect_caller_file()));
            $package = self::detect_package($caller);

            $slug = (string) ($args['slug'] ?? $package['slug']);
            $type = (string) ($args['type'] ?? $package['type']);
            $file = (string) ($package['file'] ?? '');
            $basename = (string) ($args['basename'] ?? $package['basename']);

            $headers = $file !== '' && is_file($file)
                ? get_file_data($file, ['name' => $type === 'theme' ? 'Theme Name' : 'Plugin Name', 'version' => 'Version', 'update_uri' => 'Update URI'])
                : ['name' => '', 'version' => '', 'update_uri' => ''];

            $hub_url = (string) ($args['hub_url'] ?? ($headers['update_uri'] ?: (defined('KAROM_HUB_URL') ? KAROM_HUB_URL : '')));
            $hub_url = untrailingslashit(trim($hub_url));
            if ($slug === '' || !preg_match('#^https?://[^/]+#i', $hub_url)) {
                throw new InvalidArgumentException('Karom Hub client: add an "Update URI" header with the hub address (https://…) to this plugin or theme.');
            }

            return [
                'hub_url' => $hub_url,
                'slug' => $slug,
                'type' => $type,
                'basename' => $basename,
                'version' => (string) ($args['version'] ?? ($headers['version'] ?: '0.0.0')),
                'name' => (string) ($args['name'] ?? ($headers['name'] ?: $slug)),
            ];
        }

        private static function detect_caller_file(): string {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (isset($frame['file']) && $frame['file'] !== __FILE__) {
                    return $frame['file'];
                }
            }
            return __FILE__;
        }

        /** @return array{slug:string,type:string,basename:string,file:string} */
        private static function detect_package(string $path): array {
            $plugins = self::normalize_path(WP_PLUGIN_DIR) . '/';
            if (str_starts_with($path, $plugins)) {
                $relative = substr($path, strlen($plugins));
                $slug = explode('/', $relative)[0];
                $dir = $plugins . $slug;
                $main = '';
                if (is_dir($dir)) {
                    foreach (glob($dir . '/*.php') ?: [] as $candidate) {
                        $data = get_file_data($candidate, ['name' => 'Plugin Name']);
                        if ($data['name'] !== '') {
                            $main = self::normalize_path($candidate);
                            break;
                        }
                    }
                } else {
                    $slug = basename($slug, '.php');
                    $main = $path;
                }
                return ['slug' => $slug, 'type' => 'plugin', 'basename' => $main !== '' ? $slug . '/' . basename($main) : $slug, 'file' => $main];
            }

            $themes = self::normalize_path(get_theme_root()) . '/';
            if (str_starts_with($path, $themes)) {
                $slug = explode('/', substr($path, strlen($themes)))[0];
                return ['slug' => $slug, 'type' => 'theme', 'basename' => $slug, 'file' => $themes . $slug . '/style.css'];
            }

            return ['slug' => '', 'type' => 'plugin', 'basename' => '', 'file' => ''];
        }

        private static function normalize_path(string $path): string {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        // ----------------------------------------------------------------- hooks

        private function register_hooks(): void {
            $host = (string) wp_parse_url($this->config['hub_url'], PHP_URL_HOST);
            $slug = $this->config['slug'];

            if ($this->config['type'] === 'theme') {
                add_filter("update_themes_{$host}", [$this, 'offer_theme_update'], 10, 4);
            } else {
                add_filter("update_plugins_{$host}", [$this, 'offer_plugin_update'], 10, 4);
                add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
                add_filter('plugin_action_links_' . $this->config['basename'], [$this, 'action_links']);
                add_action('after_plugin_row_' . $this->config['basename'], [$this, 'plugin_row'], 10, 3);
            }
            add_filter('upgrader_pre_download', [$this, 'download_package'], 10, 4);

            add_action('init', [$this, 'schedule']);
            add_action($this->cron_hook(), [$this, 'refresh_status']);
            add_action('admin_init', [$this, 'maybe_refresh']);
            add_action('admin_menu', [$this, 'register_menu']);
            add_action('admin_notices', [$this, 'render_notice']);
            add_action("admin_post_karom_hub_activate_{$slug}", [$this, 'handle_activate']);
            add_action("admin_post_karom_hub_deactivate_{$slug}", [$this, 'handle_deactivate']);
            add_action("admin_post_karom_hub_refresh_{$slug}", [$this, 'handle_refresh']);
        }

        /** The challenge route is shared by every Karom plugin of the site. */
        private static function register_shared_hooks(): void {
            if (self::$shared_hooks) {
                return;
            }
            self::$shared_hooks = true;
            add_action('rest_api_init', static function (): void {
                register_rest_route('karom-hub/v1', '/challenge', [
                    'methods' => 'GET',
                    'callback' => [self::class, 'challenge_response'],
                    'permission_callback' => '__return_true',
                ]);
            });
        }

        private function cron_hook(): string {
            return 'karom_hub_refresh_' . $this->config['slug'];
        }

        public function schedule(): void {
            if (!wp_next_scheduled($this->cron_hook())) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', $this->cron_hook());
            }
        }

        public function maybe_refresh(): void {
            $state = $this->state();
            if (!empty($state['key']) && (time() - (int) ($state['checked_at'] ?? 0)) > self::REFRESH_AFTER) {
                $this->refresh_status();
            }
        }

        // ----------------------------------------------------------------- state

        private function option_name(): string {
            return 'karom_hub_' . $this->config['slug'];
        }

        /** @return array<string, mixed> */
        public function state(): array {
            $state = get_option($this->option_name(), []);
            return is_array($state) ? $state : [];
        }

        private function save_state(array $state): array {
            update_option($this->option_name(), $state, false);
            return $state;
        }

        // ------------------------------------------------------------------ HTTP

        private function site_url(): string {
            return untrailingslashit(home_url());
        }

        /** @return array|\WP_Error */
        private function request(string $method, string $url, string $key, array $extra = []) {
            $args = [
                'method' => $method,
                'timeout' => 20,
                'redirection' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $key,
                    'X-Karom-Site-Url' => $this->site_url(),
                    'User-Agent' => 'KaromHubClient/' . self::VERSION . '; ' . $this->site_url(),
                ],
            ];
            return wp_remote_request($url, array_merge($args, $extra));
        }

        private function api(string $path): string {
            return $this->config['hub_url'] . '/api/v1' . $path;
        }

        /**
         * @param array|\WP_Error $response
         * @return array{code:int,body:array,network:bool,message:string,raw:array}
         */
        private function parse($response): array {
            if (is_wp_error($response)) {
                return ['code' => 0, 'body' => [], 'network' => true, 'message' => $response->get_error_message(), 'raw' => []];
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return [
                'code' => wp_remote_retrieve_response_code($response),
                'body' => is_array($body) ? $body : [],
                'network' => false,
                'message' => '',
                'raw' => $response,
            ];
        }

        // --------------------------------------------------------- status refresh

        /** Asks the hub for the status and the current offer, keeping the last known state on any failure. */
        public function refresh_status(): array {
            $state = $this->state();
            $key = (string) ($state['key'] ?? '');
            if ($key === '') {
                return ['status' => 'no_key'];
            }
            $before = [(string) ($state['status'] ?? ''), (string) ($state['update']['version'] ?? '')];

            $reply = $this->parse($this->request('GET', $this->api('/updates?version=' . rawurlencode($this->config['version'])), $key));
            $state['checked_at'] = time();

            if ($reply['code'] === 200 && isset($reply['body']['status'])) {
                $update = $reply['body']['update'] ?? null;
                $state['status'] = (string) $reply['body']['status'];
                $state['update'] = is_array($update) ? $update : null;
                $state['error'] = null;
                $state['last_success'] = time();
            } elseif ($reply['code'] === 401) {
                $state['status'] = 'invalid_key';
                $state['update'] = null;
                $state['error'] = null;
                $state['last_success'] = time();
            } else {
                $state['error'] = $reply['network'] ? $reply['message'] : 'HTTP ' . $reply['code'];
            }
            $saved = $this->save_state($state);
            if ($before !== [(string) ($saved['status'] ?? ''), (string) ($saved['update']['version'] ?? '')]) {
                // WordPress keeps its own list of available updates for hours: make it ask again so the offer shows up now.
                delete_site_transient($this->config['type'] === 'theme' ? 'update_themes' : 'update_plugins');
            }
            return $saved;
        }

        // --------------------------------------------------------------- updates

        private function package_prefix(): string {
            return $this->api('/downloads/' . rawurlencode($this->config['slug']) . '/');
        }

        /** @return array<string, mixed>|null */
        private function available_offer(string $installed): ?array {
            $state = $this->state();
            $offer = $state['update'] ?? null;
            if (($state['status'] ?? '') !== 'valid' || !is_array($offer) || empty($offer['version'])) {
                return null;
            }
            return version_compare((string) $offer['version'], $installed, '>') ? $offer : null;
        }

        public function offer_plugin_update($update, $plugin_data, $plugin_file, $locales = []) {
            if ($plugin_file !== $this->config['basename']) {
                return $update;
            }
            $offer = $this->available_offer((string) ($plugin_data['Version'] ?? $this->config['version']));
            if ($offer === null) {
                return false;
            }
            return [
                'id' => $this->config['hub_url'] . '/' . $this->config['slug'],
                'slug' => $this->config['slug'],
                'plugin' => $this->config['basename'],
                'version' => (string) $offer['version'],
                'url' => $this->config['hub_url'],
                'package' => $this->package_prefix() . rawurlencode((string) $offer['version']),
                'tested' => (string) ($offer['tested'] ?? ''),
                'requires' => (string) ($offer['requires'] ?? ''),
                'requires_php' => (string) ($offer['requires_php'] ?? ''),
                'icons' => [],
                'banners' => [],
            ];
        }

        public function offer_theme_update($update, $theme_data, $theme_stylesheet, $locales = []) {
            if ($theme_stylesheet !== $this->config['slug']) {
                return $update;
            }
            $offer = $this->available_offer((string) ($theme_data['Version'] ?? $this->config['version']));
            if ($offer === null) {
                return false;
            }
            return [
                'theme' => $this->config['slug'],
                'version' => (string) $offer['version'],
                'url' => $this->config['hub_url'],
                'package' => $this->package_prefix() . rawurlencode((string) $offer['version']),
                'requires' => (string) ($offer['requires'] ?? ''),
                'requires_php' => (string) ($offer['requires_php'] ?? ''),
            ];
        }

        public function plugins_api($result, $action, $args) {
            if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== $this->config['slug']) {
                return $result;
            }
            $offer = $this->available_offer('0.0.0');
            if ($offer === null) {
                return $result;
            }
            return (object) [
                'name' => $this->config['name'],
                'slug' => $this->config['slug'],
                'version' => (string) $offer['version'],
                'requires' => (string) ($offer['requires'] ?? ''),
                'tested' => (string) ($offer['tested'] ?? ''),
                'requires_php' => (string) ($offer['requires_php'] ?? ''),
                'homepage' => $this->config['hub_url'],
                'sections' => ['changelog' => nl2br(esc_html((string) ($offer['changelog'] ?? '')))],
                'download_link' => $this->package_prefix() . rawurlencode((string) $offer['version']),
            ];
        }

        /**
         * `upgrader_pre_download`: WordPress cannot send headers, so the hub package is fetched here.
         * The hub answers with a short-lived signed URL; the licence key is NOT sent to that storage host.
         *
         * @return false|string|\WP_Error
         */
        public function download_package($reply, $package, $upgrader = null, $hook_extra = []) {
            if (!is_string($package) || !str_starts_with($package, $this->package_prefix())) {
                return $reply;
            }
            $state = $this->state();
            $key = (string) ($state['key'] ?? '');
            if ($key === '') {
                return new WP_Error('karom_hub_no_key', __('Aucune clé de licence enregistrée pour ce produit.', 'karom-hub'));
            }

            $response = $this->parse($this->request('GET', $package, $key));
            if ($response['network']) {
                return new WP_Error('karom_hub_download', __('Le hub est injoignable pour le moment.', 'karom-hub'));
            }
            $location = in_array($response['code'], [301, 302, 303, 307, 308], true)
                ? (string) wp_remote_retrieve_header($response['raw'], 'location')
                : '';
            if ($location === '') {
                $code = (string) ($response['body']['error'] ?? 'HTTP ' . $response['code']);
                return new WP_Error('karom_hub_download', sprintf(__('Téléchargement refusé par le hub (%s).', 'karom-hub'), $code));
            }

            // The storage host gets no licence header: the signed URL is the credential.
            $file = download_url($location, 300);
            if (is_wp_error($file)) {
                return new WP_Error('karom_hub_download', $file->get_error_message());
            }

            $expected = $this->expected_checksum($package);
            if ($expected !== null && !hash_equals($expected, (string) hash_file('sha256', $file))) {
                @unlink($file);
                return new WP_Error('karom_hub_checksum', __('Le fichier téléchargé ne correspond pas à l\'empreinte annoncée par le hub.', 'karom-hub'));
            }
            return $file;
        }

        private function expected_checksum(string $package): ?string {
            $offer = $this->state()['update'] ?? null;
            if (!is_array($offer) || empty($offer['sha256'])) {
                return null;
            }
            $version = rawurldecode(substr($package, strlen($this->package_prefix())));
            return $version === (string) ($offer['version'] ?? '') ? (string) $offer['sha256'] : null;
        }

        // ------------------------------------------------------------ activation

        private const MESSAGES = [
            'invalid_key' => 'Clé de licence inconnue. Vérifiez qu\'elle est copiée en entier.',
            'expired' => 'Cette licence a expiré.',
            'revoked' => 'Cette licence a été révoquée.',
            'seat_limit' => 'Le nombre de sites autorisés par cette licence est atteint.',
            'unreachable' => 'Le hub ne peut pas joindre ce site. Son adresse doit être publique et en HTTPS.',
            'challenge_failed' => 'La preuve de site a échoué : le hub n\'a pas pu lire le jeton sur ce site.',
            'challenge_expired' => 'La preuve de site a expiré. Réessayez.',
            'rate_limited' => 'Trop d\'essais. Réessayez dans quelques minutes.',
            'network' => 'Le hub est injoignable pour le moment. Réessayez plus tard.',
            'unavailable' => 'Le hub est indisponible pour le moment. Réessayez plus tard.',
            'empty_key' => 'Saisissez votre clé de licence.',
        ];

        private function failure(string $code, ?string $status = null, ?string $detail = null): array {
            $message = self::MESSAGES[$code] ?? sprintf('Opération impossible (%s).', $code);
            if ($code === 'challenge_failed' && $detail !== null && $detail !== '') {
                $hint = self::challenge_hint($detail);
                if ($hint !== '') {
                    $message .= ' ' . $hint;
                }
            }
            return ['ok' => false, 'code' => $code, 'status' => $status, 'detail' => $detail, 'message' => $message];
        }

        /** What the hub saw when it tried to read the token, and what the site owner can do about it. */
        private static function challenge_hint(string $detail): string {
            if (preg_match('/^http_(\d{3})$/', $detail, $m)) {
                $http = (int) $m[1];
                if ($http === 404) {
                    return 'Le site a répondu 404 : la route /wp-json est introuvable. Vérifiez que les permaliens ne sont pas en mode « simple ».';
                }
                if ($http === 401 || $http === 403) {
                    return sprintf('Le site a répondu %d : un pare-feu ou une extension de sécurité bloque la requête du hub (agent « KaromHub/1.0 »). Autorisez le chemin /wp-json/karom-hub/.', $http);
                }
                return sprintf('Le site a répondu %d à la requête du hub.', $http);
            }
            return match ($detail) {
                'cloudflare' => 'Cloudflare (ou un pare-feu en amont) a bloqué la requête du hub. Autorisez le chemin /wp-json/karom-hub/ ou l\'agent « KaromHub/1.0 » dans les règles de sécurité, puis réessayez.',
                'timeout' => 'Le site n\'a pas répondu dans le délai.',
                'connect' => 'Le hub n\'a pas pu se connecter au site.',
                'tls' => 'Le certificat HTTPS du site n\'est pas accepté par le hub.',
                'not_json' => 'Le site a répondu autre chose que du JSON : une page de protection ou une redirection s\'est intercalée.',
                'no_token', 'wrong_token', 'too_large' => 'Le site a répondu sans le bon jeton : une mise en cache peut servir une ancienne réponse. Excluez /wp-json/karom-hub/ du cache.',
                default => '',
            };
        }

        private function error_code(array $reply): string {
            if ($reply['network']) {
                return 'network';
            }
            return (string) ($reply['body']['error'] ?? ($reply['code'] >= 500 ? 'unavailable' : 'http_' . $reply['code']));
        }

        /**
         * Activates this site: asks the hub for a token, serves it on /wp-json/karom-hub/v1/challenge,
         * then asks the hub to read it. The key is only stored once the hub accepted the site.
         *
         * @return array{ok:bool,code:string,status:?string,message:string}
         */
        public function activate(string $raw_key): array {
            $key = trim($raw_key);
            if ($key === '') {
                return $this->failure('empty_key');
            }

            $reply = $this->parse($this->request('POST', $this->api('/activations'), $key));
            if ($reply['code'] === 200 && ($reply['body']['status'] ?? '') === 'pending' && !empty($reply['body']['challenge']['token'])) {
                update_option(self::CHALLENGE_OPTION, [
                    'token' => (string) $reply['body']['challenge']['token'],
                    'expires' => time() + self::CHALLENGE_TTL,
                ], false);
                try {
                    $reply = $this->parse($this->request('POST', $this->api('/activations/confirm'), $key, ['timeout' => 30]));
                } finally {
                    delete_option(self::CHALLENGE_OPTION);
                }
            }

            if ($reply['code'] !== 200 || empty($reply['body']['status'])) {
                return $this->failure($this->error_code($reply), $reply['body']['status'] ?? null, isset($reply['body']['detail']) ? (string) $reply['body']['detail'] : null);
            }

            $this->save_state([
                'key' => $key,
                'status' => (string) $reply['body']['status'],
                'seat_limit' => $reply['body']['seat_limit'] ?? null,
                'seats_used' => $reply['body']['seats_used'] ?? null,
                'expires_at' => $reply['body']['expires_at'] ?? null,
                'update' => null,
                'error' => null,
                'last_success' => time(),
                'checked_at' => 0, // the next admin page asks the hub for the offer
            ]);
            return ['ok' => true, 'code' => 'ok', 'status' => (string) $reply['body']['status'], 'message' => 'Site activé.'];
        }

        /** Frees the seat on the hub, then forgets the key here. The key is kept if the hub cannot be reached. */
        public function deactivate(): array {
            $key = (string) ($this->state()['key'] ?? '');
            if ($key !== '') {
                $reply = $this->parse($this->request('DELETE', $this->api('/activations'), $key));
                if ($reply['code'] !== 200 && $reply['code'] !== 404) {
                    return $this->failure($this->error_code($reply), $reply['body']['status'] ?? null);
                }
            }
            $this->save_state(['status' => 'no_key']);
            return ['ok' => true, 'code' => 'ok', 'status' => 'no_key', 'message' => 'Site désactivé.'];
        }

        /** REST callback: the token is public for the few seconds the hub needs to read it. */
        public static function challenge_response() {
            $challenge = get_option(self::CHALLENGE_OPTION);
            if (is_array($challenge) && !empty($challenge['token']) && (int) ($challenge['expires'] ?? 0) > time()) {
                return ['token' => (string) $challenge['token']];
            }
            return new WP_Error('karom_hub_no_challenge', 'No activation in progress.', ['status' => 404]);
        }

        // ----------------------------------------------------------------- admin

        private function settings_url(): string {
            return admin_url('options-general.php?page=karom-hub-' . $this->config['slug']);
        }

        public function register_menu(): void {
            $title = sprintf('Licence %s', $this->config['name']);
            add_options_page($title, $title, 'manage_options', 'karom-hub-' . $this->config['slug'], [$this, 'render_settings']);
        }

        public function action_links($links) {
            $links = is_array($links) ? $links : [];
            array_unshift($links, '<a href="' . esc_url($this->settings_url()) . '">Licence</a>');
            return $links;
        }

        private function status_help(string $status): string {
            return [
                'valid' => '',
                'expired' => 'Renouvelez votre licence auprès de Karom pour recevoir de nouveau les mises à jour.',
                'revoked' => 'Contactez Karom pour en savoir plus.',
                'site_inactive' => 'Réactivez ce site avec votre clé. Si l\'adresse du site a changé, c\'est la raison la plus courante.',
                'invalid_key' => 'Vérifiez la clé saisie ou contactez Karom si elle est correcte.',
                'no_key' => 'Activez votre licence pour recevoir les mises à jour.',
            ][$status] ?? '';
        }

        private function effective_status(): string {
            $state = $this->state();
            return empty($state['key']) ? 'no_key' : (string) ($state['status'] ?? 'unknown');
        }

        private function status_label(string $status): string {
            return [
                'valid' => 'Licence valide',
                'expired' => 'Licence expirée',
                'revoked' => 'Licence révoquée',
                'site_inactive' => 'Ce site n\'est pas activé',
                'invalid_key' => 'Clé inconnue du hub',
                'no_key' => 'Aucune licence enregistrée',
            ][$status] ?? 'Statut inconnu';
        }

        public function render_settings(): void {
            $state = $this->state();
            $key = (string) ($state['key'] ?? '');
            $status = $key === '' ? 'no_key' : (string) ($state['status'] ?? 'unknown');
            $slug = $this->config['slug'];
            $action = esc_url(admin_url('admin-post.php'));

            echo '<div class="wrap"><h1>' . esc_html(sprintf('Licence %s', $this->config['name'])) . '</h1>';

            $result = isset($_GET['karom_hub_result']) ? (string) $_GET['karom_hub_result'] : '';
            if ($result === 'ok') {
                echo '<div class="notice notice-success"><p>' . esc_html('Opération réussie.') . '</p></div>';
            } elseif ($result === 'error') {
                $code = (string) ($_GET['karom_hub_code'] ?? '');
                $detail = (string) ($_GET['karom_hub_detail'] ?? '');
                $detail = preg_match('/^[a-z0-9_]{1,24}$/', $detail) === 1 ? $detail : null;
                echo '<div class="notice notice-error"><p>' . esc_html($this->failure($code, null, $detail)['message']) . '</p></div>';
            }

            echo '<p><strong>' . esc_html($this->status_label($status)) . '</strong></p>';
            if ($this->status_help($status) !== '' && $key !== '') {
                echo '<p>' . esc_html($this->status_help($status)) . '</p>';
            }
            if ($key !== '') {
                $checked = (int) ($state['checked_at'] ?? 0) ?: (int) ($state['last_success'] ?? 0);
                echo '<p>Dernière vérification : ' . esc_html($checked > 0 ? $this->format_date($checked) : 'pas encore') . '</p>';
                echo '<p>Clé : <code>' . esc_html('••••••••' . substr($key, -4)) . '</code></p>';
                if (isset($state['seat_limit'], $state['seats_used'])) {
                    echo '<p>Sites activés : ' . esc_html($state['seats_used'] . ' / ' . $state['seat_limit']) . '</p>';
                }
                if (!empty($state['expires_at'])) {
                    echo '<p>Valable jusqu\'au ' . esc_html(substr((string) $state['expires_at'], 0, 10)) . '</p>';
                }
                if (!empty($state['error'])) {
                    echo '<p>Dernière vérification impossible : le dernier statut connu est conservé.</p>';
                }
                if ($status !== 'valid') {
                    echo '<form method="post" action="' . $action . '" style="display:inline-block;margin-right:8px">';
                    wp_nonce_field('karom_hub_activate_' . $slug);
                    echo '<input type="hidden" name="action" value="karom_hub_activate_' . esc_attr($slug) . '">';
                    echo '<button type="submit" class="button button-primary">Réactiver ce site</button></form>';
                }
                echo '<form method="post" action="' . $action . '" style="display:inline-block;margin-right:8px">';
                wp_nonce_field('karom_hub_refresh_' . $slug);
                echo '<input type="hidden" name="action" value="karom_hub_refresh_' . esc_attr($slug) . '">';
                echo '<button type="submit" class="button">Vérifier maintenant</button></form>';
                echo '<form method="post" action="' . $action . '" style="display:inline-block">';
                wp_nonce_field('karom_hub_deactivate_' . $slug);
                echo '<input type="hidden" name="action" value="karom_hub_deactivate_' . esc_attr($slug) . '">';
                echo '<button type="submit" class="button">Désactiver ce site</button></form>';
            } else {
                if (!get_option('permalink_structure')) {
                    echo '<div class="notice notice-warning inline"><p>Les permaliens sont en mode « simple » : le hub ne pourra pas vérifier ce site. Choisissez une autre structure dans Réglages › Permaliens avant d\'activer.</p></div>';
                }
                echo '<form method="post" action="' . $action . '">';
                wp_nonce_field('karom_hub_activate_' . $slug);
                echo '<input type="hidden" name="action" value="karom_hub_activate_' . esc_attr($slug) . '">';
                echo '<p><label>Clé de licence<br><input type="text" name="karom_hub_key" class="regular-text" autocomplete="off" required></label></p>';
                echo '<button type="submit" class="button button-primary">Activer ce site</button></form>';
            }
            echo '</div>';
        }

        private function format_date(int $timestamp): string {
            return function_exists('wp_date') ? (string) wp_date('d/m/Y H:i', $timestamp) : gmdate('d/m/Y H:i', $timestamp);
        }

        private function on_relevant_screen(): bool {
            if (!function_exists('get_current_screen')) {
                return true;
            }
            $screen = get_current_screen();
            return !$screen || in_array($screen->id, ['dashboard', 'plugins', 'themes'], true) || str_contains((string) $screen->id, 'karom-hub-');
        }

        public function render_notice(): void {
            if (!current_user_can('manage_options') || !$this->on_relevant_screen()) {
                return;
            }
            $state = $this->state();
            $status = $this->effective_status();
            $link = ' <a href="' . esc_url($this->settings_url()) . '">Gérer la licence</a>';

            if (in_array($status, self::PROBLEM_STATUSES, true)) {
                echo '<div class="notice notice-warning"><p>' . esc_html(sprintf('%s : %s. Le produit continue de fonctionner, mais les mises à jour sont suspendues.', $this->config['name'], strtolower($this->status_label($status)))) . $link . '</p></div>';
                return;
            }

            $last_success = (int) ($state['last_success'] ?? 0);
            if (!empty($state['error']) && $last_success > 0 && (time() - $last_success) >= self::STALE_AFTER) {
                $days = (int) floor((time() - $last_success) / DAY_IN_SECONDS);
                echo '<div class="notice notice-warning"><p>' . esc_html(sprintf('%s : impossible de vérifier votre licence depuis %d jours. Le produit continue de fonctionner. Si le problème persiste, contactez Karom.', $this->config['name'], $days)) . $link . '</p></div>';
            }
        }

        /** Line under the plugin on the Plugins screen. */
        public function plugin_row($plugin_file, $plugin_data, $status): void {
            if ($plugin_file !== $this->config['basename']) {
                return;
            }
            $current = $this->effective_status();
            if ($current !== 'no_key' && !in_array($current, self::PROBLEM_STATUSES, true)) {
                return;
            }
            $message = $current === 'no_key'
                ? $this->status_help('no_key')
                : $this->status_label($current) . '. ' . $this->status_help($current);
            echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>'
                . esc_html($message) . ' <a href="' . esc_url($this->settings_url()) . '">Licence</a></p></div></td></tr>';
        }

        public function handle_activate(): void {
            $this->handle_action('activate', function (): array {
                $typed = sanitize_text_field(wp_unslash((string) ($_POST['karom_hub_key'] ?? '')));
                return $this->activate($typed !== '' ? $typed : (string) ($this->state()['key'] ?? ''));
            });
        }

        public function handle_refresh(): void {
            $this->handle_action('refresh', function (): array {
                $state = $this->refresh_status();
                // Someone asked for a check: WordPress must re-evaluate its own list of updates, whatever changed.
                delete_site_transient($this->config['type'] === 'theme' ? 'update_themes' : 'update_plugins');
                if (empty($state['error'])) {
                    return ['ok' => true, 'code' => 'ok', 'status' => $state['status'] ?? null, 'message' => ''];
                }
                $error = (string) $state['error'];
                $code = $error === 'HTTP 429' ? 'rate_limited' : (str_starts_with($error, 'HTTP') ? 'unavailable' : 'network');
                return $this->failure($code, $state['status'] ?? null);
            });
        }

        public function handle_deactivate(): void {
            $this->handle_action('deactivate', fn (): array => $this->deactivate());
        }

        private function handle_action(string $name, callable $run): void {
            if (!current_user_can('manage_options')) {
                wp_die('Accès refusé.');
            }
            check_admin_referer('karom_hub_' . $name . '_' . $this->config['slug']);
            $result = $run();
            $query = [
                'karom_hub_result' => $result['ok'] ? 'ok' : 'error',
                'karom_hub_code' => $result['code'],
            ];
            if (!empty($result['detail'])) {
                $query['karom_hub_detail'] = (string) $result['detail'];
            }
            wp_safe_redirect(add_query_arg($query, $this->settings_url()));
            if (!defined('KAROM_HUB_TESTING')) {
                exit;
            }
        }
    }
}

if (!function_exists('karom_hub_client')) {
    /** Starts (once) the client of the plugin or theme that calls it. */
    function karom_hub_client(array $args = []): Karom_Hub_Client {
        return Karom_Hub_Client::boot($args);
    }
}
