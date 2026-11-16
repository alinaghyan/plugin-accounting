<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * داده‌های مالی در زمان حذف افزونه عمداً پاک نمی‌شوند.
 * حذف دیتابیس فقط باید از ابزار پشتیبان‌گیری/مدیریت داده با تأیید صریح مدیر انجام شود.
 */
$role = get_role( 'administrator' );
if ( $role ) {
	foreach ( array(
		'manage_malidev_accounting',
		'view_malidev_financial_reports',
		'create_malidev_accounting_documents',
		'void_malidev_accounting_documents',
	) as $capability ) {
		$role->remove_cap( $capability );
	}
}
remove_role( 'mda_accountant' );
$shop_manager = get_role( 'shop_manager' );
if ( $shop_manager ) {
	$shop_manager->remove_cap( 'view_malidev_financial_reports' );
	$shop_manager->remove_cap( 'create_malidev_accounting_documents' );
}
foreach ( array( 'mda_schema_version', 'mda_schema_cache', 'mda_settings', 'mda_license' ) as $option ) {
	delete_option( $option );
}
// The database file path is kept on purpose so a reinstall reconnects to the same ledger.
