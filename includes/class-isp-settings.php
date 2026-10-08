<?php
/**
 * Réglages du plugin : une seule option, valeurs par défaut et nettoyage.
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

class ISP_Settings {

	const OPTION = 'isp_settings';

	/**
	 * Cache des réglages pour la requête en cours.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	public static function defaults() {
		return array(
			'formats'          => array( 'webp' ),
			'quality'          => 80,
			'auto_optimize'    => true,
			'delivery'         => 'picture',
			'lazy_skip'        => 3,
			'alt_fallback'     => 'library',
			'ai_provider'      => 'ollama',
			'ai_url'           => 'http://host.docker.internal:11434',
			'ai_model'         => 'gemma3:4b',
			'ai_key'           => '',
			'ai_context'       => true,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Formats demandés ET pris en charge par le serveur.
	 */
	public static function active_formats() {
		return array_values( array_filter( (array) self::get( 'formats' ), array( 'ISP_Converter', 'supports' ) ) );
	}

	/**
	 * Nettoie les valeurs envoyées par le formulaire de réglages.
	 *
	 * @param mixed $input Valeurs brutes.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$formats        = isset( $input['formats'] ) ? (array) $input['formats'] : array();
		$out['formats'] = array_values( array_intersect( array( 'webp', 'avif' ), array_map( 'strval', $formats ) ) );

		$quality        = isset( $input['quality'] ) ? (int) $input['quality'] : $defaults['quality'];
		$out['quality'] = min( 100, max( 30, $quality ) );

		$out['auto_optimize'] = ! empty( $input['auto_optimize'] );
		$out['ai_context']    = ! empty( $input['ai_context'] );

		$out['delivery'] = isset( $input['delivery'] ) && in_array( $input['delivery'], array( 'picture', 'rewrite', 'off' ), true )
			? $input['delivery'] : $defaults['delivery'];

		$out['lazy_skip'] = isset( $input['lazy_skip'] ) ? min( 20, max( 0, (int) $input['lazy_skip'] ) ) : $defaults['lazy_skip'];

		$out['alt_fallback'] = isset( $input['alt_fallback'] ) && in_array( $input['alt_fallback'], array( 'library', 'title', 'off' ), true )
			? $input['alt_fallback'] : $defaults['alt_fallback'];

		$out['ai_provider'] = isset( $input['ai_provider'] ) && in_array( $input['ai_provider'], array( 'ollama', 'openai' ), true )
			? $input['ai_provider'] : $defaults['ai_provider'];

		$out['ai_url']   = isset( $input['ai_url'] ) ? untrailingslashit( esc_url_raw( trim( $input['ai_url'] ), array( 'http', 'https' ) ) ) : '';
		$out['ai_model'] = isset( $input['ai_model'] ) ? sanitize_text_field( $input['ai_model'] ) : '';

		// Champ clé laissé vide = on garde la clé déjà enregistrée (elle n'est jamais réaffichée).
		$key = isset( $input['ai_key'] ) ? trim( sanitize_text_field( $input['ai_key'] ) ) : '';
		if ( '' === $key && empty( $input['ai_key_clear'] ) ) {
			$key = (string) self::get( 'ai_key' );
		}
		$out['ai_key'] = $key;

		self::flush();
		return $out;
	}

	/**
	 * Reprend les réglages de la version 1.x (options séparées).
	 */
	public static function migrate_legacy() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}
		$settings = self::defaults();
		$url      = get_option( 'isp_ollama_url' );
		$model    = get_option( 'isp_ollama_model' );
		if ( $url ) {
			$settings['ai_url'] = untrailingslashit( esc_url_raw( $url ) );
		}
		if ( $model ) {
			$settings['ai_model'] = sanitize_text_field( $model );
		}
		add_option( self::OPTION, $settings );
		foreach ( array( 'isp_enable_lazy', 'isp_ollama_url', 'isp_ollama_model', 'isp_enable_cache', 'isp_enable_minification', 'isp_delay_js', 'isp_enable_lqip' ) as $old ) {
			delete_option( $old );
		}
		self::flush();
	}
}
