<?php
/**
 * SQLite connection, private storage location and transaction guard.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Foreign_Database_Exception extends RuntimeException {}

final class MDA_Database {
	private const PATH_OPTION   = 'mda_database_file';
	private const LEGACY_FILE   = 'accounting.sqlite';
	private const DIRECTORY     = 'malidev-accounting-private';

	private static ?PDO $connection = null;
	private static ?string $database_path = null;

	public static function is_available(): bool {
		return extension_loaded( 'pdo_sqlite' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true );
	}

	/**
	 * Identity of this installation: install path + blog id + table prefix.
	 * A staging copy in another folder or another blog of a network gets its own ledger.
	 */
	public static function site_fingerprint(): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$prefix  = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? (string) $GLOBALS['wpdb']->prefix : '';
		$root    = wp_normalize_path( untrailingslashit( ABSPATH ) );
		return substr( hash( 'sha256', $root . '|' . $blog_id . '|' . $prefix ), 0, 24 );
	}

	/**
	 * After moving a site (new path/host) the administrator explicitly re-claims the copied ledger.
	 */
	public static function reclaim(): void {
		$pdo = new PDO( 'sqlite:' . self::path(), null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		$q   = $pdo->prepare( "INSERT OR REPLACE INTO mda_settings (setting_key,setting_value,updated_at) VALUES ('site_fingerprint',:v,:now)" );
		$q->execute( array( ':v' => self::site_fingerprint(), ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
		self::$connection = null;
	}

	public static function path(): string {
		if ( null !== self::$database_path ) {
			return self::$database_path;
		}

		$stored = (string) get_option( self::PATH_OPTION, '' );
		if ( '' !== $stored && is_file( $stored ) && is_writable( dirname( $stored ) ) ) {
			self::$database_path = $stored;
			return self::$database_path;
		}

		foreach ( self::candidate_directories() as $directory => $allow_webroot ) {
			if ( ! self::prepare_directory( $directory, $allow_webroot ) ) {
				continue;
			}
			$file = self::choose_file( $directory );
			update_option( self::PATH_OPTION, $file, false );
			self::$database_path = $file;
			return self::$database_path;
		}

		throw new RuntimeException( 'مسیر خصوصی دیتابیس قابل ایجاد نیست. مقدار MDA_PRIVATE_DATA_DIR را در wp-config.php به یک مسیر خارج از web root تنظیم کنید.' );
	}

	/**
	 * @return array<string,bool> directory => whether a web-reachable location is acceptable.
	 */
	private static function candidate_directories(): array {
		$candidates = array();
		if ( defined( 'MDA_PRIVATE_DATA_DIR' ) && '' !== (string) MDA_PRIVATE_DATA_DIR ) {
			$candidates[ untrailingslashit( (string) MDA_PRIVATE_DATA_DIR ) ] = false;
		}
		$candidates[ trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . self::DIRECTORY ] = false;

		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		if ( false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' ) || false !== strpos( $server, 'iis' ) ) {
			$candidates[ trailingslashit( WP_CONTENT_DIR ) . self::DIRECTORY ] = true;
		}
		return $candidates;
	}

	/**
	 * A legacy shared file is adopted only if it is unclaimed or already claimed by this site.
	 */
	private static function choose_file( string $directory ): string {
		$legacy = $directory . DIRECTORY_SEPARATOR . self::LEGACY_FILE;
		if ( is_file( $legacy ) ) {
			$owner = self::read_fingerprint( $legacy );
			if ( null === $owner || hash_equals( $owner, self::site_fingerprint() ) ) {
				return $legacy;
			}
		}
		return $directory . DIRECTORY_SEPARATOR . 'accounting-' . self::site_fingerprint() . '.sqlite';
	}

	private static function read_fingerprint( string $file ): ?string {
		try {
			$pdo = new PDO( 'sqlite:' . $file, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$has = $pdo->query( "SELECT 1 FROM sqlite_master WHERE type='table' AND name='mda_settings'" )->fetchColumn();
			if ( ! $has ) {
				return null;
			}
			$value = $pdo->query( "SELECT setting_value FROM mda_settings WHERE setting_key='site_fingerprint'" )->fetchColumn();
			return false === $value || '' === (string) $value ? null : (string) $value;
		} catch ( Throwable $e ) {
			return 'unreadable';
		}
	}

	/**
	 * Stamps the database with this site's fingerprint or refuses a database that belongs to another site.
	 */
	public static function claim( PDO $pdo ): void {
		$has = $pdo->query( "SELECT 1 FROM sqlite_master WHERE type='table' AND name='mda_settings'" )->fetchColumn();
		if ( ! $has ) {
			return;
		}
		$current = $pdo->query( "SELECT setting_value FROM mda_settings WHERE setting_key='site_fingerprint'" )->fetchColumn();
		$mine    = self::site_fingerprint();
		if ( false === $current || '' === (string) $current ) {
			$q = $pdo->prepare( "INSERT OR REPLACE INTO mda_settings (setting_key,setting_value,updated_at) VALUES ('site_fingerprint',:v,:now)" );
			$q->execute( array( ':v' => $mine, ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
			return;
		}
		if ( ! hash_equals( (string) $current, $mine ) && ! apply_filters( 'mda_allow_foreign_database', false ) ) {
			throw new MDA_Foreign_Database_Exception( 'فایل دیتابیس حسابداری متعلق به نصب دیگری (یا مسیر قبلی همین سایت) است. اگر سایت را منتقل کرده‌اید، از «حسابداری ← ابزارها» انتقال دیتابیس را تأیید کنید.' );
		}
	}

	private static function prepare_directory( string $directory, bool $allow_webroot ): bool {
		if ( ! is_dir( $directory ) ) {
			if ( ! wp_mkdir_p( $directory ) ) {
				return false;
			}
			@chmod( $directory, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort; only the PHP user needs access.
		}
		if ( ! is_writable( $directory ) || ! self::is_private_location( $directory, $allow_webroot ) ) {
			return false;
		}
		$guards = array(
			'index.php'  => "<?php\nhttp_response_code(404);\nexit;\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $guards as $name => $content ) {
			$file = trailingslashit( $directory ) . $name;
			if ( ! file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $file, $content, LOCK_EX );
			}
		}
		return is_writable( $directory );
	}

	/**
	 * The directory must sit outside every known web root. In CLI/system cron DOCUMENT_ROOT is empty,
	 * so ABSPATH is always used as an additional web-root reference instead of failing.
	 */
	private static function is_private_location( string $directory, bool $allow_webroot ): bool {
		$path = realpath( $directory );
		if ( false === $path ) {
			return false;
		}
		if ( $allow_webroot ) {
			return true;
		}
		$path  = trailingslashit( wp_normalize_path( $path ) );
		$roots = array( ABSPATH );
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		}
		foreach ( $roots as $root ) {
			$real = realpath( (string) $root );
			if ( false === $real ) {
				continue;
			}
			if ( 0 === strpos( $path, trailingslashit( wp_normalize_path( $real ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	public static function connection(): PDO {
		if ( self::$connection instanceof PDO ) {
			return self::$connection;
		}
		if ( ! self::is_available() ) {
			throw new RuntimeException( 'افزونه PHP با نام PDO_SQLite روی این سرور فعال نیست.' );
		}

		$path   = self::path();
		$is_new = ! file_exists( $path );
		$pdo    = new PDO(
			'sqlite:' . $path,
			null,
			null,
			array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
				PDO::ATTR_TIMEOUT            => 5,
			)
		);
		self::configure( $pdo );
		if ( $is_new && file_exists( $path ) ) {
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::claim( $pdo );

		self::$connection = $pdo;
		return self::$connection;
	}

	public static function configure( PDO $pdo ): void {
		$pdo->exec( 'PRAGMA foreign_keys = ON' );
		$pdo->exec( 'PRAGMA busy_timeout = 5000' );
		$pdo->exec( 'PRAGMA synchronous = NORMAL' );
		try {
			$pdo->query( 'PRAGMA journal_mode = WAL' )->fetchColumn();
		} catch ( PDOException $exception ) {
			$pdo->exec( 'PRAGMA journal_mode = DELETE' );
		}
	}

	/**
	 * Test seam: lets the test suite run the real transaction guard against an in-memory database.
	 */
	public static function use_connection( PDO $pdo ): void {
		self::$connection = $pdo;
	}

	/**
	 * Runs a callback in one SQLite transaction. Nested calls join the outer transaction.
	 * Before commit, every accounting document created in the transaction must be balanced.
	 */
	public static function transaction( callable $callback ) {
		$pdo = self::connection();
		if ( $pdo->inTransaction() ) {
			return $callback( $pdo );
		}
		$pdo->beginTransaction();
		try {
			$watermark = self::document_watermark( $pdo );
			$result    = $callback( $pdo );
			self::assert_balanced_since( $pdo, $watermark );
			$pdo->commit();
			return $result;
		} catch ( Throwable $exception ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $exception;
		}
	}

	private static function document_watermark( PDO $pdo ): ?int {
		$has = $pdo->query( "SELECT 1 FROM sqlite_master WHERE type='table' AND name='mda_documents'" )->fetchColumn();
		if ( ! $has ) {
			return null;
		}
		return (int) $pdo->query( 'SELECT COALESCE(MAX(id),0) FROM mda_documents' )->fetchColumn();
	}

	/**
	 * Central double-entry guarantee for every service that writes journal lines.
	 */
	public static function assert_balanced_since( PDO $pdo, ?int $watermark ): void {
		if ( null === $watermark ) {
			return;
		}
		$q = $pdo->prepare(
			"SELECT d.id, d.document_number, COUNT(e.id) AS lines,
				COALESCE(SUM(e.debit_minor),0) AS debit, COALESCE(SUM(e.credit_minor),0) AS credit
			FROM mda_documents d LEFT JOIN mda_journal_entries e ON e.document_id = d.id
			WHERE d.id > :w AND d.document_status IN ('posted','voided')
			GROUP BY d.id
			HAVING lines < 2 OR debit <> credit OR debit = 0"
		);
		$q->execute( array( ':w' => $watermark ) );
		$bad = $q->fetch();
		if ( $bad ) {
			throw new RuntimeException( 'سند حسابداری ' . $bad['document_number'] . ' تراز نیست (بدهکار ' . $bad['debit'] . ' / بستانکار ' . $bad['credit'] . ')؛ کل عملیات برگشت داده شد.' );
		}
	}
}
