<?php

use PHPUnit\Framework\TestCase;

/**
 * The plugin gets its updates and its licence screen from the Karom Hub through the hub's own client file.
 * These checks keep the header, the loading and the client file in step with what the hub expects.
 */
final class KaromHubCompatTest extends TestCase {
	private const HUB = 'https://hub.karom.studio';

	private function mainFile(): string {
		return dirname(__DIR__, 2) . '/backup-guard.php';
	}

	public function testHeaderPointsUpdatesAtTheHub(): void {
		$header = substr((string) file_get_contents($this->mainFile()), 0, 2048);
		$this->assertMatchesRegularExpression('/^ \* Update URI:\s*' . preg_quote(self::HUB, '/') . '\s*$/m', $header);
	}

	public function testMainFileLoadsTheClientAfterTheConstantsAndStartsIt(): void {
		$code = (string) file_get_contents($this->mainFile());
		$constants = strpos($code, "define('BACKUP_GUARD_DIR'");
		$require = strpos($code, "require_once BACKUP_GUARD_DIR . '/includes/class-karom-hub-client.php';");
		$start = strpos($code, 'karom_hub_client();');
		$this->assertNotFalse($constants);
		$this->assertNotFalse($require, 'the client file is loaded');
		$this->assertNotFalse($start, 'the client is started');
		$this->assertLessThan($require, $constants);
		$this->assertLessThan($start, $require);
	}

	public function testAClientThatCannotStartNeverBreaksTheBackupPlugin(): void {
		$code = (string) file_get_contents($this->mainFile());
		$this->assertMatchesRegularExpression('/try\s*\{\s*karom_hub_client\(\);\s*\}\s*catch\s*\(Throwable/', $code);
	}

	public function testClientFileIsShipped(): void {
		$file = dirname(__DIR__, 2) . '/includes/class-karom-hub-client.php';
		$this->assertFileExists($file);
		$this->assertStringContainsString('function karom_hub_client', (string) file_get_contents($file));
	}

	public function testClientResolvesThePluginAsShippedInTheZip(): void {
		// The zip puts everything under backup-guard/: build the same layout and let the client look at it.
		$plugins = sys_get_temp_dir() . '/backup-guard-hub-' . getmypid() . '/plugins';
		mkdir($plugins . '/backup-guard', 0777, true);
		copy($this->mainFile(), $plugins . '/backup-guard/backup-guard.php');
		if (!defined('WP_PLUGIN_DIR')) {
			define('WP_PLUGIN_DIR', $plugins);
		}
		$this->assertSame($plugins, WP_PLUGIN_DIR, 'another test already fixed the plugin folder');

		foreach (array('MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400) as $name => $seconds) {
			if (!defined($name)) {
				define($name, $seconds);
			}
		}
		foreach (array('add_filter', 'add_action') as $name) {
			if (!function_exists($name)) {
				eval('function ' . $name . '($tag, $callback, $priority = 10, $args = 1) { return true; }');
			}
		}
		if (!function_exists('wp_parse_url')) {
			function wp_parse_url($url, $component = -1) {
				return parse_url($url, $component);
			}
		}
		if (!function_exists('untrailingslashit')) {
			function untrailingslashit($value) {
				return rtrim($value, '/\\');
			}
		}
		if (!function_exists('get_file_data')) {
			function get_file_data($file, $headers) {
				$head = (string) file_get_contents($file, false, null, 0, 8192);
				$out = array();
				foreach ($headers as $key => $label) {
					$out[$key] = preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi', $head, $m) ? trim($m[1]) : '';
				}
				return $out;
			}
		}

		require_once dirname(__DIR__, 2) . '/includes/class-karom-hub-client.php';
		Karom_Hub_Client::reset();
		$client = Karom_Hub_Client::boot(array('caller_file' => $plugins . '/backup-guard/backup-guard.php'));
		$config = $client->config();

		$this->assertSame('backup-guard', $config['slug']);
		$this->assertSame('plugin', $config['type']);
		$this->assertSame(self::HUB, $config['hub_url']);
		$this->assertSame('backup-guard/backup-guard.php', $config['basename']);
		$this->assertSame('Backup Guard', $config['name']);
		$this->assertSame(BACKUP_GUARD_TEST_VERSION, $config['version']);
	}
}
