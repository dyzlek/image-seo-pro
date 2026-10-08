<?php
/**
 * Administration : page « Médias → Image SEO Pro », réglages, actions AJAX
 * et colonne dans la médiathèque.
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

class ISP_Admin {

	const SLUG     = 'image-seo-pro';
	const NONCE    = 'isp_admin';
	const PER_PAGE = 20;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ISP_FILE ), array( $this, 'action_links' ) );

		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );

		foreach ( array( 'optimize', 'restore', 'pending', 'save_alt', 'suggest_alt', 'missing_alt', 'test_ai' ) as $action ) {
			add_action( 'wp_ajax_isp_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Menu, réglages, assets                                             */
	/* ------------------------------------------------------------------ */

	public function menu() {
		add_media_page(
			__( 'Image SEO Pro', 'image-seo-pro' ),
			__( 'Image SEO Pro', 'image-seo-pro' ),
			'upload_files',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'image-seo-pro' ) . '</a>'
		);
		return $links;
	}

	public static function url( $tab = 'overview', $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'upload.php' )
		);
	}

	public function assets( $hook ) {
		if ( 'media_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'isp-admin', ISP_URL . 'assets/admin.css', array(), ISP_VERSION );
		wp_enqueue_script( 'isp-admin', ISP_URL . 'assets/admin.js', array(), ISP_VERSION, true );
		wp_localize_script(
			'isp-admin',
			'ISP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'working'       => __( 'Working…', 'image-seo-pro' ),
					'saved'         => __( 'Saved', 'image-seo-pro' ),
					'error'         => __( 'Error', 'image-seo-pro' ),
					'requestFailed' => __( 'The request failed. Check your connection and try again.', 'image-seo-pro' ),
					'nothingToDo'   => __( 'Nothing to do: everything is already done.', 'image-seo-pro' ),
					/* translators: 1: number processed, 2: total. */
					'progress'      => __( '%1$d / %2$d', 'image-seo-pro' ),
					/* translators: 1: number of successes, 2: number of errors. */
					'done'          => __( 'Done: %1$d succeeded, %2$d failed.', 'image-seo-pro' ),
					'confirmAi'     => __( 'Generate and save an alt text with the AI for every image without one? You can edit them afterwards.', 'image-seo-pro' ),
					'confirmName'   => __( 'Fill every missing alt text from the image title or file name? You can edit them afterwards.', 'image-seo-pro' ),
					'confirmRestore' => __( 'Delete the WebP / AVIF copies of this image? The original is not touched.', 'image-seo-pro' ),
					'stop'          => __( 'Stop', 'image-seo-pro' ),
					'stopped'       => __( 'Stopped.', 'image-seo-pro' ),
					'connectionOk'  => __( 'Connection OK, the model is available.', 'image-seo-pro' ),
					'noSuggestion'  => __( 'No useful suggestion for this file name.', 'image-seo-pro' ),
				),
			)
		);
	}

	public function register_settings() {
		register_setting(
			'isp_settings',
			ISP_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'ISP_Settings', 'sanitize' ),
				'default'           => ISP_Settings::defaults(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                               */
	/* ------------------------------------------------------------------ */

	private function tabs() {
		$tabs = array(
			'overview' => __( 'Overview', 'image-seo-pro' ),
			'images'   => __( 'Images', 'image-seo-pro' ),
			'alt'      => __( 'Alt texts', 'image-seo-pro' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['settings'] = __( 'Settings', 'image-seo-pro' );
		}
		return $tabs;
	}

	public function render_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'image-seo-pro' ) );
		}
		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		?>
		<div class="wrap isp-wrap">
			<h1><?php esc_html_e( 'Image SEO Pro', 'image-seo-pro' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Sections', 'image-seo-pro' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="isp-tab isp-tab-<?php echo esc_attr( $tab ); ?>">
				<?php $this->{ 'render_' . $tab }(); ?>
			</div>
		</div>
		<?php
	}

	private function render_overview() {
		$stats   = ISP_Optimizer::stats();
		$missing = ISP_Alt_Text::count_missing();
		$formats = ISP_Settings::active_formats();
		?>
		<div class="isp-cards">
			<div class="isp-card">
				<span class="isp-card-value"><?php echo esc_html( number_format_i18n( $stats['images'] ) ); ?></span>
				<span class="isp-card-label"><?php esc_html_e( 'Images in the library', 'image-seo-pro' ); ?></span>
			</div>
			<div class="isp-card">
				<span class="isp-card-value"><?php echo esc_html( number_format_i18n( $stats['optimized'] ) ); ?></span>
				<span class="isp-card-label"><?php esc_html_e( 'Optimized images', 'image-seo-pro' ); ?></span>
			</div>
			<div class="isp-card is-good">
				<span class="isp-card-value"><?php echo esc_html( $stats['saved'] > 0 ? size_format( $stats['saved'], 1 ) : '—' ); ?></span>
				<span class="isp-card-label">
					<?php
					/* translators: %d: percentage saved. */
					echo esc_html( sprintf( __( 'Saved (−%d %%)', 'image-seo-pro' ), $stats['percent'] ) );
					?>
				</span>
			</div>
			<a class="isp-card <?php echo $missing ? 'is-warning' : 'is-good'; ?>" href="<?php echo esc_url( self::url( 'alt', array( 'filter' => 'missing' ) ) ); ?>">
				<span class="isp-card-value"><?php echo esc_html( number_format_i18n( $missing ) ); ?></span>
				<span class="isp-card-label"><?php esc_html_e( 'Images without alt text', 'image-seo-pro' ); ?></span>
			</a>
		</div>

		<div class="isp-panel">
			<h2><?php esc_html_e( 'Optimize the whole library', 'image-seo-pro' ); ?></h2>
			<?php if ( array() === $formats ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'No output format is enabled or supported by this server. Check the settings.', 'image-seo-pro' ); ?></p></div>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of images, 2: formats (WEBP, AVIF). */
							_n( '%1$s image to optimize. A %2$s copy is created next to each file: the originals are never modified.', '%1$s images to optimize. A %2$s copy is created next to each file: the originals are never modified.', $stats['pending'], 'image-seo-pro' ),
							number_format_i18n( $stats['pending'] ),
							implode( ' + ', array_map( array( 'ISP_Converter', 'label' ), $formats ) )
						)
					);
					?>
				</p>
				<p>
					<label><input type="checkbox" id="isp-force"> <?php esc_html_e( 'Also redo the images already optimized (after changing the quality or the formats)', 'image-seo-pro' ); ?></label>
				</p>
				<div class="isp-bulk" data-isp-bulk="optimize">
					<button type="button" class="button button-primary isp-bulk-start"><?php esc_html_e( 'Optimize all', 'image-seo-pro' ); ?></button>
					<?php $this->render_progress(); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="isp-panel">
			<h2><?php esc_html_e( 'Server', 'image-seo-pro' ); ?></h2>
			<ul class="isp-checklist">
				<?php foreach ( ISP_Converter::FORMATS as $format ) : $engine = ISP_Converter::engine_for( $format ); ?>
					<li class="<?php echo $engine ? 'is-ok' : 'is-ko'; ?>">
						<span class="dashicons <?php echo $engine ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>" aria-hidden="true"></span>
						<?php
						echo esc_html(
							$engine
								/* translators: 1: format, 2: engine (Imagick, GD). */
								? sprintf( __( '%1$s supported (%2$s)', 'image-seo-pro' ), ISP_Converter::label( $format ), 'imagick' === $engine ? 'Imagick' : 'GD' )
								/* translators: %s: format. */
								: sprintf( __( '%s not supported by this server', 'image-seo-pro' ), ISP_Converter::label( $format ) )
						);
						?>
					</li>
				<?php endforeach; ?>
				<li class="<?php echo ISP_AI_Client::is_configured() ? 'is-ok' : 'is-ko'; ?>">
					<span class="dashicons <?php echo ISP_AI_Client::is_configured() ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
					<?php
					echo esc_html(
						ISP_AI_Client::is_configured()
							/* translators: %s: AI model name. */
							? sprintf( __( 'AI alt texts: model %s', 'image-seo-pro' ), ISP_Settings::get( 'ai_model' ) )
							: __( 'AI alt texts: not configured', 'image-seo-pro' )
					);
					?>
				</li>
			</ul>
		</div>
		<?php
	}

	private function render_progress() {
		?>
		<div class="isp-progress" hidden>
			<div class="isp-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
			<p class="isp-progress-text" aria-live="polite"></p>
			<button type="button" class="button-link isp-bulk-stop"><?php esc_html_e( 'Stop', 'image-seo-pro' ); ?></button>
			<ul class="isp-progress-log"></ul>
		</div>
		<?php
	}

	private function current_page() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private function current_search() {
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private function render_search( $tab, $extra = array() ) {
		?>
		<form method="get" class="isp-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php foreach ( $extra as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<label class="screen-reader-text" for="isp-search"><?php esc_html_e( 'Search images', 'image-seo-pro' ); ?></label>
			<input type="search" id="isp-search" name="s" value="<?php echo esc_attr( $this->current_search() ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search images', 'image-seo-pro' ); ?></button>
		</form>
		<?php
	}

	private function render_pagination( WP_Query $query ) {
		if ( $query->max_num_pages < 2 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $this->current_page(),
				'total'     => $query->max_num_pages,
				'prev_text' => '‹',
				'next_text' => '›',
			)
		);
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
	}

	private function render_images() {
		$query = ISP_Alt_Text::query(
			array(
				'page'     => $this->current_page(),
				'per_page' => self::PER_PAGE,
				'search'   => $this->current_search(),
			)
		);
		?>
		<div class="isp-toolbar">
			<p class="description"><?php esc_html_e( 'Sizes are those of the full-size image; the gain also counts every thumbnail.', 'image-seo-pro' ); ?></p>
			<?php $this->render_search( 'images' ); ?>
		</div>
		<table class="widefat striped isp-table">
			<thead>
				<tr>
					<th scope="col" class="column-thumb"><span class="screen-reader-text"><?php esc_html_e( 'Preview', 'image-seo-pro' ); ?></span></th>
					<th scope="col"><?php esc_html_e( 'File', 'image-seo-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Original', 'image-seo-pro' ); ?></th>
					<th scope="col">WebP</th>
					<th scope="col">AVIF</th>
					<th scope="col"><?php esc_html_e( 'Gain', 'image-seo-pro' ); ?></th>
					<th scope="col" class="column-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'image-seo-pro' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( ! $query->posts ) {
					echo '<tr><td colspan="7">' . esc_html__( 'No images found.', 'image-seo-pro' ) . '</td></tr>';
				}
				foreach ( $query->posts as $post ) {
					echo self::image_row( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans image_row().
				}
				?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $query );
	}

	/**
	 * Ligne du tableau « Images » (aussi renvoyée après une action AJAX).
	 */
	public static function image_row( $id ) {
		$file     = get_attached_file( $id, true );
		$name     = $file ? wp_basename( $file ) : '';
		$size     = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
		$meta     = wp_get_attachment_metadata( $id );
		$data     = ISP_Optimizer::get_data( $id );
		$exists   = $file && file_exists( $file );
		$can      = $exists && ISP_Optimizer::is_optimizable( $id );
		$main     = $data && isset( $data['files'][ $name ] ) ? $data['files'][ $name ] : array();
		$summary  = $data ? ISP_Optimizer::summarize( $data ) : null;

		ob_start();
		?>
		<tr id="isp-image-<?php echo (int) $id; ?>" data-id="<?php echo (int) $id; ?>">
			<td class="column-thumb"><?php echo wp_get_attachment_image( $id, array( 60, 60 ), false, array( 'loading' => 'lazy' ) ); ?></td>
			<td>
				<strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $name ); ?></a></strong>
				<span class="isp-muted">
					<?php
					echo esc_html( strtoupper( wp_basename( (string) get_post_mime_type( $id ) ) ) );
					if ( ! empty( $meta['width'] ) ) {
						echo ' · ' . esc_html( $meta['width'] . '×' . $meta['height'] );
					}
					?>
				</span>
			</td>
			<td data-label="<?php esc_attr_e( 'Original', 'image-seo-pro' ); ?>"><?php echo esc_html( $size ? size_format( $size, 1 ) : '—' ); ?></td>
			<?php foreach ( ISP_Converter::FORMATS as $format ) : ?>
				<td data-label="<?php echo esc_attr( ISP_Converter::label( $format ) ); ?>">
					<?php
					if ( ! empty( $main[ $format ] ) ) {
						$percent = $size ? (int) round( 100 * ( $size - $main[ $format ] ) / $size ) : 0;
						echo esc_html( size_format( $main[ $format ], 1 ) ) . ' <span class="isp-badge is-good">−' . (int) $percent . ' %</span>';
					} else {
						echo '<span class="isp-muted">—</span>';
					}
					?>
				</td>
			<?php endforeach; ?>
			<td data-label="<?php esc_attr_e( 'Gain', 'image-seo-pro' ); ?>">
				<?php
				if ( $summary && $summary['saved'] > 0 ) {
					echo '<strong>' . esc_html( size_format( $summary['saved'], 1 ) ) . '</strong> <span class="isp-muted">(−' . (int) $summary['percent'] . ' %)</span>';
				} elseif ( $data ) {
					echo '<span class="isp-muted">' . esc_html__( 'Already optimal', 'image-seo-pro' ) . '</span>';
				} elseif ( ! $exists ) {
					echo '<span class="isp-badge is-bad">' . esc_html__( 'File missing', 'image-seo-pro' ) . '</span>';
				} elseif ( $can ) {
					echo '<span class="isp-badge">' . esc_html__( 'Not optimized', 'image-seo-pro' ) . '</span>';
				} else {
					echo '<span class="isp-muted">' . esc_html__( 'Not applicable', 'image-seo-pro' ) . '</span>';
				}
				?>
			</td>
			<td class="column-actions">
				<?php if ( $can ) : ?>
					<button type="button" class="button button-small <?php echo $data ? '' : 'button-primary'; ?> isp-optimize"><?php echo $data ? esc_html__( 'Redo', 'image-seo-pro' ) : esc_html__( 'Optimize', 'image-seo-pro' ); ?></button>
				<?php endif; ?>
				<?php if ( $data ) : ?>
					<button type="button" class="button button-small isp-restore"><?php esc_html_e( 'Restore', 'image-seo-pro' ); ?></button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	private function render_alt() {
		$missing_only = ! isset( $_GET['filter'] ) || 'all' !== $_GET['filter']; // phpcs:ignore WordPress.Security.NonceVerification
		$missing      = ISP_Alt_Text::count_missing();
		$query        = ISP_Alt_Text::query(
			array(
				'page'     => $this->current_page(),
				'per_page' => self::PER_PAGE,
				'missing'  => $missing_only,
				'search'   => $this->current_search(),
			)
		);
		$ai           = ISP_AI_Client::is_configured();
		?>
		<div class="isp-toolbar">
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( self::url( 'alt', array( 'filter' => 'missing' ) ) ); ?>" class="<?php echo $missing_only ? 'current' : ''; ?>"><?php esc_html_e( 'Without alt text', 'image-seo-pro' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $missing ) ); ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( self::url( 'alt', array( 'filter' => 'all' ) ) ); ?>" class="<?php echo $missing_only ? '' : 'current'; ?>"><?php esc_html_e( 'All images', 'image-seo-pro' ); ?></a></li>
			</ul>
			<?php $this->render_search( 'alt', array( 'filter' => $missing_only ? 'missing' : 'all' ) ); ?>
		</div>

		<?php if ( $missing ) : ?>
			<div class="isp-panel isp-bulk" data-isp-bulk="alt">
				<p>
					<strong><?php esc_html_e( 'Fill every missing alt text:', 'image-seo-pro' ); ?></strong>
					<?php if ( $ai ) : ?>
						<button type="button" class="button button-primary isp-bulk-start" data-mode="ai"><?php esc_html_e( 'With the AI', 'image-seo-pro' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button isp-bulk-start" data-mode="filename"><?php esc_html_e( 'From the title / file name', 'image-seo-pro' ); ?></button>
					<?php if ( ! $ai && current_user_can( 'manage_options' ) ) : ?>
						<a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'Set up the AI', 'image-seo-pro' ); ?></a>
					<?php endif; ?>
				</p>
				<?php $this->render_progress(); ?>
			</div>
		<?php endif; ?>

		<table class="widefat striped isp-table isp-alt-table">
			<thead>
				<tr>
					<th scope="col" class="column-thumb"><span class="screen-reader-text"><?php esc_html_e( 'Preview', 'image-seo-pro' ); ?></span></th>
					<th scope="col"><?php esc_html_e( 'File', 'image-seo-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Alt text', 'image-seo-pro' ); ?></th>
					<th scope="col" class="column-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'image-seo-pro' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $query->posts ) : ?>
					<tr><td colspan="4"><?php echo $missing_only ? esc_html__( 'Every image has an alt text.', 'image-seo-pro' ) : esc_html__( 'No images found.', 'image-seo-pro' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $query->posts as $post ) :
					$id     = $post->ID;
					$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
					$alt    = ISP_Alt_Text::get( $id );
					?>
					<tr data-id="<?php echo (int) $id; ?>">
						<td class="column-thumb"><?php echo wp_get_attachment_image( $id, array( 60, 60 ), false, array( 'loading' => 'lazy' ) ); ?></td>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( wp_basename( (string) get_attached_file( $id, true ) ) ); ?></a></strong>
							<?php if ( $parent ) : ?>
								<span class="isp-muted">
									<?php
									/* translators: %s: title of the post the image is attached to. */
									printf( esc_html__( 'In: %s', 'image-seo-pro' ), '<a href="' . esc_url( get_permalink( $parent ) ) . '">' . esc_html( get_the_title( $parent ) ) . '</a>' );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td class="column-alt">
							<label class="screen-reader-text" for="isp-alt-<?php echo (int) $id; ?>"><?php esc_html_e( 'Alt text', 'image-seo-pro' ); ?></label>
							<input type="text" id="isp-alt-<?php echo (int) $id; ?>" class="large-text isp-alt-input" value="<?php echo esc_attr( $alt ); ?>" maxlength="250" data-saved="<?php echo esc_attr( $alt ); ?>">
							<span class="isp-alt-meta"><span class="isp-alt-count"><?php echo (int) ( function_exists( 'mb_strlen' ) ? mb_strlen( $alt ) : strlen( $alt ) ); ?></span>/125 <span class="isp-alt-status" aria-live="polite"></span></span>
						</td>
						<td class="column-actions">
							<button type="button" class="button button-small button-primary isp-alt-save"><?php esc_html_e( 'Save', 'image-seo-pro' ); ?></button>
							<?php if ( $ai ) : ?>
								<button type="button" class="button button-small isp-alt-suggest" data-mode="ai"><?php esc_html_e( 'AI suggestion', 'image-seo-pro' ); ?></button>
							<?php endif; ?>
							<button type="button" class="button button-small isp-alt-suggest" data-mode="filename" title="<?php esc_attr_e( 'Suggest from the title or file name', 'image-seo-pro' ); ?>"><?php esc_html_e( 'From name', 'image-seo-pro' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $query );
	}

	private function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = ISP_Settings::all();
		$name = ISP_Settings::OPTION;
		?>
		<form method="post" action="options.php" class="isp-settings">
			<?php settings_fields( 'isp_settings' ); ?>

			<h2><?php esc_html_e( 'Optimization', 'image-seo-pro' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Formats', 'image-seo-pro' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Formats', 'image-seo-pro' ); ?></legend>
							<?php foreach ( ISP_Converter::FORMATS as $format ) : $ok = ISP_Converter::supports( $format ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[formats][]" value="<?php echo esc_attr( $format ); ?>" <?php checked( in_array( $format, (array) $s['formats'], true ) ); ?> <?php disabled( ! $ok ); ?>>
									<?php echo esc_html( ISP_Converter::label( $format ) ); ?>
									<?php if ( ! $ok ) : ?>
										<span class="isp-muted">(<?php esc_html_e( 'not supported by this server', 'image-seo-pro' ); ?>)</span>
									<?php endif; ?>
								</label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'WebP works in every current browser. AVIF is even lighter but slower to create.', 'image-seo-pro' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="isp-quality"><?php esc_html_e( 'Quality', 'image-seo-pro' ); ?></label></th>
					<td>
						<input type="number" id="isp-quality" name="<?php echo esc_attr( $name ); ?>[quality]" value="<?php echo (int) $s['quality']; ?>" min="30" max="100" step="1" class="small-text"> %
						<p class="description"><?php esc_html_e( '75–85 is a good balance. Copies that end up heavier than the original are not kept.', 'image-seo-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'New uploads', 'image-seo-pro' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_optimize]" value="1" <?php checked( $s['auto_optimize'] ); ?>> <?php esc_html_e( 'Optimize images automatically when they are uploaded', 'image-seo-pro' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'On the site', 'image-seo-pro' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Serve the copies', 'image-seo-pro' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Serve the copies', 'image-seo-pro' ); ?></legend>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[delivery]" value="picture" <?php checked( $s['delivery'], 'picture' ); ?>> <?php esc_html_e( '<picture> tag (recommended): each browser takes the best format it supports', 'image-seo-pro' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[delivery]" value="rewrite" <?php checked( $s['delivery'], 'rewrite' ); ?>> <?php esc_html_e( 'Replace the image address by the WebP (if your theme does not handle <picture>)', 'image-seo-pro' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[delivery]" value="off" <?php checked( $s['delivery'], 'off' ); ?>> <?php esc_html_e( 'Off (only create the files)', 'image-seo-pro' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="isp-lazy"><?php esc_html_e( 'Lazy loading', 'image-seo-pro' ); ?></label></th>
					<td>
						<input type="number" id="isp-lazy" name="<?php echo esc_attr( $name ); ?>[lazy_skip]" value="<?php echo (int) $s['lazy_skip']; ?>" min="0" max="20" class="small-text">
						<p class="description"><?php esc_html_e( 'Number of images at the top of the page loaded right away. The others wait until they are about to be seen. WordPress default: 3.', 'image-seo-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Empty alt in content', 'image-seo-pro' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Empty alt in content', 'image-seo-pro' ); ?></legend>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[alt_fallback]" value="library" <?php checked( $s['alt_fallback'], 'library' ); ?>> <?php esc_html_e( 'Use the alt text from the media library (recommended)', 'image-seo-pro' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[alt_fallback]" value="title" <?php checked( $s['alt_fallback'], 'title' ); ?>> <?php esc_html_e( 'Same, and the image title when there is none', 'image-seo-pro' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[alt_fallback]" value="off" <?php checked( $s['alt_fallback'], 'off' ); ?>> <?php esc_html_e( 'Do nothing', 'image-seo-pro' ); ?></label>
							<p class="description"><?php esc_html_e( 'An alt text added in the library afterwards does not reach posts where the image was already inserted. This fixes it when the page is displayed, without changing your posts.', 'image-seo-pro' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'AI for alt texts', 'image-seo-pro' ); ?></h2>
			<p><?php esc_html_e( 'A vision model describes the image. Ollama runs on your machine for free; any OpenAI-compatible API (OpenAI, Mistral, LM Studio…) also works.', 'image-seo-pro' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="isp-ai-provider"><?php esc_html_e( 'Service', 'image-seo-pro' ); ?></label></th>
					<td>
						<select id="isp-ai-provider" name="<?php echo esc_attr( $name ); ?>[ai_provider]">
							<option value="ollama" <?php selected( $s['ai_provider'], 'ollama' ); ?>>Ollama</option>
							<option value="openai" <?php selected( $s['ai_provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI-compatible API', 'image-seo-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="isp-ai-url"><?php esc_html_e( 'Address', 'image-seo-pro' ); ?></label></th>
					<td>
						<input type="url" id="isp-ai-url" name="<?php echo esc_attr( $name ); ?>[ai_url]" value="<?php echo esc_attr( $s['ai_url'] ); ?>" class="regular-text" placeholder="http://localhost:11434">
						<p class="description"><?php echo wp_kses( __( 'Ollama: <code>http://localhost:11434</code> (from Docker: <code>http://host.docker.internal:11434</code>). OpenAI: <code>https://api.openai.com/v1</code>.', 'image-seo-pro' ), array( 'code' => array() ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="isp-ai-model"><?php esc_html_e( 'Model', 'image-seo-pro' ); ?></label></th>
					<td>
						<input type="text" id="isp-ai-model" name="<?php echo esc_attr( $name ); ?>[ai_model]" value="<?php echo esc_attr( $s['ai_model'] ); ?>" class="regular-text" placeholder="gemma3:4b">
						<p class="description"><?php echo wp_kses( __( 'It must understand images. Examples: <code>gemma3:4b</code>, <code>qwen2.5vl:7b</code> (Ollama), <code>gpt-4o-mini</code> (OpenAI).', 'image-seo-pro' ), array( 'code' => array() ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="isp-ai-key"><?php esc_html_e( 'API key', 'image-seo-pro' ); ?></label></th>
					<td>
						<input type="password" id="isp-ai-key" name="<?php echo esc_attr( $name ); ?>[ai_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['ai_key'] ? esc_attr__( 'Saved — leave empty to keep it', 'image-seo-pro' ) : ''; ?>">
						<?php if ( $s['ai_key'] ) : ?>
							<br><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ai_key_clear]" value="1"> <?php esc_html_e( 'Delete the saved key', 'image-seo-pro' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Not needed for Ollama.', 'image-seo-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Context', 'image-seo-pro' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ai_context]" value="1" <?php checked( $s['ai_context'] ); ?>> <?php esc_html_e( 'Give the AI the title of the page the image was uploaded to', 'image-seo-pro' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test', 'image-seo-pro' ); ?></th>
					<td>
						<button type="button" class="button isp-test-ai"><?php esc_html_e( 'Test the connection', 'image-seo-pro' ); ?></button>
						<span class="isp-test-result" aria-live="polite"></span>
						<p class="description"><?php esc_html_e( 'Tests the saved settings: save first.', 'image-seo-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Médiathèque                                                        */
	/* ------------------------------------------------------------------ */

	public function media_column( $columns ) {
		$columns['isp'] = __( 'Optimization', 'image-seo-pro' );
		return $columns;
	}

	public function media_column_content( $column, $id ) {
		if ( 'isp' !== $column || ! wp_attachment_is_image( $id ) ) {
			return;
		}
		$data = ISP_Optimizer::get_data( $id );
		if ( $data ) {
			$summary = ISP_Optimizer::summarize( $data );
			echo esc_html( '−' . $summary['percent'] . ' % · ' . implode( ', ', array_map( array( 'ISP_Converter', 'label' ), array_keys( $summary['formats'] ) ) ) );
		} elseif ( ISP_Optimizer::is_optimizable( $id ) ) {
			echo '<a href="' . esc_url( self::url( 'images', array( 's' => get_the_title( $id ) ) ) ) . '">' . esc_html__( 'Not optimized', 'image-seo-pro' ) . '</a>';
		} else {
			echo '—';
		}
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Vérifie le jeton et les droits ; renvoie l'ID du média visé (ou 0 si non demandé).
	 *
	 * @param bool   $needs_id   L'action porte sur un média.
	 * @param string $capability Droit requis.
	 * @return int
	 */
	private function guard( $needs_id = true, $capability = 'upload_files' ) {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page.', 'image-seo-pro' ) ), 403 );
		}
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'image-seo-pro' ) ), 403 );
		}
		if ( ! $needs_id ) {
			return 0;
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Image not found.', 'image-seo-pro' ) ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'image-seo-pro' ) ), 403 );
		}
		return $id;
	}

	public function ajax_optimize() {
		$id     = $this->guard();
		$result = ISP_Optimizer::optimize( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$summary = ISP_Optimizer::summarize( $result );
		wp_send_json_success(
			array(
				'row'     => self::image_row( $id ),
				'saved'   => $summary['saved'],
				'message' => sprintf(
					/* translators: 1: file name, 2: size saved, 3: percentage. */
					__( '%1$s: %2$s saved (−%3$d %%)', 'image-seo-pro' ),
					wp_basename( (string) get_attached_file( $id, true ) ),
					size_format( $summary['saved'], 1 ),
					$summary['percent']
				),
			)
		);
	}

	public function ajax_restore() {
		$id = $this->guard();
		ISP_Optimizer::restore( $id );
		wp_send_json_success( array( 'row' => self::image_row( $id ) ) );
	}

	public function ajax_pending() {
		$this->guard( false );
		wp_send_json_success( array( 'ids' => ISP_Optimizer::pending_ids( ! empty( $_POST['force'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- vérifié dans guard().
	}

	public function ajax_missing_alt() {
		$this->guard( false );
		wp_send_json_success( array( 'ids' => ISP_Alt_Text::missing_ids() ) );
	}

	public function ajax_save_alt() {
		$id  = $this->guard();
		$alt = ISP_Alt_Text::save( $id, isset( $_POST['alt'] ) ? (string) $_POST['alt'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- nettoyé dans save().
		wp_send_json_success( array( 'alt' => $alt ) );
	}

	/**
	 * Suggestion de texte alternatif (IA ou nom de fichier), enregistrée si save=1.
	 */
	public function ajax_suggest_alt() {
		$id   = $this->guard();
		$mode = isset( $_POST['mode'] ) && 'ai' === $_POST['mode'] ? 'ai' : 'filename'; // phpcs:ignore WordPress.Security.NonceVerification

		$alt = 'ai' === $mode ? ISP_AI_Client::describe( $id ) : ISP_Alt_Text::suggest_from_filename( $id );
		if ( is_wp_error( $alt ) ) {
			wp_send_json_error( array( 'message' => $alt->get_error_message() ) );
		}
		if ( '' === $alt ) {
			wp_send_json_error( array( 'message' => __( 'No useful suggestion for this file name.', 'image-seo-pro' ) ) );
		}
		if ( ! empty( $_POST['save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$alt = ISP_Alt_Text::save( $id, $alt );
		}
		wp_send_json_success(
			array(
				'alt'     => $alt,
				'message' => wp_basename( (string) get_attached_file( $id, true ) ) . ' : ' . $alt,
			)
		);
	}

	public function ajax_test_ai() {
		$this->guard( false, 'manage_options' );
		$result = ISP_AI_Client::test();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success();
	}
}
