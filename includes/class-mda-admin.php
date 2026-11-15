<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Admin {
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'حسابداری MaliDev', 'malidev-accounting' ),
			__( 'حسابداری', 'malidev-accounting' ),
			MDA_Capabilities::MANAGE,
			'mda-accounting',
			array( __CLASS__, 'render' ),
			'dashicons-chart-area',
			56
		);
	}

	public static function render(): void {
		if ( ! current_user_can( MDA_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'malidev-accounting' ) );
		}

		$available = MDA_Database::is_available();
		$connected = false;
		$error = '';

		if ( $available ) {
			try {
				$pdo = MDA_Database::connection();
				$connected = true;
				$integrity = $pdo->query( 'PRAGMA quick_check' )->fetchColumn();
			} catch ( Throwable $exception ) {
				$error = $exception->getMessage();
			}
		}
		?>
		<div class="wrap" dir="rtl">
			<h1><?php echo esc_html__( 'حسابداری MaliDev', 'malidev-accounting' ); ?></h1>
			<p><?php echo esc_html__( 'نسخه اولیه هسته فنی؛ هنوز ثبت سند مالی و همگام‌سازی سفارش‌ها فعال نشده است.', 'malidev-accounting' ); ?></p>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th><?php echo esc_html__( 'نسخه افزونه', 'malidev-accounting' ); ?></th><td><?php echo esc_html( MDA_VERSION ); ?></td></tr>
					<tr><th>PDO_SQLite</th><td><?php echo $available ? esc_html__( 'فعال', 'malidev-accounting' ) : esc_html__( 'غیرفعال یا در دسترس نیست', 'malidev-accounting' ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'اتصال دیتابیس', 'malidev-accounting' ); ?></th><td><?php echo $connected ? esc_html__( 'برقرار', 'malidev-accounting' ) : esc_html__( 'برقرار نیست', 'malidev-accounting' ); ?></td></tr>
					<?php if ( $connected ) : ?>
						<tr><th><?php echo esc_html__( 'بررسی سلامت SQLite', 'malidev-accounting' ); ?></th><td><?php echo esc_html( (string) $integrity ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $error ) : ?>
						<tr><th><?php echo esc_html__( 'جزئیات خطا', 'malidev-accounting' ); ?></th><td><?php echo esc_html( $error ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html__( 'برای جلوگیری از ثبت ناقص، تا تکمیل لایه تراکنش و همگام‌سازی، هیچ رویداد فروش ووکامرس به دفتر مالی متصل نیست.', 'malidev-accounting' ); ?></p>
		</div>
		<?php
	}
}
