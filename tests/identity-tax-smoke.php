<?php
/**
 * Shared identity & tax service: national code checksum, mobile normalisation, api.ir response mapping,
 * Verhoeff, Moodian tax id layout, price-replacement message cleaning and bulk change sets.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
require_once dirname( __DIR__ ) . '/includes/class-mda-jalali.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-price-service.php';
require_once dirname( __DIR__ ) . '/includes/class-mda-product-grid-service.php';
require_once dirname( __DIR__ ) . '/includes/identity/class-mda-shahkar.php';
require_once dirname( __DIR__ ) . '/includes/identity/class-mda-moodian.php';

// National code.
mda_assert( MDA_Shahkar::is_valid_code( '0499370899' ), 'valid national code' );
mda_assert( ! MDA_Shahkar::is_valid_code( '0499370898' ), 'bad checksum rejected' );
mda_assert( ! MDA_Shahkar::is_valid_code( '1111111111' ), 'repeated digits rejected' );
mda_assert( '0499370899' === MDA_Shahkar::normalize_code( '۰۴۹۹-۳۷۰-۸۹۹' ), 'Persian digits normalised' );

// Mobile, same canonical form as the SMS plugin (so HMAC proofs match across plugins).
foreach ( array( '09121234567', '+989121234567', '00989121234567', '9121234567', '۰۹۱۲۱۲۳۴۵۶۷' ) as $m ) {
	mda_assert( '09121234567' === MDA_Shahkar::normalize_mobile( $m ), "mobile $m" );
}
mda_assert( '' === MDA_Shahkar::normalize_mobile( '02112345678' ), 'landline is not a mobile' );

// api.ir mapping: only explicit false is a mismatch.
mda_assert( 'match' === MDA_Shahkar::interpret( 200, array( 'success' => true, 'data' => true ) ), 'match' );
mda_assert( 'mismatch' === MDA_Shahkar::interpret( 200, array( 'success' => true, 'data' => false ) ), 'mismatch' );
mda_assert( 'error' === MDA_Shahkar::interpret( 200, array( 'success' => true ) ), 'unclear = error' );
mda_assert( 'error' === MDA_Shahkar::interpret( 500, array( 'success' => true, 'data' => true ) ), 'http error' );

// Verhoeff (textbook vector 236 → 3, 2363 valid).
mda_assert( 3 === MDA_Moodian::verhoeff( '236' ), 'verhoeff 236' );
mda_assert( MDA_Moodian::verhoeff_valid( '2363' ) && ! MDA_Moodian::verhoeff_valid( '2364' ), 'verhoeff validate' );

// Tax id layout.
$id = MDA_Moodian::tax_id( 'a11216', 1, 19000 * DAY_IN_SECONDS + 500 );
mda_assert( 22 === strlen( $id ), 'tax id is 22 chars' );
mda_assert( 'A11216' === substr( $id, 0, 6 ) && strtoupper( dechex( 19000 ) ) === ltrim( substr( $id, 6, 5 ), '0' ) && '0000000001' === substr( $id, 11, 10 ), 'memory id + hex days + hex serial' );
mda_assert( MDA_Moodian::verhoeff_valid( '65' . '11216' . '019000' . '000000000001' . substr( $id, -1 ) ), 'check digit over decimal form' );
mda_assert_throws( static fn() => MDA_Moodian::tax_id( 'ABC', 1, 0 ), 'memory id must be 6 chars' );
mda_assert( '1234567890123' === MDA_Moodian::clean_sstid( '۱۲۳۴۵۶۷۸۹۰۱۲۳۹۹' ), 'sstid cleaned to 13 digits' );

// Price-replacement message.
mda_assert( array() === MDA_Product_Grid_Service::clean_message( array( 'enabled' => '', 'title' => 'x' ) ), 'disabled message removed' );
mda_assert( array() === MDA_Product_Grid_Service::clean_message( array( 'enabled' => '1', 'title' => '' ) ), 'empty title removed' );
$m = MDA_Product_Grid_Service::clean_message( array( 'enabled' => '1', 'title' => '<b>در حال تأمین</b>', 'note' => str_repeat( 'ا', 60 ), 'phone' => '۰۲۱-۱۲۳۴<script>' ) );
mda_assert( 'در حال تأمین' === $m['title'] && 40 === mb_strlen( $m['note'] ) && '021-1234' === $m['phone'], 'message sanitised and capped' );

echo "identity-tax-smoke: OK\n";
