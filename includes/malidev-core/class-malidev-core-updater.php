<?php
/**
 * Update delivery for MaliDev plugins.
 *
 * Primary source: the license server (GitHub details stay private on malidev.ir):
 *   POST {license-server}/update  license_key, product, domain, version
 *   → { "version": "1.2.0", "package": "https://…zip", "requires": "6.3", "tested": "6.8", "requires_php": "8.1", "changelog": "html" }
 * Optional fallback for public repositories: GitHub "latest release" (enable with the
 * `malidev_core_allow_github_updates` filter); the release must attach a built plugin zip.
 *
 * @package MaliDev_Core
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'MaliDev_Core_Updater' ) ) {
	return;
}

final class MaliDev_Core_Updater {
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function boot(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function flush(): void {
		foreach ( array_keys( MaliDev_Core::products() ) as $slug ) {
			delete_site_transient( 'malidev_update_' . $slug );
		}
	}

	private static function remote( array $product ): ?array {
		$cache_key = 'malidev_update_' . $product['slug'];
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}
		$data  = null;
		$state = MaliDev_Core_License::state( $product['slug'] );
		if ( MaliDev_Core_License::is_active( $product['slug'] ) ) {
			$response = wp_remote_post(
				MaliDev_Core_License::server() . '/update',
				array(
					'timeout' => 15,
					'body'    => array(
						'license_key' => $state['key'],
						'product'     => $product['license_product'],
						'domain'      => MaliDev_Core_License::domain(),
						'version'     => $product['version'],
					),
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				if ( is_array( $json ) && ! empty( $json['version'] ) && ! empty( $json['package'] ) ) {
					$data = $json;
				}
			}
		}
		if ( null === $data && $product['repository'] && apply_filters( 'malidev_core_allow_github_updates', false, $product ) ) {
			$data = self::github( (string) $product['repository'] );
		}
		set_site_transient( $cache_key, $data ?: array(), self::CACHE_TTL );
		return $data;
	}

	private static function github( string $repository ): ?array {
		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository ) ) {
			return null;
		}
		$response = wp_remote_get( 'https://api.github.com/repos/' . $repository . '/releases/latest', array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return null;
		}
		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['browser_download_url'] ) && str_ends_with( (string) $asset['name'], '.zip' ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			return null;
		}
		return array(
			'version'   => ltrim( (string) $release['tag_name'], 'vV' ),
			'package'   => $package,
			'changelog' => wp_kses_post( nl2br( esc_html( (string) ( $release['body'] ?? '' ) ) ) ),
		);
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		foreach ( MaliDev_Core::products() as $product ) {
			$remote = self::remote( $product );
			if ( ! $remote || ! version_compare( (string) $remote['version'], (string) $product['version'], '>' ) ) {
				continue;
			}
			$transient->response[ $product['basename'] ] = (object) array(
				'slug'         => dirname( $product['basename'] ),
				'plugin'       => $product['basename'],
				'new_version'  => (string) $remote['version'],
				'package'      => esc_url_raw( (string) $remote['package'] ),
				'tested'       => (string) ( $remote['tested'] ?? '' ),
				'requires'     => (string) ( $remote['requires'] ?? '' ),
				'requires_php' => (string) ( $remote['requires_php'] ?? '' ),
				'url'          => 'https://malidev.ir',
				'icons'        => $product['icon'] ? array( 'svg' => $product['icon'], '1x' => $product['icon'] ) : array(),
			);
		}
		return $transient;
	}

	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		foreach ( MaliDev_Core::products() as $product ) {
			if ( dirname( $product['basename'] ) !== $args->slug ) {
				continue;
			}
			$remote = self::remote( $product );
			return (object) array(
				'name'          => $product['name'],
				'slug'          => $args->slug,
				'version'       => $remote['version'] ?? $product['version'],
				'author'        => 'MaliDev',
				'homepage'      => 'https://malidev.ir',
				'requires'      => $remote['requires'] ?? '',
				'tested'        => $remote['tested'] ?? '',
				'requires_php'  => $remote['requires_php'] ?? '',
				'download_link' => $remote['package'] ?? '',
				'sections'      => array( 'changelog' => (string) ( $remote['changelog'] ?? '' ) ),
				'icons'         => $product['icon'] ? array( 'svg' => $product['icon'] ) : array(),
			);
		}
		return $result;
	}
}
