<?php
/**
 * Audit log writer and reader.
 *
 * @package t3admin
 * @since   1.0.0
 */

namespace T3Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all JSONL audit-log I/O for the plugin.
 *
 * @since 1.0.0
 */
class Logger {

	const LOG_DIR  = 't3admin-logs';
	const LOG_FILE = 'access-grants.jsonl';

	/**
	 * Returns the absolute path to the log directory inside wp-content/uploads.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function log_dir(): string {
		return wp_upload_dir()['basedir'] . '/' . self::LOG_DIR;
	}

	/**
	 * Returns the absolute path to the JSONL log file.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function log_path(): string {
		return $this->log_dir() . '/' . self::LOG_FILE;
	}

	/**
	 * Creates the log directory and protective files if they do not exist.
	 *
	 * Writes web-server deny rules and index stubs to reduce direct HTTP
	 * exposure across common stacks.
	 *
	 * @since 1.0.0
	 */
	public function ensure_log_dir(): void {
		$dir = $this->log_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, $rules );
		}
		$web_config = $dir . '/web.config';
		if ( ! file_exists( $web_config ) ) {
			$config = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n"
				. "\t<system.webServer>\n"
				. "\t\t<security>\n"
				. "\t\t\t<authorization>\n"
				. "\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n"
				. "\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n"
				. "\t\t\t</authorization>\n"
				. "\t\t</security>\n"
				. "\t</system.webServer>\n"
				. "</configuration>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $web_config, $config );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	/**
	 * Appends a JSONL entry to the audit log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event One of 'granted', 'revoked', or 'expired'.
	 * @param array  $grant The grant record being logged.
	 */
	public function log( string $event, array $grant ): void {
		$this->ensure_log_dir();
		$entry = array(
			'timestamp'      => gmdate( 'c' ),
			'event'          => $event,
			'grant_id'       => $grant['id'],
			'user_id'        => $grant['user_id'],
			'original_role'  => $grant['original_role'],
			'temporary_role' => $grant['temporary_role'],
			'granted_by'     => $grant['granted_by'],
			'granted_at'     => $grant['granted_at'],
			'expires_at'     => $grant['expires_at'],
		);
		if ( 'granted' !== $event ) {
			$entry['resolved_at'] = $grant['resolved_at'] ?? time();
		}
		if ( isset( $grant['revoked_by'] ) ) {
			$entry['revoked_by']    = $grant['revoked_by'];
			$entry['revoke_reason'] = $grant['revoke_reason'] ?? '';
		}
		// Direct file I/O is intentional: WP_Filesystem requires an admin
		// context and is inappropriate for background log writes.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_path(), wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Reads a page of log entries from the JSONL file, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $per_page Entries per page.
	 * @param int $page     1-based page number.
	 * @return array{ entries: array, total: int }
	 */
	public function read_log( int $per_page = 50, int $page = 1 ): array {
		$path = $this->log_path();
		if ( ! file_exists( $path ) ) {
			return array(
				'entries' => array(),
				'total'   => 0,
			);
		}

		$per_page = max( 1, $per_page );
		$page     = max( 1, $page );

		$total = $this->count_non_empty_lines( $path );
		if ( 0 === $total ) {
			return array(
				'entries' => array(),
				'total'   => 0,
			);
		}

		$offset_newest = ( $page - 1 ) * $per_page;
		if ( $offset_newest >= $total ) {
			return array(
				'entries' => array(),
				'total'   => $total,
			);
		}

		$end_newest_exclusive = min( $offset_newest + $per_page, $total );
		$start_original       = $total - $end_newest_exclusive;
		$end_original         = $total - $offset_newest;

		// Direct file I/O is intentional: WP_Filesystem requires an admin
		// context and is inappropriate for background log reads.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return array(
				'entries' => array(),
				'total'   => $total,
			);
		}

		$lines          = array();
		$non_empty_line = 0;
		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$trimmed = rtrim( $line, "\r\n" );
			if ( '' === $trimmed ) {
				continue;
			}

			if ( $non_empty_line >= $start_original && $non_empty_line < $end_original ) {
				$lines[] = $trimmed;
			}

			++$non_empty_line;
			if ( $non_empty_line >= $end_original ) {
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		$entries = array();
		foreach ( array_reverse( $lines ) as $line ) {
			$decoded = json_decode( $line, true );
			if ( $decoded ) {
				$entries[] = $decoded;
			}
		}

		return array(
			'entries' => $entries,
			'total'   => $total,
		);
	}

	/**
	 * Counts non-empty lines in a JSONL file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path to the JSONL file.
	 * @return int
	 */
	private function count_non_empty_lines( string $path ): int {
		// Direct file I/O is intentional: WP_Filesystem requires an admin
		// context and is inappropriate for background log reads.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return 0;
		}

		$count = 0;
		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			if ( '' !== rtrim( $line, "\r\n" ) ) {
				++$count;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $count;
	}
}
