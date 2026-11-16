<?php
/**
 * Internal supplier per product («این کالا را از چه کسی می‌خریم»). Admin-only: stored in the private
 * accounting database (mda_product_settings) and never output on the storefront.
 *
 * - preferred supplier, the supplier's own code for the item and a short internal note;
 * - filled automatically from the first posted purchase when empty;
 * - last purchase (supplier, date, unit cost) is always derived from posted purchase lines.
 *
 * Settings are kept per product (variation 0): variations inherit their parent's supplier.
 *
 * @package MaliDev_Accounting
 */

defined( 'ABSPATH' ) || exit;

final class MDA_Supplier_Service {
	public static function init(): void {
		add_action( 'mda_purchase_posted', array( __CLASS__, 'from_purchase' ), 20, 2 );
	}

	/** Parties with the supplier role, for selects. id => "code — name". */
	public static function suppliers(): array {
		try {
			$q = MDA_Database::connection()->query(
				"SELECT p.id, p.party_code, p.display_name FROM mda_parties p JOIN mda_party_roles r ON r.party_id = p.id AND r.role_key = 'supplier'
				WHERE COALESCE(p.is_active,1) = 1 ORDER BY p.display_name"
			);
			$out = array();
			foreach ( $q as $r ) {
				$out[ (int) $r['id'] ] = $r['display_name'] . ' (' . $r['party_code'] . ')';
			}
			return $out;
		} catch ( Throwable $e ) {
			return array();
		}
	}

	public static function get( int $product_id ): array {
		$row = array( 'supplier_id' => 0, 'supplier_name' => '', 'supplier_sku' => '', 'supplier_note' => '' );
		try {
			$q = MDA_Database::connection()->prepare(
				'SELECT s.preferred_supplier_party_id, s.supplier_sku, s.supplier_note, p.display_name FROM mda_product_settings s
				LEFT JOIN mda_parties p ON p.id = s.preferred_supplier_party_id WHERE s.product_id = :p AND s.variation_id = 0'
			);
			$q->execute( array( ':p' => $product_id ) );
			if ( $r = $q->fetch() ) {
				$row = array(
					'supplier_id'   => (int) $r['preferred_supplier_party_id'],
					'supplier_name' => (string) $r['display_name'],
					'supplier_sku'  => (string) $r['supplier_sku'],
					'supplier_note' => (string) $r['supplier_note'],
				);
			}
		} catch ( Throwable $e ) {
			return $row;
		}
		return $row;
	}

	/** Supplier names for many products at once (grid). product_id => name. */
	public static function names( array $product_ids ): array {
		$ids = array_values( array_filter( array_map( 'absint', $product_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		try {
			$in = implode( ',', $ids );
			$q  = MDA_Database::connection()->query(
				"SELECT s.product_id, p.display_name FROM mda_product_settings s JOIN mda_parties p ON p.id = s.preferred_supplier_party_id
				WHERE s.variation_id = 0 AND s.product_id IN ($in)" // Integers only (absint above).
			);
			return $q->fetchAll( PDO::FETCH_KEY_PAIR );
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * Saves supplier details without touching supply mode / lead time.
	 */
	public static function save( int $product_id, int $supplier_id, ?string $sku = null, ?string $note = null ): void {
		if ( $product_id < 1 ) {
			throw new InvalidArgumentException( 'محصول معتبر نیست.' );
		}
		$db = MDA_Database::connection();
		if ( $supplier_id > 0 ) {
			$s = $db->prepare( "SELECT 1 FROM mda_party_roles WHERE party_id = :id AND role_key = 'supplier'" );
			$s->execute( array( ':id' => $supplier_id ) );
			if ( ! $s->fetchColumn() ) {
				throw new InvalidArgumentException( 'تأمین‌کننده انتخاب‌شده معتبر نیست.' );
			}
		}
		$cur  = self::get( $product_id );
		$sku  = null === $sku ? $cur['supplier_sku'] : mb_substr( sanitize_text_field( $sku ), 0, 60 );
		$note = null === $note ? $cur['supplier_note'] : mb_substr( sanitize_text_field( $note ), 0, 200 );
		$db->prepare(
			"INSERT INTO mda_product_settings (product_id, variation_id, supply_mode, preferred_supplier_party_id, supplier_sku, supplier_note, updated_at)
			VALUES (:p, 0, 'stock', :s, :sku, :note, :now)
			ON CONFLICT(product_id, variation_id) DO UPDATE SET preferred_supplier_party_id = excluded.preferred_supplier_party_id,
			supplier_sku = excluded.supplier_sku, supplier_note = excluded.supplier_note, updated_at = excluded.updated_at"
		)->execute( array( ':p' => $product_id, ':s' => $supplier_id ?: null, ':sku' => $sku, ':note' => $note, ':now' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/** Most recent posted purchase lines of a product (any variation). */
	public static function last_purchases( int $product_id, int $limit = 3 ): array {
		try {
			$q = MDA_Database::connection()->prepare(
				"SELECT d.document_number, d.document_date, d.currency_code, p.display_name, l.unit_cost_minor, l.quantity_scaled, l.quantity_scale
				FROM mda_purchase_lines l JOIN mda_purchase_documents d ON d.id = l.purchase_id AND d.document_status = 'posted'
				JOIN mda_parties p ON p.id = d.supplier_party_id WHERE l.product_id = :p ORDER BY d.document_date DESC, d.id DESC LIMIT :l"
			);
			$q->bindValue( ':p', $product_id, PDO::PARAM_INT );
			$q->bindValue( ':l', $limit, PDO::PARAM_INT );
			$q->execute();
			return $q->fetchAll();
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/** First purchase of a product fills its supplier when none is set yet. */
	public static function from_purchase( int $purchase_id, array $lines ): void {
		try {
			$q = MDA_Database::connection()->prepare( 'SELECT supplier_party_id FROM mda_purchase_documents WHERE id = :id' );
			$q->execute( array( ':id' => $purchase_id ) );
			$supplier = (int) $q->fetchColumn();
			if ( $supplier < 1 ) {
				return;
			}
			$done = array();
			foreach ( $lines as $line ) {
				$pid = (int) ( $line['product_id'] ?? 0 );
				if ( $pid < 1 || isset( $done[ $pid ] ) ) {
					continue;
				}
				$done[ $pid ] = true;
				if ( 0 === self::get( $pid )['supplier_id'] ) {
					self::save( $pid, $supplier );
				}
			}
		} catch ( Throwable $e ) {
			error_log( 'MaliDev Accounting supplier auto-fill: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
