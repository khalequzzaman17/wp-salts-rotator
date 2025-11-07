<?php
/**
 * Plugin Name: WP Salts Rotator
 * Plugin URI:  https://sysninja.net/
 * Description: Automatically rotate WP_SECRET keys & salts every 30 days and provide an admin UI to view/rotate them on demand.
 * Version:     1.0.0
 * Author:      Khalequzzaman
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Salts_Rotator {

	const CRON_HOOK = 'wpr_salts_rotate_event';
	const OPTION_LAST = 'wpr_salts_last_rotated';
	const PLUGIN_PREFIX = 'wpr_salts_';

	private $salt_api = 'https://api.wordpress.org/secret-key/1.1/salt/';
	private $keys = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'on_activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'on_deactivation' ) );

		add_filter( 'cron_schedules', array( $this, 'add_thirty_day_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_rotate_salts' ) );

		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_post_wpr_rotate_now', array( $this, 'handle_manual_rotate' ) );
	}

	/* Activation - schedule cron */
	public function on_activation() {
		// Ensure our custom schedule exists by forcing filter to run
		$this->add_thirty_day_schedule( array() );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_30_days', self::CRON_HOOK );
		}
	}

	/* Deactivation - clear cron */
	public function on_deactivation() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/* Add 30 day schedule */
	public function add_thirty_day_schedule( $schedules ) {
		// 30 days in seconds
		$schedules['every_30_days'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Every 30 Days', 'wpr-salts' ),
		);
		return $schedules;
	}

	/* Cron callback */
	public function cron_rotate_salts() {
		$result = $this->rotate_salts();
		// store last run info
		if ( is_wp_error( $result ) ) {
			update_option( self::OPTION_LAST, array(
				'time'   => time(),
				'status' => 'failed',
				'error'  => $result->get_error_message(),
			) );
		} else {
			update_option( self::OPTION_LAST, array(
				'time'   => time(),
				'status' => 'success',
			) );
		}
	}

	/* Manual rotate handler (admin_post) */
	public function handle_manual_rotate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'wpr-salts' ) );
		}
		check_admin_referer( 'wpr_rotate_now' );

		$result = $this->rotate_salts();

		if ( is_wp_error( $result ) ) {
			// redirect back with error
			$redirect = add_query_arg( 'wpr_error', urlencode( $result->get_error_message() ), admin_url( 'options-general.php?page=wpr-salts-rotator' ) );
			wp_redirect( $redirect );
			exit;
		} else {
			update_option( self::OPTION_LAST, array(
				'time'   => time(),
				'status' => 'success',
			) );
			$redirect = add_query_arg( 'wpr_success', '1', admin_url( 'options-general.php?page=wpr-salts-rotator' ) );
			wp_redirect( $redirect );
			exit;
		}
	}

	/* Main rotation routine */
	public function rotate_salts() {
		// 1) fetch new salts
		$content = $this->fetch_salts();
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$parsed = $this->parse_salt_lines( $content );
		if ( empty( $parsed ) ) {
			return new WP_Error( 'parse_failed', 'Failed to parse salt lines from API response.' );
		}

		// 2) update wp-config.php
		$update = $this->update_wp_config( $parsed );
		return $update;
	}

	/* Fetch using cURL (as requested) */
	private function fetch_salts() {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'no_curl', 'cURL is not available on this PHP installation.' );
		}

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->salt_api );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 20 );

		$response = curl_exec( $ch );

		if ( curl_errno( $ch ) ) {
			$err = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', $err );
		}

		$http = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $http < 200 || $http >= 300 ) {
			return new WP_Error( 'http_error', "Salt API returned HTTP code $http" );
		}

		return $response;
	}

	/* Parse API text into array keyed by CONSTANT => "define(...) line" */
	private function parse_salt_lines( $text ) {
		$lines = preg_split( "/\r\n|\n|\r/", trim( $text ) );
		$out   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( ! $line ) {
				continue;
			}
			// match define('CONSTANT', 'value');
			if ( preg_match( "/define\(\s*'([^']+)'\s*,\s*'(.+)'\s*\)\s*;/", $line, $m ) ) {
				$name = $m[1];
				$out[ $name ] = "define('" . $name . "', '" . $m[2] . "');";
			}
		}

		// Ensure we have the eight keys
		foreach ( $this->keys as $k ) {
			if ( ! isset( $out[ $k ] ) ) {
				// missing; abort
				return array();
			}
		}

		return $out;
	}

	/* Update wp-config.php safely: backup, replace or insert defines */
	private function update_wp_config( $new_defines ) {
		// locate wp-config.php
		// Prefer WP_CONFIG_FILE if defined, otherwise ABSPATH + wp-config.php, else one level up (typical WP)
		$possible = array();

		if ( defined( 'WP_CONFIG_FILE' ) ) {
			$possible[] = WP_CONFIG_FILE;
		}

		$possible[] = ABSPATH . 'wp-config.php';
		$possible[] = dirname( ABSPATH ) . '/wp-config.php';

		$found = false;
		$path  = '';

		foreach ( $possible as $p ) {
			if ( $p && file_exists( $p ) ) {
				$found = true;
				$path  = $p;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'no_wp_config', 'wp-config.php not found in expected locations.' );
		}

		// read file
		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			return new WP_Error( 'read_failed', "Failed to read $path" );
		}

		// backup first
		$bak_path = $path . '.wprbak.' . date( 'Ymd-His' );
		if ( ! copy( $path, $bak_path ) ) {
			return new WP_Error( 'backup_failed', "Failed to create backup: $bak_path" );
		}

		// For each key, try to replace existing define line; if not found, we'll insert block before "That's all, stop editing!"
		$modified = $contents;
		foreach ( $this->keys as $k ) {
			$pattern = "/define\\(\\s*'".preg_quote( $k, '/' )."'\\s*,\\s*'[^']*'\\s*\\)\\s*;/";
			if ( preg_match( $pattern, $modified ) ) {
				$modified = preg_replace( $pattern, $new_defines[ $k ], $modified, 1 );
			} else {
				// mark as missing by leaving for insertion later
			}
		}

		// If any of the constants were not present, insert block before the "stop editing" comment or at end if not found.
		$missing = array();
		foreach ( $this->keys as $k ) {
			$pattern = "/define\\(\\s*'".preg_quote( $k, '/' )."'\\s*,\\s*'[^']*'\\s*\\)\\s*;/";
			if ( ! preg_match( $pattern, $modified ) ) {
				$missing[] = $new_defines[ $k ];
			}
		}

		if ( ! empty( $missing ) ) {
			$insert_block = "\n/** WP Salts Rotator: inserted keys **/\n" . implode( "\n", $missing ) . "\n\n";
			// find stop editing comment
			$stop_pattern = "/\/\*\s*That's all, stop editing!.*\*\//s";
			if ( preg_match( $stop_pattern, $modified, $m, PREG_OFFSET_CAPTURE ) ) {
				$pos = $m[0][1];
				// insert before the comment
				$modified = substr_replace( $modified, $insert_block, $pos, 0 );
			} else {
				// append at end
				$modified .= "\n" . $insert_block;
			}
		}

		// final write
		$result = file_put_contents( $path, $modified, LOCK_EX );
		if ( $result === false ) {
			// attempt to restore backup
			@copy( $bak_path, $path );
			return new WP_Error( 'write_failed', "Failed to write updated wp-config.php. Backup is at $bak_path" );
		}

		return true;
	}

	/* Read current salts from wp-config.php to display on admin page */
	public function read_current_salts() {
		$paths = array(
			defined( 'WP_CONFIG_FILE' ) ? WP_CONFIG_FILE : false,
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);

		foreach ( $paths as $p ) {
			if ( $p && file_exists( $p ) ) {
				$contents = file_get_contents( $p );
				if ( $contents !== false ) {
					$out = array();
					foreach ( $this->keys as $k ) {
						if ( preg_match( "/define\\(\\s*'".preg_quote( $k, '/' )."'\\s*,\\s*'([^']*)'\\s*\\)\\s*;/", $contents, $m ) ) {
							$out[ $k ] = $m[1];
						} else {
							$out[ $k ] = null;
						}
					}
					return $out;
				}
			}
		}
		return null;
	}

	/* Admin UI */
	public function add_admin_page() {
		add_options_page(
			__( 'Salt Rotator', 'wpr-salts' ),
			__( 'Salt Rotator', 'wpr-salts' ),
			'manage_options',
			'wpr-salts-rotator',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$salts = $this->read_current_salts();
		$last  = get_option( self::OPTION_LAST );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Salts Rotator', 'wpr-salts' ); ?></h1>

			<?php if ( isset( $_GET['wpr_error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $_GET['wpr_error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['wpr_success'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Salts rotated successfully.', 'wpr-salts' ); ?></p></div>
			<?php endif; ?>

			<p><strong><?php esc_html_e( 'Warning:', 'wpr-salts' ); ?></strong>
				<?php esc_html_e( 'Rotating salts will invalidate all logged-in sessions. All users will be logged out and must log in again.', 'wpr-salts' ); ?>
			</p>

			<h2><?php esc_html_e( 'Current keys & salts (from wp-config.php)', 'wpr-salts' ); ?></h2>

			<?php if ( is_null( $salts ) ) : ?>
				<p><?php esc_html_e( 'Could not read wp-config.php from expected locations.', 'wpr-salts' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed" cellspacing="0">
					<thead><tr><th><?php esc_html_e( 'Constant', 'wpr-salts' ); ?></th><th><?php esc_html_e( 'Value', 'wpr-salts' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $salts as $k => $v ) : ?>
							<tr>
								<td><code><?php echo esc_html( $k ); ?></code></td>
								<td style="font-family: monospace; white-space: pre-wrap;"><?php echo $v === null ? '<em>' . esc_html__( 'Not defined', 'wpr-salts' ) . '</em>' : esc_html( $v ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Actions', 'wpr-salts' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpr_rotate_now' ); ?>
				<input type="hidden" name="action" value="wpr_rotate_now"/>
				<?php submit_button( __( 'Rotate now (manual)', 'wpr-salts' ) ); ?>
			</form>

			<?php if ( $last ) : ?>
				<h3><?php esc_html_e( 'Last rotation', 'wpr-salts' ); ?></h3>
				<ul>
					<li><?php echo esc_html( 'Time: ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] ) ); ?></li>
					<li><?php echo esc_html( 'Status: ' . ( isset( $last['status'] ) ? $last['status'] : 'unknown' ) ); ?></li>
					<?php if ( isset( $last['error'] ) ) : ?>
						<li><?php echo esc_html( 'Error: ' . $last['error'] ); ?></li>
					<?php endif; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No rotation recorded yet.', 'wpr-salts' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Notes', 'wpr-salts' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'A backup of wp-config.php is created before each rotation, named wp-config.php.wprbak.YYYYMMDD-HHMMSS', 'wpr-salts' ); ?></li>
				<li><?php esc_html_e( 'Plugin requires file write permission to wp-config.php. If the plugin cannot write, the change will fail and a backup remains.', 'wpr-salts' ); ?></li>
			</ul>
		</div>
		<?php
	}
}

new WP_Salts_Rotator();
