<?php
/**
 * Consistent SQLite backups (VACUUM INTO) kept next to the private database, with rotation and download.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Backup_Service {
	public const CRON = 'mda_daily_backup';

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'scheduled' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:30:00' ), 'daily', self::CRON );
		}
	}

	public static function directory(): string {
		$dir = dirname( MDA_Database::path() ) . DIRECTORY_SEPARATOR . 'backups';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'پوشه پشتیبان قابل ایجاد نیست.' );
		}
		foreach ( array( 'index.php' => "<?php\nhttp_response_code(404);\nexit;\n", '.htaccess' => "Require all denied\nDeny from all\n" ) as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
		return $dir;
	}

	public static function scheduled(): void {
		if ( ! MDA_Database::is_available() || ! MDA_Settings::get( 'backup_enabled' ) ) {
			return;
		}
		try {
			self::create( 'scheduled' );
		} catch ( Throwable $e ) {
			error_log( 'MaliDev Accounting backup failed: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	public static function create( string $trigger = 'manual' ): array {
		$db   = MDA_Database::connection();
		$name = 'accounting-' . gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '.sqlite';
		$file = self::directory() . DIRECTORY_SEPARATOR . $name;
		$q    = $db->prepare( 'VACUUM INTO :file' );
		$q->execute( array( ':file' => $file ) );
		@chmod( $file, 0600 ); // phpcs:ignore
		$check = new PDO( 'sqlite:' . $file, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		if ( 'ok' !== (string) $check->query( 'PRAGMA integrity_check' )->fetchColumn() ) {
			@unlink( $file ); // phpcs:ignore
			throw new RuntimeException( 'فایل پشتیبان ساخته شد ولی بررسی سلامت آن موفق نبود.' );
		}
		$check = null;
		$row   = array(
			'file_name'  => $name,
			'size_bytes' => (int) filesize( $file ),
			'sha256'     => hash_file( 'sha256', $file ),
		);
		$db->prepare( 'INSERT INTO mda_backups (file_name, size_bytes, sha256, trigger_type, created_by, created_at) VALUES (:n, :s, :h, :t, :u, :now)' )
			->execute( array( ':n' => $row['file_name'], ':s' => $row['size_bytes'], ':h' => $row['sha256'], ':t' => $trigger, ':u' => get_current_user_id() ?: null, ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
		self::rotate();
		return $row;
	}

	public static function rotate(): void {
		$keep  = max( 3, (int) MDA_Settings::get( 'backup_keep' ) );
		$files = glob( self::directory() . DIRECTORY_SEPARATOR . 'accounting-*.sqlite' ) ?: array();
		rsort( $files );
		foreach ( array_slice( $files, $keep ) as $old ) {
			@unlink( $old ); // phpcs:ignore
		}
	}

	public static function list(): array {
		$files = glob( self::directory() . DIRECTORY_SEPARATOR . 'accounting-*.sqlite' ) ?: array();
		rsort( $files );
		return array_map(
			static fn( $f ) => array( 'file_name' => basename( $f ), 'size_bytes' => (int) filesize( $f ), 'modified' => gmdate( 'Y-m-d H:i:s', (int) filemtime( $f ) ) ),
			$files
		);
	}

	/**
	 * Streams one backup file; the name is validated against the backup directory listing.
	 */
	public static function download( string $name ): void {
		if ( ! preg_match( '/^accounting-\d{8}-\d{6}-[a-f0-9]{8}\.sqlite$/', $name ) ) {
			wp_die( esc_html__( 'نام فایل پشتیبان معتبر نیست.', 'malidev-accounting' ) );
		}
		$file = self::directory() . DIRECTORY_SEPARATOR . $name;
		if ( ! is_file( $file ) ) {
			wp_die( esc_html__( 'فایل پشتیبان پیدا نشد.', 'malidev-accounting' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.sqlite3' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore
		exit;
	}
}
