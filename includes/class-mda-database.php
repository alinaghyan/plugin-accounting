<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Database {
	private static ?PDO $connection = null;
	private static ?string $database_path = null;

	public static function is_available(): bool {
		return extension_loaded( 'pdo_sqlite' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true );
	}

	public static function path(): string {
		if ( null !== self::$database_path ) {
			return self::$database_path;
		}

		$preferred = trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . 'malidev-accounting-private';
		$fallback  = trailingslashit( WP_CONTENT_DIR ) . 'malidev-accounting-private';
		$custom    = defined( 'MDA_PRIVATE_DATA_DIR' ) ? MDA_PRIVATE_DATA_DIR : '';

		$candidates = array_filter( array( $custom, $preferred ) );

		// مسیر داخل wp-content فقط روی وب‌سرورهای شناخته‌شده با فایل deny ساخته می‌شود.
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
		if ( false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' ) || false !== strpos( $server, 'iis' ) ) {
			$candidates[] = $fallback;
		}

		foreach ( $candidates as $directory ) {
			$directory = untrailingslashit( $directory );
			if ( self::prepare_directory( $directory ) ) {
				self::$database_path = $directory . DIRECTORY_SEPARATOR . 'accounting.sqlite';
				return self::$database_path;
			}
		}

		throw new RuntimeException( 'مسیر خصوصی دیتابیس قابل ایجاد نیست. مقدار MDA_PRIVATE_DATA_DIR را در wp-config.php به یک مسیر خارج از web root تنظیم کنید.' );
	}

	private static function prepare_directory( string $directory ): bool {
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}
		if ( ! is_writable( $directory ) ) {
			return false;
		}

		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX );
		}

		$htaccess = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\nDeny from all\n", LOCK_EX );
		}

		$web_config = trailingslashit( $directory ) . 'web.config';
		if ( ! file_exists( $web_config ) ) {
			@file_put_contents(
				$web_config,
				'<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
				LOCK_EX
			);
		}

		return is_writable( $directory );
	}

	public static function connection(): PDO {
		if ( self::$connection instanceof PDO ) {
			return self::$connection;
		}
		if ( ! self::is_available() ) {
			throw new RuntimeException( 'افزونه PHP با نام PDO_SQLite روی این سرور فعال نیست.' );
		}

		$path = self::path();
		$is_new = ! file_exists( $path );
		$pdo = new PDO( 'sqlite:' . $path, null, null, array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::ATTR_TIMEOUT            => 5,
		) );

		$pdo->exec( 'PRAGMA foreign_keys = ON' );
		$pdo->exec( 'PRAGMA busy_timeout = 5000' );
		$pdo->exec( 'PRAGMA synchronous = NORMAL' );

		try {
			$pdo->query( 'PRAGMA journal_mode = WAL' )->fetchColumn();
		} catch ( PDOException $exception ) {
			$pdo->exec( 'PRAGMA journal_mode = DELETE' );
		}

		if ( $is_new && file_exists( $path ) ) {
			@chmod( $path, 0600 );
		}

		self::$connection = $pdo;
		return self::$connection;
	}

	public static function transaction( callable $callback ) {
		$pdo = self::connection();
		$pdo->beginTransaction();
		try {
			$result = $callback( $pdo );
			$pdo->commit();
			return $result;
		} catch ( Throwable $exception ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $exception;
		}
	}
}
