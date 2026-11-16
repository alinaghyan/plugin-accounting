<?php
/**
 * Capabilities and the dedicated accountant role.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Capabilities {
	/** Settings, chart of accounts, fiscal periods, backups, integrations. */
	public const MANAGE = 'manage_malidev_accounting';
	/** Read-only access to reports and ledgers. */
	public const VIEW_REPORTS = 'view_malidev_financial_reports';
	/** Day-to-day entry: purchases, sales, receipts, payments, cheques. */
	public const CREATE_DOCUMENTS = 'create_malidev_accounting_documents';
	/** Reversals, voids and closing periods. */
	public const VOID_DOCUMENTS = 'void_malidev_accounting_documents';

	public const ROLE_ACCOUNTANT = 'mda_accountant';

	public static function all(): array {
		return array( self::MANAGE, self::VIEW_REPORTS, self::CREATE_DOCUMENTS, self::VOID_DOCUMENTS );
	}

	public static function add(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $capability ) {
				$admin->add_cap( $capability );
			}
		}
		$manager = get_role( 'shop_manager' );
		if ( $manager ) {
			$manager->add_cap( self::VIEW_REPORTS );
			$manager->add_cap( self::CREATE_DOCUMENTS );
		}
	}

	public static function register_roles(): void {
		if ( ! get_role( self::ROLE_ACCOUNTANT ) ) {
			add_role(
				self::ROLE_ACCOUNTANT,
				'حسابدار',
				array(
					'read'                 => true,
					self::VIEW_REPORTS     => true,
					self::CREATE_DOCUMENTS => true,
					self::VOID_DOCUMENTS   => true,
				)
			);
		}
	}

	public static function remove(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( self::all() as $capability ) {
					$role->remove_cap( $capability );
				}
			}
		}
		remove_role( self::ROLE_ACCOUNTANT );
	}

	/**
	 * Capability required by each admin page slug. Pages not listed need MANAGE.
	 */
	public static function for_page( string $slug ): string {
		$map = array(
			'mda-accounting'          => self::VIEW_REPORTS,
			'mda-reports'             => self::VIEW_REPORTS,
			'mda-ledgers'             => self::VIEW_REPORTS,
			'mda-party-ledger'        => self::VIEW_REPORTS,
			'mda-parties'             => self::CREATE_DOCUMENTS,
			'mda-purchases'           => self::CREATE_DOCUMENTS,
			'mda-phone-sales'         => self::CREATE_DOCUMENTS,
			'mda-sales-receipts'      => self::CREATE_DOCUMENTS,
			'mda-sales-returns'       => self::CREATE_DOCUMENTS,
			'mda-cashbook'            => self::CREATE_DOCUMENTS,
			'mda-cheques'             => self::CREATE_DOCUMENTS,
			'mda-journal'             => self::CREATE_DOCUMENTS,
			'mda-inventory'           => self::CREATE_DOCUMENTS,
			'mda-bank-reconciliation' => self::CREATE_DOCUMENTS,
			'mda-gateway-settlements' => self::CREATE_DOCUMENTS,
		);
		if ( 'mda-prices' === $slug ) {
			return 'edit_products';
		}
		return $map[ $slug ] ?? self::MANAGE;
	}

	/**
	 * Capability required by each admin-post action. Unknown actions need MANAGE.
	 */
	public static function for_action( string $action ): string {
		if ( preg_match( '/^mda_(run_|save_account|gateway_configure|backup|restore|reclaim|close_period|reopen_period|create_period|save_settings|license)/', $action ) ) {
			return self::MANAGE;
		}
		if ( preg_match( '/^mda_export/', $action ) ) {
			return self::VIEW_REPORTS;
		}
		if ( preg_match( '/^mda_(void|reverse)/', $action ) ) {
			return self::VOID_DOCUMENTS;
		}
		return self::CREATE_DOCUMENTS;
	}

	public static function current_action(): string {
		// phpcs:ignore WordPress.Security.NonceVerification -- the nonce is verified by the handler right after this check.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		return $action;
	}

	/**
	 * Used by every handler and page renderer: admin-post handlers are checked per action,
	 * page renderers per page slug.
	 */
	public static function user_can_action(): bool {
		$action = self::current_action();
		if ( '' !== $action && 0 === strpos( $action, 'mda_' ) ) {
			return current_user_can( self::for_action( $action ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing value.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return current_user_can( '' !== $page ? self::for_page( $page ) : self::MANAGE );
	}
}
