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
delete_option( 'mda_schema_version' );
