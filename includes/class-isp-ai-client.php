<?php
/**
 * Génère un texte alternatif avec un modèle de vision :
 * Ollama (local) ou n'importe quelle API compatible OpenAI (OpenAI, Mistral, LM Studio…).
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

class ISP_AI_Client {

	/**
	 * Largeur visée pour l'image envoyée : assez pour la décrire, sans envoyer des Mo.
	 */
	const TARGET_WIDTH = 1024;

	const MAX_LENGTH = 150;

	public static function is_configured() {
		return '' !== (string) ISP_Settings::get( 'ai_url' ) && '' !== (string) ISP_Settings::get( 'ai_model' );
	}

	/**
	 * Fichier à envoyer : la miniature la plus proche de 1024 px de large, sinon l'original.
	 *
	 * @param int $id ID du média.
	 * @return array{path: string, mime: string}|null
	 */
	public static function image_for( $id ) {
		$file = get_attached_file( $id, true );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		$best     = array(
			'path' => $file,
			'mime' => get_post_mime_type( $id ),
		);
		$meta     = wp_get_attachment_metadata( $id );
		$distance = isset( $meta['width'] ) ? abs( (int) $meta['width'] - self::TARGET_WIDTH ) : PHP_INT_MAX;
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				$path = dirname( $file ) . '/' . $size['file'];
				$gap  = abs( (int) $size['width'] - self::TARGET_WIDTH );
				if ( $gap < $distance && (int) $size['width'] >= 512 && file_exists( $path ) ) {
					$distance = $gap;
					$best     = array(
						'path' => $path,
						'mime' => isset( $size['mime-type'] ) ? $size['mime-type'] : $best['mime'],
					);
				}
			}
		}
		return $best;
	}

	/**
	 * Nom de la langue du site, en anglais (les modèles suivent mieux une consigne en anglais).
	 */
	public static function language() {
		$names  = array(
			'fr' => 'French',
			'en' => 'English',
			'es' => 'Spanish',
			'de' => 'German',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'pl' => 'Polish',
		);
		$locale = determine_locale();
		$lang   = strtolower( substr( $locale, 0, 2 ) );
		return isset( $names[ $lang ] ) ? $names[ $lang ] : 'the language of the locale ' . $locale;
	}

	public static function prompt( $id ) {
		$prompt = 'Write the alt text of this image, for accessibility and SEO. '
			. 'Language: ' . self::language() . '. '
			. 'One short sentence, at most 125 characters, no final period. '
			. 'Describe what is visible and useful to someone who cannot see it. '
			. 'Do not start with "Image of", "Photo of" or an equivalent. '
			. 'Reply with the alt text only.';

		$parent = wp_get_post_parent_id( $id );
		if ( $parent && ISP_Settings::get( 'ai_context' ) ) {
			$title = wp_strip_all_tags( get_the_title( $parent ) );
			if ( '' !== $title ) {
				$prompt .= ' Context: the image illustrates a page titled "' . $title . '".';
			}
		}
		return (string) apply_filters( 'isp_ai_prompt', $prompt, $id );
	}

	/**
	 * Demande un texte alternatif pour un média.
	 *
	 * @param int $id ID du média.
	 * @return string|WP_Error
	 */
	public static function describe( $id ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'isp_ai_config', __( 'Set the AI address and model in the settings first.', 'image-seo-pro' ) );
		}
		$image = self::image_for( $id );
		if ( ! $image ) {
			return new WP_Error( 'isp_no_file', __( 'The image file is missing.', 'image-seo-pro' ) );
		}
		$base64 = base64_encode( (string) file_get_contents( $image['path'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions
		$prompt = self::prompt( $id );
		$url    = (string) ISP_Settings::get( 'ai_url' );
		$model  = (string) ISP_Settings::get( 'ai_model' );

		if ( 'openai' === ISP_Settings::get( 'ai_provider' ) ) {
			$endpoint = $url . '/chat/completions';
			$payload  = array(
				'model'       => $model,
				'temperature' => 0.2,
				'max_tokens'  => 120,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $prompt,
							),
							array(
								'type'      => 'image_url',
								'image_url' => array( 'url' => 'data:' . $image['mime'] . ';base64,' . $base64 ),
							),
						),
					),
				),
			);
		} else {
			$endpoint = $url . '/api/generate';
			$payload  = array(
				'model'   => $model,
				'prompt'  => $prompt,
				'images'  => array( $base64 ),
				'stream'  => false,
				'options' => array( 'temperature' => 0.2 ),
			);
		}

		$body = self::request( 'POST', $endpoint, $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$text = 'openai' === ISP_Settings::get( 'ai_provider' )
			? ( isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '' )
			: ( isset( $body['response'] ) ? $body['response'] : '' );

		$alt = self::clean( (string) $text );
		if ( '' === $alt ) {
			return new WP_Error( 'isp_ai_empty', __( 'The AI returned an empty answer.', 'image-seo-pro' ) );
		}
		return $alt;
	}

	/**
	 * Vérifie l'accès à l'API et la présence du modèle.
	 *
	 * @return true|WP_Error
	 */
	public static function test() {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'isp_ai_config', __( 'Set the AI address and model in the settings first.', 'image-seo-pro' ) );
		}
		$url   = (string) ISP_Settings::get( 'ai_url' );
		$model = (string) ISP_Settings::get( 'ai_model' );

		if ( 'openai' === ISP_Settings::get( 'ai_provider' ) ) {
			$body  = self::request( 'GET', $url . '/models' );
			$names = is_wp_error( $body ) ? array() : wp_list_pluck( isset( $body['data'] ) ? $body['data'] : array(), 'id' );
		} else {
			$body  = self::request( 'GET', $url . '/api/tags' );
			$names = is_wp_error( $body ) ? array() : wp_list_pluck( isset( $body['models'] ) ? $body['models'] : array(), 'name' );
		}
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		// Ollama ajoute « :latest » quand aucune étiquette n'est donnée.
		if ( $names && ! in_array( $model, $names, true ) && ! in_array( $model . ':latest', $names, true ) ) {
			return new WP_Error(
				'isp_ai_model',
				/* translators: 1: model name, 2: list of available models. */
				sprintf( __( 'Connected, but the model "%1$s" is not available. Available: %2$s', 'image-seo-pro' ), $model, implode( ', ', array_slice( $names, 0, 15 ) ) )
			);
		}
		return true;
	}

	/**
	 * Appel HTTP JSON.
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $url     Adresse complète.
	 * @param array|null $payload Corps JSON.
	 * @return array|WP_Error Réponse décodée.
	 */
	private static function request( $method, $url, $payload = null ) {
		$headers = array( 'Content-Type' => 'application/json' );
		$key     = (string) ISP_Settings::get( 'ai_key' );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			// Le premier appel à Ollama charge le modèle en mémoire : ça peut être long.
			'timeout' => (int) apply_filters( 'isp_ai_timeout', 'POST' === $method ? 120 : 10 ),
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: technical error message. */
			return new WP_Error( 'isp_ai_http', sprintf( __( 'The AI service cannot be reached: %s', 'image-seo-pro' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = '';
			if ( isset( $body['error']['message'] ) ) {
				$detail = $body['error']['message'];
			} elseif ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
				$detail = $body['error'];
			}
			/* translators: 1: HTTP status code, 2: error detail. */
			return new WP_Error( 'isp_ai_status', trim( sprintf( __( 'The AI service answered with an error (HTTP %1$d). %2$s', 'image-seo-pro' ), $code, $detail ) ) );
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'isp_ai_json', __( 'The AI service returned an unreadable answer.', 'image-seo-pro' ) );
		}
		return $body;
	}

	/**
	 * Nettoie la réponse : raisonnement, guillemets, préfixes du type « Texte alternatif : ».
	 *
	 * @param string $text Réponse brute.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = preg_replace( '/<think>.*?<\/think>/is', '', $text );
		$text = trim( wp_strip_all_tags( $text ) );
		$text = preg_split( '/\R/u', $text );
		$text = trim( (string) reset( $text ) );
		$text = preg_replace( '/^\**\s*(alt[\s-]*text|texte alternatif|balise alt|alt|description|légende)\s*\**\s*[:：-]\s*/iu', '', $text );
		$text = trim( $text, " \t\"'«»“”‘’*`" );
		$text = rtrim( $text, '.' );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > self::MAX_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_LENGTH );
			$cut  = mb_strrpos( $text, ' ' );
			$text = false !== $cut ? mb_substr( $text, 0, $cut ) : $text;
		}
		return trim( $text );
	}
}
