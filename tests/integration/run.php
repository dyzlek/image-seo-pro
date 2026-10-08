<?php
/**
 * Tests d'intégration, exécutés dans un vrai WordPress :
 *
 *   wp eval-file tests/integration/run.php
 *
 * Variables d'environnement :
 *   ISP_ENGINE=gd|imagick   force le moteur de conversion
 *   ISP_TEST_UNINSTALL=1    teste aussi uninstall.php (à la fin, supprime les réglages)
 *
 * Code de sortie 1 si un test échoue.
 *
 * @package ImageSeoPro
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	echo "À lancer avec : wp eval-file tests/integration/run.php\n";
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

final class ISP_Test {
	public static $pass = 0;
	public static $fail = 0;
	public static $skip = 0;
	public static $cleanup = array();

	public static function check( $condition, $label, $detail = '' ) {
		if ( $condition ) {
			++self::$pass;
			echo "  \033[32m✓\033[0m $label\n";
		} else {
			++self::$fail;
			echo "  \033[31m✗ $label\033[0m" . ( '' !== $detail ? "\n      → $detail" : '' ) . "\n";
		}
	}

	public static function same( $expected, $actual, $label ) {
		self::check( $expected === $actual, $label, 'attendu ' . var_export( $expected, true ) . ', obtenu ' . var_export( $actual, true ) );
	}

	public static function skip( $label ) {
		++self::$skip;
		echo "  \033[33m- $label (ignoré)\033[0m\n";
	}

	public static function group( $name, $fn ) {
		echo "\n$name\n";
		try {
			$fn();
		} catch ( Throwable $e ) {
			++self::$fail;
			echo "  \033[31m✗ exception : " . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\033[0m\n";
		}
	}

	/**
	 * Change des réglages le temps d'un test.
	 */
	public static function settings( $values ) {
		update_option( ISP_Settings::OPTION, array_merge( ISP_Settings::all(), $values ) );
		ISP_Settings::flush();
	}

	/**
	 * Crée une image « photo » (dégradés + bruit) et l'importe dans la médiathèque.
	 */
	public static function upload( $type = 'jpeg', $width = 1600, $height = 1000, $name = 'velo-rouge' ) {
		$img = imagecreatetruecolor( $width, $height );
		if ( 'png' === $type ) {
			imagealphablending( $img, false );
			imagesavealpha( $img, true );
			imagefill( $img, 0, 0, imagecolorallocatealpha( $img, 0, 0, 0, 127 ) );
		}
		mt_srand( 42 );
		for ( $y = 0; $y < $height; $y += 4 ) {
			for ( $x = 0; $x < $width; $x += 4 ) {
				if ( 'png' === $type && $x < $width / 2 ) {
					continue; // Moitié gauche transparente.
				}
				$c = imagecolorallocate( $img, ( $x * 255 / $width + mt_rand( 0, 40 ) ) % 256, ( $y * 255 / $height + mt_rand( 0, 40 ) ) % 256, ( ( $x + $y ) % 256 ) );
				imagefilledrectangle( $img, $x, $y, $x + 3, $y + 3, $c );
			}
		}
		$tmp = wp_tempnam( $name );
		'png' === $type ? imagepng( $img, $tmp ) : imagejpeg( $img, $tmp, 92 );

		$id = media_handle_sideload(
			array(
				'name'     => $name . ( 'png' === $type ? '.png' : '.jpg' ),
				'tmp_name' => $tmp,
			),
			0
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'upload : ' . $id->get_error_message() );
		}
		self::$cleanup[] = $id;
		return $id;
	}

	public static function siblings( $id, $format ) {
		$set   = ISP_Optimizer::attachment_files( $id );
		$found = array();
		foreach ( $set['files'] as $name ) {
			$path = ISP_Optimizer::sibling_path( $set['dir'] . '/' . $name, $format );
			if ( file_exists( $path ) ) {
				$found[] = $path;
			}
		}
		return $found;
	}

	/**
	 * Appelle une action AJAX du plugin et renvoie [réussite, message d'erreur, données].
	 * (En ligne de commande les en-têtes sont déjà partis : on lit le JSON, pas le code HTTP.)
	 */
	public static function ajax( $action, $post ) {
		$_POST = $_REQUEST = $post;
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = function () {
			return function () {
				throw new RuntimeException( 'isp-die' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			( new ISP_Admin() )->{ 'ajax_' . $action }();
		} catch ( RuntimeException $e ) {
			if ( 'isp-die' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			$out = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_POST = $_REQUEST = array();
		}
		$json = json_decode( $out, true );
		return array(
			! empty( $json['success'] ),
			isset( $json['data']['message'] ) ? $json['data']['message'] : '',
			isset( $json['data'] ) ? $json['data'] : null,
		);
	}
}

$engine = getenv( 'ISP_ENGINE' );
if ( $engine ) {
	add_filter( 'isp_image_engine', function () use ( $engine ) {
		return $engine;
	} );
}

$saved_settings = get_option( ISP_Settings::OPTION );
$admin_id       = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
wp_set_current_user( $admin_id );

echo 'Image SEO Pro ' . ISP_VERSION . ' — WordPress ' . get_bloginfo( 'version' ) . ' — PHP ' . PHP_VERSION
	. ' — WebP : ' . ( ISP_Converter::engine_for( 'webp' ) ?: 'non' ) . ', AVIF : ' . ( ISP_Converter::engine_for( 'avif' ) ?: 'non' ) . "\n";

ISP_Test::settings( array( 'formats' => array( 'webp' ), 'quality' => 80, 'auto_optimize' => true, 'delivery' => 'picture', 'alt_fallback' => 'library' ) );

/* ---------------------------------------------------------------------- */

ISP_Test::group( 'Réglages', function () {
	$clean = ISP_Settings::sanitize( array( 'formats' => array( 'webp', 'gif', 'avif' ), 'quality' => 500, 'delivery' => 'nope', 'lazy_skip' => -3, 'ai_url' => 'javascript:alert(1)' ) );
	ISP_Test::same( array( 'webp', 'avif' ), $clean['formats'], 'formats inconnus retirés' );
	ISP_Test::same( 100, $clean['quality'], 'qualité bornée à 100' );
	ISP_Test::same( 'picture', $clean['delivery'], 'mode d\'affichage invalide → valeur par défaut' );
	ISP_Test::same( 0, $clean['lazy_skip'], 'chargement différé borné à 0' );
	ISP_Test::same( '', $clean['ai_url'], 'URL javascript: refusée' );
	ISP_Test::same( false, $clean['auto_optimize'], 'case décochée = false' );

	ISP_Test::settings( array( 'ai_key' => 'sk-secret' ) );
	$kept = ISP_Settings::sanitize( array( 'ai_key' => '' ) );
	ISP_Test::same( 'sk-secret', $kept['ai_key'], 'clé API conservée si le champ est laissé vide' );
	$cleared = ISP_Settings::sanitize( array( 'ai_key' => '', 'ai_key_clear' => '1' ) );
	ISP_Test::same( '', $cleared['ai_key'], 'clé API supprimée sur demande' );
	ISP_Test::settings( array( 'ai_key' => '' ) );
} );

ISP_Test::group( 'Migration depuis la version 1.x', function () use ( $saved_settings ) {
	delete_option( ISP_Settings::OPTION );
	update_option( 'isp_ollama_model', 'llava:7b' );
	update_option( 'isp_enable_lazy', 'on' );
	ISP_Settings::flush();
	ISP_Settings::migrate_legacy();
	ISP_Test::same( 'llava:7b', ISP_Settings::get( 'ai_model' ), 'modèle IA repris' );
	ISP_Test::same( false, get_option( 'isp_enable_lazy' ), 'anciennes options supprimées' );
	update_option( ISP_Settings::OPTION, $saved_settings ?: ISP_Settings::defaults() );
	ISP_Test::settings( array( 'formats' => array( 'webp' ), 'quality' => 80, 'auto_optimize' => true, 'delivery' => 'picture', 'alt_fallback' => 'library' ) );
} );

ISP_Test::group( 'Textes alternatifs depuis le nom de fichier', function () {
	ISP_Test::same( 'Velo rouge', ISP_Alt_Text::humanize( 'velo-rouge-1024x768.jpg' ), 'tirets et dimensions' );
	ISP_Test::same( 'Mon chat noir', ISP_Alt_Text::humanize( 'mon_chat_noir-scaled.jpeg' ), 'underscores et -scaled' );
	ISP_Test::same( 'Équipe 2026', ISP_Alt_Text::humanize( 'équipe-2026.png' ), 'accents et majuscule' );
	ISP_Test::same( '', ISP_Alt_Text::humanize( 'IMG_20240101_123456.jpg' ), 'nom d\'appareil photo ignoré' );
	ISP_Test::same( '', ISP_Alt_Text::humanize( "Capture d'écran 2026-04-08 001613" ), 'capture d\'écran ignorée' );
	ISP_Test::same( '', ISP_Alt_Text::humanize( '12345' ), 'que des chiffres → vide' );
} );

ISP_Test::group( 'Optimisation automatique à l\'upload (JPEG)', function () {
	$id   = ISP_Test::upload( 'jpeg' );
	$file = get_attached_file( $id );
	$md5  = md5_file( $file );
	$data = ISP_Optimizer::get_data( $id );

	ISP_Test::check( is_array( $data ), 'données d\'optimisation enregistrées' );
	$set = ISP_Optimizer::attachment_files( $id );
	ISP_Test::check( count( $set['files'] ) > 1, 'miniatures présentes (' . count( $set['files'] ) . ' fichiers)' );
	ISP_Test::same( count( $set['files'] ), count( ISP_Test::siblings( $id, 'webp' ) ), 'une copie WebP par fichier' );

	$main = ISP_Optimizer::sibling_path( $file, 'webp' );
	$info = @getimagesize( $main );
	ISP_Test::same( 'image/webp', $info ? $info['mime'] : null, 'la copie est un vrai WebP' );

	$all_smaller = true;
	foreach ( $data['files'] as $entry ) {
		if ( isset( $entry['webp'] ) && $entry['webp'] >= $entry['size'] ) {
			$all_smaller = false;
		}
	}
	ISP_Test::check( $all_smaller, 'chaque copie conservée est plus légère que l\'original' );
	$summary = ISP_Optimizer::summarize( $data );
	ISP_Test::check( $summary['percent'] > 0, 'gain total : −' . $summary['percent'] . ' %' );

	ISP_Test::same( $md5, md5_file( $file ), 'original intact' );
	ISP_Test::same( 'image/jpeg', get_post_mime_type( $id ), 'type du média inchangé' );
	ISP_Test::check( ! in_array( $id, ISP_Optimizer::pending_ids(), true ), 'n\'est plus dans la liste à optimiser' );
	ISP_Test::check( in_array( $id, ISP_Optimizer::pending_ids( true ), true ), 'reste dans la liste « tout refaire »' );
} );

ISP_Test::group( 'Upload sans optimisation automatique', function () {
	ISP_Test::settings( array( 'auto_optimize' => false ) );
	$id = ISP_Test::upload( 'jpeg', 800, 500, 'manuel' );
	ISP_Test::same( null, ISP_Optimizer::get_data( $id ), 'rien n\'est créé' );
	ISP_Test::check( in_array( $id, ISP_Optimizer::pending_ids(), true ), 'image listée à optimiser' );
	$data = ISP_Optimizer::optimize( $id );
	ISP_Test::check( is_array( $data ), 'optimisation manuelle OK' );
	ISP_Test::settings( array( 'auto_optimize' => true ) );
} );

ISP_Test::group( 'PNG transparent', function () {
	$id   = ISP_Test::upload( 'png', 800, 600, 'logo-transparent' );
	$webp = ISP_Optimizer::sibling_path( get_attached_file( $id ), 'webp' );
	ISP_Test::check( file_exists( $webp ), 'copie WebP créée' );
	if ( file_exists( $webp ) && function_exists( 'imagecreatefromwebp' ) ) {
		$img   = imagecreatefromwebp( $webp );
		$alpha = ( imagecolorat( $img, 10, 10 ) >> 24 ) & 0x7F;
		ISP_Test::same( 127, $alpha, 'transparence conservée (pas de fond blanc)' );
	}
} );

ISP_Test::group( 'AVIF', function () {
	if ( ! ISP_Converter::supports( 'avif' ) ) {
		ISP_Test::skip( 'AVIF non pris en charge par ce serveur' );
		return;
	}
	ISP_Test::settings( array( 'formats' => array( 'webp', 'avif' ) ) );
	$id   = ISP_Test::upload( 'jpeg', 1200, 800, 'montagne' );
	$avif = ISP_Optimizer::sibling_path( get_attached_file( $id ), 'avif' );
	ISP_Test::check( file_exists( $avif ), 'copie AVIF créée' );
	$data = ISP_Optimizer::get_data( $id );
	ISP_Test::same( array( 'webp', 'avif' ), $data['formats'], 'les deux formats enregistrés' );
	ISP_Test::settings( array( 'formats' => array( 'webp' ) ) );
} );

ISP_Test::group( 'Affichage : balise <picture>', function () {
	ISP_Test::settings( array( 'formats' => array( 'webp', 'avif' ) ) );
	$id = ISP_Test::upload( 'jpeg', 1600, 1000, 'plage' );
	ISP_Test::settings( array( 'formats' => array( 'webp' ) ) );
	update_post_meta( $id, '_wp_attachment_image_alt', 'Une plage au coucher du soleil' );
	$url  = wp_get_attachment_image_url( $id, 'large' );
	$html = '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="" class="wp-image-' . $id . '"/></figure>';

	$post = wp_insert_post( array( 'post_title' => 'Test ISP', 'post_content' => $html, 'post_status' => 'publish' ) );
	ISP_Test::$cleanup[] = $post;
	$GLOBALS['post'] = get_post( $post );
	setup_postdata( $GLOBALS['post'] );
	$out = apply_filters( 'the_content', $html );
	wp_reset_postdata();

	ISP_Test::check( false !== strpos( $out, '<picture' ), 'image entourée d\'un <picture>' );
	ISP_Test::check( false !== strpos( $out, 'type="image/webp"' ), 'source WebP' );
	ISP_Test::check( (bool) preg_match( '/srcset="[^"]*\.jpg\.webp/', $out ), 'srcset WebP (avec toutes les tailles)' );
	if ( ISP_Converter::supports( 'avif' ) ) {
		ISP_Test::check( strpos( $out, 'image/avif' ) < strpos( $out, 'image/webp' ), 'AVIF proposé avant WebP' );
	}
	ISP_Test::check( (bool) preg_match( '/<img[^>]+src="[^"]+\.jpg"/', $out ), '<img> de secours toujours en JPEG' );
	ISP_Test::check( false !== strpos( $out, 'alt="Une plage au coucher du soleil"' ), 'alt vide complété depuis la médiathèque' );
	ISP_Test::same( $out, ISP_Delivery::transform( $out, $id ), 'pas de double traitement' );

	// Image de thème qui repasse ensuite dans le filtre des contenus (thèmes blocs).
	$theme  = wp_get_attachment_image( $id, 'medium' );
	$double = wp_filter_content_tags( $theme );
	ISP_Test::same( 1, substr_count( $double, '<picture' ), 'image de thème : un seul <picture>' );

	ISP_Test::settings( array( 'delivery' => 'rewrite' ) );
	$rewritten = ISP_Delivery::transform( '<img src="' . esc_url( $url ) . '" class="wp-image-' . $id . '">', $id );
	ISP_Test::check( (bool) preg_match( '/src="[^"]+\.jpg\.webp"/', $rewritten ) && false === strpos( $rewritten, '<picture' ), 'mode « rewrite » : src pointe vers le WebP' );

	ISP_Test::settings( array( 'delivery' => 'off' ) );
	$off = apply_filters( 'the_content', $html );
	ISP_Test::check( false === strpos( $off, '<picture' ), 'mode « off » : rien ne change' );
	ISP_Test::settings( array( 'delivery' => 'picture' ) );

	ISP_Test::settings( array( 'alt_fallback' => 'off' ) );
	ISP_Test::check( false !== strpos( apply_filters( 'the_content', $html ), 'alt=""' ), 'alt non complété si désactivé' );
	ISP_Test::settings( array( 'alt_fallback' => 'library' ) );

	$plain = apply_filters( 'the_content', '<img src="https://example.com/autre.jpg" alt="x">' );
	ISP_Test::check( false === strpos( $plain, '<picture' ) && false !== strpos( $plain, 'alt="x"' ) && false === strpos( $plain, ISP_Delivery::MARK ), 'image externe non modifiée' );

	ISP_Test::settings( array( 'lazy_skip' => 1 ) );
	ISP_Test::same( 1, apply_filters( 'wp_omit_loading_attr_threshold', 3 ), 'nombre d\'images non différées réglable' );
	ISP_Test::settings( array( 'lazy_skip' => 3 ) );
} );

ISP_Test::group( 'Restauration et suppression', function () {
	$id = ISP_Test::upload( 'jpeg', 1000, 700, 'foret' );
	ISP_Test::check( count( ISP_Test::siblings( $id, 'webp' ) ) > 0, 'copies présentes' );
	ISP_Optimizer::restore( $id );
	ISP_Test::same( 0, count( ISP_Test::siblings( $id, 'webp' ) ), 'restauration : copies supprimées' );
	ISP_Test::same( null, ISP_Optimizer::get_data( $id ), 'restauration : données supprimées' );
	ISP_Test::check( file_exists( get_attached_file( $id ) ), 'restauration : original toujours là' );

	ISP_Optimizer::optimize( $id );
	$copies = ISP_Test::siblings( $id, 'webp' );
	wp_delete_attachment( $id, true );
	$left = array_filter( $copies, 'file_exists' );
	ISP_Test::same( 0, count( $left ), 'suppression du média : copies supprimées aussi' );
} );

ISP_Test::group( 'Cas refusés', function () {
	$post = wp_insert_post( array( 'post_title' => 'Pas une image', 'post_status' => 'draft' ) );
	ISP_Test::$cleanup[] = $post;
	ISP_Test::check( is_wp_error( ISP_Optimizer::optimize( $post ) ), 'un article n\'est pas optimisable' );

	ISP_Test::settings( array( 'formats' => array() ) );
	$id = ISP_Test::upload( 'jpeg', 400, 300, 'sans-format' );
	ISP_Test::same( null, ISP_Optimizer::get_data( $id ), 'aucun format activé : rien n\'est créé' );
	ISP_Test::check( is_wp_error( ISP_Optimizer::optimize( $id ) ), 'aucun format activé : erreur explicite' );
	ISP_Test::settings( array( 'formats' => array( 'webp' ) ) );
} );

ISP_Test::group( 'Client IA (réponses simulées)', function () {
	$id       = ISP_Test::upload( 'jpeg', 1600, 1000, 'chien' );
	$captured = array();
	$reply    = array();
	$mock     = function ( $pre, $args, $url ) use ( &$captured, &$reply ) {
		$captured = array( 'url' => $url, 'args' => $args );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $reply['body'] ),
			'response' => array( 'code' => $reply['code'], 'message' => '' ),
			'cookies'  => array(),
		);
	};
	add_filter( 'pre_http_request', $mock, 10, 3 );

	ISP_Test::settings( array( 'ai_provider' => 'ollama', 'ai_url' => 'http://ollama.test:11434', 'ai_model' => 'gemma3:4b', 'ai_key' => '' ) );
	$reply = array( 'code' => 200, 'body' => array( 'response' => "Texte alternatif : « Un chien qui court sur la plage. »\nAutre ligne" ) );
	$alt   = ISP_AI_Client::describe( $id );
	ISP_Test::same( 'Un chien qui court sur la plage', $alt, 'Ollama : réponse nettoyée' );
	ISP_Test::same( 'http://ollama.test:11434/api/generate', $captured['url'], 'Ollama : bonne adresse' );
	$sent = json_decode( $captured['args']['body'], true );
	ISP_Test::check( ! empty( $sent['images'][0] ) && false === $sent['stream'], 'Ollama : image envoyée en base64' );
	ISP_Test::check( strlen( base64_decode( $sent['images'][0] ) ) < filesize( get_attached_file( $id ) ), 'image réduite envoyée (pas l\'original)' );
	ISP_Test::check( false !== strpos( $sent['prompt'], 'French' ) || 'fr' !== substr( determine_locale(), 0, 2 ), 'consigne dans la langue du site' );

	ISP_Test::settings( array( 'ai_provider' => 'openai', 'ai_url' => 'https://api.example.test/v1', 'ai_model' => 'gpt-4o-mini', 'ai_key' => 'sk-test' ) );
	$reply = array( 'code' => 200, 'body' => array( 'choices' => array( array( 'message' => array( 'content' => '<think>hmm</think>"Chien noir sur le sable."' ) ) ) ) );
	ISP_Test::same( 'Chien noir sur le sable', ISP_AI_Client::describe( $id ), 'OpenAI : réponse nettoyée' );
	ISP_Test::same( 'https://api.example.test/v1/chat/completions', $captured['url'], 'OpenAI : bonne adresse' );
	ISP_Test::same( 'Bearer sk-test', $captured['args']['headers']['Authorization'], 'OpenAI : clé API envoyée' );
	$sent = json_decode( $captured['args']['body'], true );
	ISP_Test::check( 0 === strpos( $sent['messages'][0]['content'][1]['image_url']['url'], 'data:image/' ), 'OpenAI : image en data URL' );

	$reply = array( 'code' => 401, 'body' => array( 'error' => array( 'message' => 'Invalid API key' ) ) );
	$err   = ISP_AI_Client::describe( $id );
	ISP_Test::check( is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'Invalid API key' ), 'erreur HTTP remontée avec son message' );

	ISP_Test::settings( array( 'ai_provider' => 'ollama', 'ai_url' => 'http://ollama.test:11434', 'ai_model' => 'absent:1b', 'ai_key' => '' ) );
	$reply = array( 'code' => 200, 'body' => array( 'models' => array( array( 'name' => 'gemma3:4b' ) ) ) );
	$test  = ISP_AI_Client::test();
	ISP_Test::check( is_wp_error( $test ) && false !== strpos( $test->get_error_message(), 'gemma3:4b' ), 'test : modèle absent signalé, modèles disponibles listés' );
	ISP_Test::settings( array( 'ai_model' => 'gemma3' ) );
	$reply = array( 'code' => 200, 'body' => array( 'models' => array( array( 'name' => 'gemma3:latest' ) ) ) );
	ISP_Test::same( true, ISP_AI_Client::test(), 'test : « gemma3 » trouvé comme « gemma3:latest »' );

	$long = ISP_AI_Client::clean( str_repeat( 'mot ', 80 ) );
	ISP_Test::check( mb_strlen( $long ) <= ISP_AI_Client::MAX_LENGTH && ' ' !== substr( $long, -1 ), 'réponse trop longue coupée entre deux mots' );

	ISP_Test::settings( array( 'ai_url' => '' ) );
	ISP_Test::check( is_wp_error( ISP_AI_Client::describe( $id ) ), 'IA non configurée : erreur explicite' );

	remove_filter( 'pre_http_request', $mock, 10 );
	ISP_Test::settings( array( 'ai_provider' => 'ollama', 'ai_url' => 'http://host.docker.internal:11434', 'ai_model' => 'gemma3:4b', 'ai_key' => '' ) );
} );

ISP_Test::group( 'Actions AJAX : sécurité', function () use ( $admin_id ) {
	$id = ISP_Test::upload( 'jpeg', 600, 400, 'securite' );

	$sub = wp_insert_user( array( 'user_login' => 'isp_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	list( $ok, $msg ) = ISP_Test::ajax( 'optimize', array( 'id' => $id, 'nonce' => wp_create_nonce( ISP_Admin::NONCE ) ) );
	ISP_Test::check( ! $ok && __( 'You are not allowed to do this.', 'image-seo-pro' ) === $msg, 'abonné : refusé', $msg );
	wp_set_current_user( $admin_id );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub );

	list( $ok, $msg ) = ISP_Test::ajax( 'optimize', array( 'id' => $id, 'nonce' => 'mauvais' ) );
	ISP_Test::check( ! $ok && __( 'Your session has expired. Reload the page.', 'image-seo-pro' ) === $msg, 'jeton invalide : refusé', $msg );

	$post = wp_insert_post( array( 'post_title' => 'Article', 'post_status' => 'draft' ) );
	ISP_Test::$cleanup[] = $post;
	list( $ok, $msg ) = ISP_Test::ajax( 'save_alt', array( 'id' => $post, 'alt' => 'x', 'nonce' => wp_create_nonce( ISP_Admin::NONCE ) ) );
	ISP_Test::check( ! $ok && __( 'Image not found.', 'image-seo-pro' ) === $msg, 'ID qui n\'est pas une image : refusé', $msg );
	ISP_Test::same( '', get_post_meta( $post, '_wp_attachment_image_alt', true ), 'rien n\'est écrit sur l\'article' );

	list( $ok, , $data ) = ISP_Test::ajax( 'optimize', array( 'id' => $id, 'nonce' => wp_create_nonce( ISP_Admin::NONCE ) ) );
	ISP_Test::check( $ok && false !== strpos( $data['row'], 'isp-image-' . $id ), 'administrateur : optimisation OK, ligne renvoyée' );

	ISP_Test::ajax( 'save_alt', array( 'id' => $id, 'alt' => '<script>alert(1)</script>Un <b>chat</b>', 'nonce' => wp_create_nonce( ISP_Admin::NONCE ) ) );
	ISP_Test::same( 'Un chat', ISP_Alt_Text::get( $id ), 'texte alternatif nettoyé (pas de HTML)' );

	ISP_Test::ajax( 'suggest_alt', array( 'id' => $id, 'mode' => 'filename', 'save' => 1, 'nonce' => wp_create_nonce( ISP_Admin::NONCE ) ) );
	ISP_Test::same( 'Securite', ISP_Alt_Text::get( $id ), 'suggestion depuis le nom enregistrée' );

	list( , $msg ) = ISP_Test::ajax( 'test_ai', array( 'nonce' => wp_create_nonce( ISP_Admin::NONCE ) ) );
	ISP_Test::check( __( 'You are not allowed to do this.', 'image-seo-pro' ) !== $msg, 'test IA accessible à l\'administrateur' );
} );

ISP_Test::group( 'Statistiques', function () {
	$stats = ISP_Optimizer::stats();
	ISP_Test::check( $stats['optimized'] >= 5 && $stats['saved'] > 0 && $stats['percent'] > 0, 'gains cumulés : ' . size_format( $stats['saved'] ) . ' (−' . $stats['percent'] . ' %)' );
	$missing = ISP_Alt_Text::missing_ids();
	ISP_Test::check( in_array( ISP_Test::$cleanup[0], $missing, true ), 'image sans alt détectée (méta absente)' );
	update_post_meta( ISP_Test::$cleanup[0], '_wp_attachment_image_alt', '' );
	ISP_Test::check( in_array( ISP_Test::$cleanup[0], ISP_Alt_Text::missing_ids(), true ), 'image sans alt détectée (méta vide)' );
} );

/* ---------------------------------------------------------------------- */

foreach ( array_reverse( ISP_Test::$cleanup ) as $isp_post ) {
	'attachment' === get_post_type( $isp_post ) ? wp_delete_attachment( $isp_post, true ) : wp_delete_post( $isp_post, true );
}
update_option( ISP_Settings::OPTION, $saved_settings ?: ISP_Settings::defaults() );
ISP_Settings::flush();

if ( getenv( 'ISP_TEST_UNINSTALL' ) ) {
	ISP_Test::group( 'Désinstallation', function () {
		$id    = ISP_Test::upload( 'jpeg', 500, 400, 'desinstall' );
		$files = ISP_Test::siblings( $id, 'webp' );
		define( 'WP_UNINSTALL_PLUGIN', 'image-seo-pro/image-seo-pro.php' );
		include ISP_PATH . 'uninstall.php';
		ISP_Test::same( 0, count( array_filter( $files, 'file_exists' ) ), 'copies supprimées' );
		ISP_Test::same( false, get_option( ISP_Settings::OPTION ), 'réglages supprimés' );
		ISP_Test::check( file_exists( get_attached_file( $id ) ), 'originaux conservés' );
		wp_delete_attachment( $id, true );
	} );
}

printf( "\n%d réussi(s), %d échoué(s), %d ignoré(s)\n", ISP_Test::$pass, ISP_Test::$fail, ISP_Test::$skip );
if ( ISP_Test::$fail ) {
	exit( 1 );
}
