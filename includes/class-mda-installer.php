<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Installer {
	private const SCHEMA_VERSION = 1;

	public static function activate(): void {
		if ( ! MDA_Database::is_available() ) {
			wp_die(
				esc_html__( 'برای نصب MaliDev Accounting باید افزونه PHP با نام PDO_SQLite فعال باشد.', 'malidev-accounting' ),
				esc_html__( 'پیش‌نیاز نصب موجود نیست', 'malidev-accounting' ),
				array( 'back_link' => true )
			);
		}

		try {
			self::migrate();
			MDA_Capabilities::add();
		} catch ( Throwable $exception ) {
			wp_die(
				esc_html( 'نصب افزونه انجام نشد: ' . $exception->getMessage() ),
				esc_html__( 'خطا در نصب حسابداری', 'malidev-accounting' ),
				array( 'back_link' => true )
			);
		}
	}

	public static function migrate(): void {
		$pdo = MDA_Database::connection();
		$current = (bool) $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name='mda_settings'" )->fetchColumn();

		if ( $current ) {
			$statement = $pdo->prepare( "SELECT setting_value FROM mda_settings WHERE setting_key = :key" );
			$statement->execute( array( ':key' => 'schema_version' ) );
			$version = (int) $statement->fetchColumn();
		} else {
			$version = 0;
		}

		if ( $version < 1 ) {
			$sql = file_get_contents( MDA_PATH . 'database/migrations/001_initial.sql' );
			if ( false === $sql ) {
				throw new RuntimeException( 'فایل مهاجرت اولیه پیدا نشد.' );
			}
			MDA_Database::transaction(
				static function ( PDO $connection ) use ( $sql ) {
					$connection->exec( $sql );
					$statement = $connection->prepare(
						"INSERT OR REPLACE INTO mda_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated)"
					);
					$statement->execute( array(
						':key'     => 'schema_version',
						':value'   => (string) self::SCHEMA_VERSION,
						':updated' => gmdate( 'Y-m-d H:i:s' ),
					) );
				}
			);
		}

		update_option( 'mda_schema_version', self::SCHEMA_VERSION, false );
	}
}
