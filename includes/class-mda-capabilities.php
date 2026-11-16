<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Capabilities {
	public const MANAGE = 'manage_malidev_accounting';
	public const VIEW_REPORTS = 'view_malidev_financial_reports';
	public const CREATE_DOCUMENTS = 'create_malidev_accounting_documents';
	public const VOID_DOCUMENTS = 'void_malidev_accounting_documents';

	public static function add(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( array( self::MANAGE, self::VIEW_REPORTS, self::CREATE_DOCUMENTS, self::VOID_DOCUMENTS ) as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	public static function remove(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( array( self::MANAGE, self::VIEW_REPORTS, self::CREATE_DOCUMENTS, self::VOID_DOCUMENTS ) as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
