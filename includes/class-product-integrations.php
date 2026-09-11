<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Built-in integrations for nisehatakiti products.
 *
 * NHK Form remains standalone. Integrations are activated only when the
 * corresponding product is loaded in the same WordPress installation.
 */
class NHK_Form_Product_Integrations {
	public static function register() {
		static $registered = false;
		if ( $registered ) return;
		$registered = true;

		self::register_alumni_core();
		self::register_alumni_core_form_templates();
		self::import_alumni_core_forms();
		self::mirror_existing_nhk_forms_to_alumni();
		self::reconcile_alumni_links();

		// Trash is intentionally not synchronized. Only permanent deletion unlinks
		// the surviving record. Explicit two-sided removal is handled separately.
		add_action( 'before_delete_post', array( __CLASS__, 'handle_permanent_delete' ), 10, 2 );
		if ( is_admin() ) {
			add_filter( 'post_row_actions', array( __CLASS__, 'add_link_actions' ), 20, 2 );
			add_action( 'admin_post_nhk_form_link_action', array( __CLASS__, 'handle_link_action' ) );
		}
		do_action( 'nhk_form_register_product_schemas' );
	}

	private static function register_alumni_core() {
		if ( ! class_exists( '\\AlumniCore\\Includes\\Modules\\Content\\Post_Type' )
			|| ! class_exists( '\\AlumniCore\\Includes\\Modules\\NewsEvents\\Post_Type' ) ) {
			return;
		}

		$content = '\\AlumniCore\\Includes\\Modules\\Content\\Post_Type';
		$news    = '\\AlumniCore\\Includes\\Modules\\NewsEvents\\Post_Type';

		NHK_Form_Schema_Registry::register( 'alumni_person_greeting', array(
			'label'       => 'Alumni Core：人物挨拶',
			'description' => 'Alumni Coreの人物挨拶コンテンツとして下書きを作成します。',
			'provider'    => 'alumni-core',
			'post_type'   => $content::SLUG,
			'fields'      => array(
				array( 'key' => 'content_name', 'label' => 'コンテンツ名', 'type' => 'text', 'required' => true ),
				array( 'key' => 'person_name', 'label' => '氏名', 'type' => 'text', 'required' => true ),
				array( 'key' => 'person_kana', 'label' => '氏名（ふりがな）', 'type' => 'text', 'required' => false ),
				array( 'key' => 'person_title', 'label' => '役職', 'type' => 'text', 'required' => false ),
				array( 'key' => 'person_term', 'label' => '卒業期', 'type' => 'number', 'required' => false ),
				array( 'key' => 'person_tenure', 'label' => '任期', 'type' => 'text', 'required' => false ),
				array( 'key' => 'body', 'label' => '挨拶文', 'type' => 'textarea', 'required' => true ),
				array( 'key' => 'photo', 'label' => 'プロフィール写真', 'type' => 'file', 'required' => false, 'allowed_extensions' => 'jpg,jpeg,png,webp' ),
			),
			'fixed_meta' => array(
				$content::META_KIND => $content::KIND_PERSON_GREETING,
			),
			'map' => array(
				'title'   => 'content_name',
				'content' => 'body',
				'meta'    => array(
					$content::META_PERSON_NAME   => 'person_name',
					$content::META_PERSON_KANA   => 'person_kana',
					$content::META_PERSON_TITLE  => 'person_title',
					$content::META_PERSON_TERM   => 'person_term',
					$content::META_PERSON_TENURE => 'person_tenure',
				),
				'files' => array(
					'photo' => $content::META_PERSON_PHOTO_ID,
				),
			),
		) );

		NHK_Form_Schema_Registry::register( 'alumni_news', array(
			'label'       => 'Alumni Core：ニュース',
			'description' => 'Alumni Coreのニュース・イベントにニュースの下書きを作成します。',
			'provider'    => 'alumni-core',
			'post_type'   => $news::SLUG,
			'fields'      => array(
				array( 'key' => 'title', 'label' => 'タイトル', 'type' => 'text', 'required' => true ),
				array( 'key' => 'content', 'label' => '内容', 'type' => 'textarea', 'required' => true ),
				array( 'key' => 'photo', 'label' => '写真', 'type' => 'file', 'required' => false, 'allowed_extensions' => 'jpg,jpeg,png,webp' ),
			),
			'fixed_meta' => array(
				$news::META_CONTENT_TYPE => $news::TYPE_NEWS,
			),
			'map' => array(
				'title'   => 'title',
				'content' => 'content',
				'files'   => array( 'photo' => 'featured' ),
			),
		) );

		NHK_Form_Schema_Registry::register( 'alumni_event', array(
			'label'       => 'Alumni Core：イベント',
			'description' => 'Alumni Coreのニュース・イベントにイベントの下書きを作成します。',
			'provider'    => 'alumni-core',
			'post_type'   => $news::SLUG,
			'fields'      => array(
				array( 'key' => 'title', 'label' => 'タイトル', 'type' => 'text', 'required' => true ),
				array( 'key' => 'event_date', 'label' => '開催日', 'type' => 'date', 'required' => true ),
				array( 'key' => 'content', 'label' => '内容', 'type' => 'textarea', 'required' => true ),
				array( 'key' => 'photo', 'label' => '写真', 'type' => 'file', 'required' => false, 'allowed_extensions' => 'jpg,jpeg,png,webp' ),
			),
			'fixed_meta' => array(
				$news::META_CONTENT_TYPE => $news::TYPE_EVENT,
			),
			'map' => array(
				'title'   => 'title',
				'content' => 'content',
				'meta'    => array(
					$news::META_EVENT_DATE => 'event_date',
				),
				'files'   => array( 'photo' => 'featured' ),
			),
		) );
	}


	/**
	 * Import existing Alumni Core form definitions as editable NHK Form posts.
	 * The original Alumni Core post remains the source and is updated whenever
	 * its NHK mirror is edited.
	 */

	/**
	 * Reconcile forms that were created in NHK Form before bidirectional
	 * mirroring was introduced. This runs only for an administrator in wp-admin
	 * so public requests never perform synchronization writes.
	 */
	private static function mirror_existing_nhk_forms_to_alumni() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		if ( ! class_exists( '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type' ) ) return;

		$forms = get_posts( array(
			'post_type'      => NHK_Form_Post_Type::SLUG,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => NHK_Form_Post_Type::META_SOURCE_PROVIDER,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => NHK_Form_Post_Type::META_LINK_STATE,
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		foreach ( $forms as $form ) {
			self::sync_alumni_source_from_nhk( $form->ID );
		}
	}


	/**
	 * Clear links whose Alumni Core counterpart was permanently deleted.
	 * Trash does not reach this path and therefore keeps the relationship.
	 */
	private static function reconcile_alumni_links() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		if ( ! class_exists( '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type' ) ) return;

		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		$forms = get_posts( array(
			'post_type'      => NHK_Form_Post_Type::SLUG,
			'post_status'    => array( 'publish', 'draft', 'private', 'trash' ),
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => NHK_Form_Post_Type::META_SOURCE_PROVIDER, 'value' => 'alumni-core' ),
				array( 'key' => NHK_Form_Post_Type::META_SOURCE_ID, 'compare' => 'EXISTS' ),
			),
		) );

		foreach ( $forms as $form ) {
			$source_id = NHK_Form_Post_Type::source_id( $form->ID );
			$source = $source_id ? get_post( $source_id ) : null;
			if ( ! $source || $source->post_type !== $form_type::SLUG ) {
				self::unlink_nhk_form( $form->ID );
			}
		}
	}

	/**
	 * Native permanent deletion: preserve the surviving form and remove only
	 * the relationship. WordPress trash operations are deliberately ignored.
	 */
	public static function handle_permanent_delete( $post_id, $post ) {
		if ( ! $post instanceof WP_Post ) return;

		if ( NHK_Form_Post_Type::SLUG === $post->post_type ) {
			if ( 'alumni-core' !== NHK_Form_Post_Type::source_provider( $post_id ) ) return;
			$source_id = NHK_Form_Post_Type::source_id( $post_id );
			if ( $source_id && get_post( $source_id ) ) {
				update_post_meta( $source_id, '_nhk_form_link_state', 'unlinked' );
				update_post_meta( $source_id, '_nhk_form_unlinked_at', current_time( 'mysql' ) );
			}
			return;
		}

		if ( ! class_exists( '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type' ) ) return;
		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		if ( $form_type::SLUG !== $post->post_type ) return;

		$mirrors = get_posts( array(
			'post_type'      => NHK_Form_Post_Type::SLUG,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => NHK_Form_Post_Type::META_SOURCE_PROVIDER, 'value' => 'alumni-core' ),
				array( 'key' => NHK_Form_Post_Type::META_SOURCE_ID, 'value' => $post_id ),
			),
		) );
		foreach ( $mirrors as $nhk_id ) self::unlink_nhk_form( $nhk_id );
	}

	private static function unlink_nhk_form( $nhk_id ) {
		delete_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_PROVIDER );
		delete_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_ID );
		update_post_meta( $nhk_id, NHK_Form_Post_Type::META_LINK_STATE, 'unlinked' );
		update_post_meta( $nhk_id, '_nhk_form_unlinked_at', current_time( 'mysql' ) );
	}

	private static function find_nhk_mirrors( $source_id ) {
		return get_posts( array(
			'post_type'      => NHK_Form_Post_Type::SLUG,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => NHK_Form_Post_Type::META_SOURCE_PROVIDER, 'value' => 'alumni-core' ),
				array( 'key' => NHK_Form_Post_Type::META_SOURCE_ID, 'value' => $source_id ),
			),
		) );
	}

	public static function add_link_actions( $actions, $post ) {
		if ( ! current_user_can( 'delete_post', $post->ID ) ) return $actions;

		$is_nhk = NHK_Form_Post_Type::SLUG === $post->post_type;
		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		$is_alumni = class_exists( $form_type ) && $form_type::SLUG === $post->post_type;
		if ( ! $is_nhk && ! $is_alumni ) return $actions;

		$linked = $is_nhk
			? ( 'alumni-core' === NHK_Form_Post_Type::source_provider( $post->ID ) && NHK_Form_Post_Type::source_id( $post->ID ) )
			: ! empty( self::find_nhk_mirrors( $post->ID ) );

		if ( $linked ) {
			$actions['nhk_unlink'] = '<a href="' . esc_url( self::action_url( $post->ID, 'unlink' ) ) . '">連携解除</a>';
			if ( 'trash' !== $post->post_status ) {
				$actions['nhk_trash_both'] = '<a href="' . esc_url( self::action_url( $post->ID, 'trash_both' ) ) . '" onclick="return confirm(\'連携先もゴミ箱へ移動します。\');">両方をゴミ箱へ</a>';
			}
		} elseif ( $is_nhk && 'unlinked' === get_post_meta( $post->ID, NHK_Form_Post_Type::META_LINK_STATE, true ) ) {
			$actions['nhk_reconnect'] = '<a href="' . esc_url( self::action_url( $post->ID, 'reconnect' ) ) . '">Alumni Coreに再接続</a>';
		} elseif ( $is_alumni && 'unlinked' === get_post_meta( $post->ID, '_nhk_form_link_state', true ) ) {
			$actions['nhk_reconnect'] = '<a href="' . esc_url( self::action_url( $post->ID, 'reconnect' ) ) . '">NHK Formに再接続</a>';
		}
		return $actions;
	}

	private static function action_url( $post_id, $link_action ) {
		$url = add_query_arg( array(
			'action'      => 'nhk_form_link_action',
			'post_id'     => (int) $post_id,
			'link_action' => $link_action,
		), admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, 'nhk_form_link_action_' . $post_id . '_' . $link_action );
	}

	public static function handle_link_action() {
		$post_id = absint( $_GET['post_id'] ?? 0 );
		$link_action = sanitize_key( $_GET['link_action'] ?? '' );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! current_user_can( 'delete_post', $post_id ) ) wp_die( '権限がありません。' );
		check_admin_referer( 'nhk_form_link_action_' . $post_id . '_' . $link_action );

		$is_nhk = NHK_Form_Post_Type::SLUG === $post->post_type;
		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		$is_alumni = class_exists( $form_type ) && $form_type::SLUG === $post->post_type;
		if ( ! $is_nhk && ! $is_alumni ) wp_die( '対象フォームではありません。' );

		if ( 'unlink' === $link_action ) {
			if ( $is_nhk ) {
				$source_id = NHK_Form_Post_Type::source_id( $post_id );
				if ( $source_id ) update_post_meta( $source_id, '_nhk_form_link_state', 'unlinked' );
				self::unlink_nhk_form( $post_id );
			} else {
				foreach ( self::find_nhk_mirrors( $post_id ) as $nhk_id ) self::unlink_nhk_form( $nhk_id );
				update_post_meta( $post_id, '_nhk_form_link_state', 'unlinked' );
			}
			$result = 'unlinked';
		} elseif ( 'trash_both' === $link_action ) {
			if ( $is_nhk ) {
				$source_id = NHK_Form_Post_Type::source_id( $post_id );
				if ( $source_id && get_post_status( $source_id ) !== 'trash' ) wp_trash_post( $source_id );
				if ( get_post_status( $post_id ) !== 'trash' ) wp_trash_post( $post_id );
			} else {
				foreach ( self::find_nhk_mirrors( $post_id ) as $nhk_id ) if ( get_post_status( $nhk_id ) !== 'trash' ) wp_trash_post( $nhk_id );
				if ( get_post_status( $post_id ) !== 'trash' ) wp_trash_post( $post_id );
			}
			$result = 'trashed_both';
		} elseif ( 'reconnect' === $link_action ) {
			if ( $is_nhk ) {
				delete_post_meta( $post_id, NHK_Form_Post_Type::META_LINK_STATE );
				delete_post_meta( $post_id, '_nhk_form_unlinked_at' );
				self::sync_alumni_source_from_nhk( $post_id );
			} else {
				delete_post_meta( $post_id, '_nhk_form_link_state' );
				delete_post_meta( $post_id, '_nhk_form_unlinked_at' );
				$nhk_id = wp_insert_post( array(
					'post_type'   => NHK_Form_Post_Type::SLUG,
					'post_status' => $post->post_status,
					'post_title'  => $post->post_title,
				), true );
				if ( ! is_wp_error( $nhk_id ) && $nhk_id ) {
					self::copy_alumni_form_to_nhk( $post_id, $nhk_id );
					update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_PROVIDER, 'alumni-core' );
					update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_ID, $post_id );
				}
			}
			$result = 'reconnected';
		} else {
			wp_die( '無効な操作です。' );
		}

		$redirect = wp_get_referer() ?: admin_url( 'edit.php?post_type=' . $post->post_type );
		wp_safe_redirect( add_query_arg( 'nhk_link_result', $result, $redirect ) );
		exit;
	}

	private static function import_alumni_core_forms() {
		if ( ! class_exists( '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type' ) ) return;
		if ( ! current_user_can( 'manage_options' ) && is_admin() ) return;

		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		$forms = get_posts( array(
			'post_type'      => $form_type::SLUG,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		foreach ( $forms as $source ) {
			// A permanently deleted NHK mirror intentionally leaves the Alumni
			// form alive. Do not silently recreate it until the user reconnects.
			if ( 'unlinked' === get_post_meta( $source->ID, '_nhk_form_link_state', true ) ) continue;

			$existing = get_posts( array(
				'post_type'      => NHK_Form_Post_Type::SLUG,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => NHK_Form_Post_Type::META_SOURCE_PROVIDER, 'value' => 'alumni-core' ),
					array( 'key' => NHK_Form_Post_Type::META_SOURCE_ID, 'value' => $source->ID ),
				),
				'fields'         => 'ids',
			) );

			if ( $existing ) {
				self::ensure_alumni_import_configuration( (int) $existing[0], $source->ID );
				continue;
			}

			$nhk_id = wp_insert_post( array(
				'post_type'   => NHK_Form_Post_Type::SLUG,
				'post_status' => $source->post_status,
				'post_title'  => $source->post_title,
			), true );
			if ( is_wp_error( $nhk_id ) ) continue;

			self::copy_alumni_form_to_nhk( $source->ID, $nhk_id );
			update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_PROVIDER, 'alumni-core' );
			update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_ID, $source->ID );
		}
	}


	private static function ensure_alumni_import_configuration( $nhk_id, $source_id ) {
		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		if ( ! $form_type::is_content_submission( $source_id ) ) return;

		$schema = 'alumni_form_' . $source_id;
		if ( NHK_Form_Post_Type::mode( $nhk_id ) !== 'post_submission' ) {
			update_post_meta( $nhk_id, NHK_Form_Post_Type::META_MODE, 'post_submission' );
		}
		if ( NHK_Form_Post_Type::schema( $nhk_id ) !== $schema ) {
			update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SCHEMA, $schema );
		}
	}

	private static function copy_alumni_form_to_nhk( $source_id, $nhk_id ) {
		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		$mode   = $form_type::get_mode( $source_id );
		$target = $form_type::get_target( $source_id );
		$schema = '';
		if ( 'content_submission' === $mode && $target ) {
			$schema = 'alumni_form_' . $source_id;
		}

		$meta_map = array(
			NHK_Form_Post_Type::META_DESCRIPTION    => $form_type::META_DESCRIPTION,
			NHK_Form_Post_Type::META_RECIPIENTS     => $form_type::META_RECIPIENT_EMAIL,
			NHK_Form_Post_Type::META_MAIL_SUBJECT   => $form_type::META_MAIL_SUBJECT,
			NHK_Form_Post_Type::META_SUCCESS        => $form_type::META_SUCCESS_MESSAGE,
			NHK_Form_Post_Type::META_AUTO_REPLY     => $form_type::META_AUTO_REPLY_ENABLED,
			NHK_Form_Post_Type::META_FROM_NAME      => $form_type::META_FROM_NAME,
			NHK_Form_Post_Type::META_FROM_EMAIL     => $form_type::META_FROM_EMAIL,
			NHK_Form_Post_Type::META_REPLY_TO_MODE  => $form_type::META_REPLY_TO_MODE,
			NHK_Form_Post_Type::META_REPLY_TO_EMAIL => $form_type::META_REPLY_TO_EMAIL,
			NHK_Form_Post_Type::META_REPLY_TO_FIELD => $form_type::META_REPLY_TO_FIELD_KEY,
			NHK_Form_Post_Type::META_FIELDS         => $form_type::META_FIELDS,
		);
		foreach ( $meta_map as $nhk_meta => $source_meta ) {
			update_post_meta( $nhk_id, $nhk_meta, get_post_meta( $source_id, $source_meta, true ) );
		}

		update_post_meta( $nhk_id, NHK_Form_Post_Type::META_MODE, 'content_submission' === $mode ? 'post_submission' : 'standard' );
		update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SCHEMA, $schema );
	}

	/**
	 * Synchronize every NHK Form into Alumni Core when Alumni Core is active.
	 *
	 * Imported forms already have a source. Standalone forms created in NHK Form
	 * get an Alumni Core alumni_form record on their first save, which makes them
	 * immediately available to Alumni Core content/section selectors as well.
	 */
	public static function sync_alumni_source_from_nhk( $nhk_id ) {
		if ( ! class_exists( '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type' ) ) return;

		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		$source_id = NHK_Form_Post_Type::source_id( $nhk_id );
		$provider  = NHK_Form_Post_Type::source_provider( $nhk_id );

		if ( 'alumni-core' !== $provider || ! $source_id ) {
			$source_id = wp_insert_post( array(
				'post_type'   => $form_type::SLUG,
				'post_status' => get_post_status( $nhk_id ) ?: 'draft',
				'post_title'  => get_the_title( $nhk_id ),
			), true );
			if ( is_wp_error( $source_id ) || ! $source_id ) return;

			update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_PROVIDER, 'alumni-core' );
			update_post_meta( $nhk_id, NHK_Form_Post_Type::META_SOURCE_ID, (int) $source_id );
		}

		$source = get_post( $source_id );
		if ( ! $source || $form_type::SLUG !== $source->post_type ) return;
		$mode = NHK_Form_Post_Type::mode( $nhk_id );
		$schema = NHK_Form_Post_Type::schema( $nhk_id );
		$target = '';
		if ( 'post_submission' === $mode ) {
			if ( 0 === strpos( $schema, 'alumni_form_' ) ) {
				$target = $form_type::get_target( $source_id );
			} elseif ( 'alumni_person_greeting' === $schema ) {
				$target = 'person_greeting';
			} elseif ( 'alumni_news' === $schema || 'alumni_event' === $schema ) {
				$target = 'news_event';
			}
			if ( ! $target ) $target = $form_type::get_target( $source_id );
		}

		wp_update_post( array(
			'ID'          => $source_id,
			'post_title'  => get_the_title( $nhk_id ),
			'post_status' => get_post_status( $nhk_id ),
		) );

		$meta_map = array(
			$form_type::META_DESCRIPTION        => NHK_Form_Post_Type::META_DESCRIPTION,
			$form_type::META_RECIPIENT_EMAIL    => NHK_Form_Post_Type::META_RECIPIENTS,
			$form_type::META_MAIL_SUBJECT       => NHK_Form_Post_Type::META_MAIL_SUBJECT,
			$form_type::META_SUCCESS_MESSAGE    => NHK_Form_Post_Type::META_SUCCESS,
			$form_type::META_AUTO_REPLY_ENABLED => NHK_Form_Post_Type::META_AUTO_REPLY,
			$form_type::META_FROM_NAME          => NHK_Form_Post_Type::META_FROM_NAME,
			$form_type::META_FROM_EMAIL         => NHK_Form_Post_Type::META_FROM_EMAIL,
			$form_type::META_REPLY_TO_MODE      => NHK_Form_Post_Type::META_REPLY_TO_MODE,
			$form_type::META_REPLY_TO_EMAIL     => NHK_Form_Post_Type::META_REPLY_TO_EMAIL,
			$form_type::META_REPLY_TO_FIELD_KEY => NHK_Form_Post_Type::META_REPLY_TO_FIELD,
			$form_type::META_FIELDS             => NHK_Form_Post_Type::META_FIELDS,
		);
		foreach ( $meta_map as $source_meta => $nhk_meta ) {
			update_post_meta( $source_id, $source_meta, get_post_meta( $nhk_id, $nhk_meta, true ) );
		}
		update_post_meta( $source_id, $form_type::META_MODE, 'post_submission' === $mode ? 'content_submission' : 'standard' );
		update_post_meta( $source_id, $form_type::META_TARGET, 'post_submission' === $mode ? $target : '' );
	}

	private static function register_alumni_core_form_templates() {
		if ( ! class_exists( '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type' ) ) return;

		$form_type = '\\AlumniCore\\Includes\\Modules\\Forms\\Post_Type';
		$forms = get_posts( array(
			'post_type'      => $form_type::SLUG,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		foreach ( $forms as $form ) {
			$target = $form_type::get_target( $form->ID );
			if ( ! $target ) continue;

			$fields = $form_type::get_fields( $form->ID );
			if ( ! $fields ) continue;

			$keys = array();
			foreach ( $fields as $field ) {
				if ( ! empty( $field['key'] ) ) $keys[] = sanitize_key( $field['key'] );
			}

			$map = self::legacy_alumni_map( $target, $keys );
			if ( empty( $map ) ) {
				$map = self::fallback_alumni_map( $target, $fields );
			}
			if ( empty( $map ) ) continue;

			$schema = array(
				'label'       => 'Alumni Coreフォーム：' . get_the_title( $form ),
				'description' => 'Alumni Coreに登録済みのフォーム定義を自動読み取りしました。',
				'provider'    => 'alumni-core',
				'post_type'   => $map['post_type'],
				'fields'      => $fields,
				'fixed_meta'  => $map['fixed_meta'],
				'map'         => $map['map'],
			);

			NHK_Form_Schema_Registry::register( 'alumni_form_' . $form->ID, $schema );
		}
	}


	/**
	 * Existing Alumni Core forms may use arbitrary field keys. Always provide
	 * a usable submission map so imported content-submission forms can still
	 * create Alumni Core draft content instead of silently becoming schemas
	 * that cannot be resolved.
	 */
	private static function fallback_alumni_map( $target, $fields ) {
		$title = '';
		$content = '';
		$date = '';
		$file = '';

		foreach ( (array) $fields as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			$type = sanitize_key( $field['type'] ?? 'text' );
			if ( ! $key ) continue;
			if ( ! $title && 'file' !== $type ) $title = $key;
			if ( ! $content && 'textarea' === $type ) $content = $key;
			if ( ! $date && 'date' === $type ) $date = $key;
			if ( ! $file && 'file' === $type ) $file = $key;
		}

		if ( ! $title ) return array();

		if ( 'person_greeting' === $target
			&& class_exists( '\\AlumniCore\\Includes\\Modules\\Content\\Post_Type' ) ) {
			$content_type = '\\AlumniCore\\Includes\\Modules\\Content\\Post_Type';
			return array(
				'post_type'  => $content_type::SLUG,
				'fixed_meta' => array( $content_type::META_KIND => $content_type::KIND_PERSON_GREETING ),
				'map'        => array(
					'title'   => $title,
					'content' => $content,
					'meta'    => array(),
					'files'   => $file ? array( $file => $content_type::META_PERSON_PHOTO_ID ) : array(),
				),
			);
		}

		if ( 'news_event' === $target
			&& class_exists( '\\AlumniCore\\Includes\\Modules\\NewsEvents\\Post_Type' ) ) {
			$news = '\\AlumniCore\\Includes\\Modules\\NewsEvents\\Post_Type';
			return array(
				'post_type'  => $news::SLUG,
				'fixed_meta' => array( $news::META_CONTENT_TYPE => $date ? $news::TYPE_EVENT : $news::TYPE_NEWS ),
				'map'        => array(
					'title'   => $title,
					'content' => $content,
					'meta'    => $date ? array( $news::META_EVENT_DATE => $date ) : array(),
					'files'   => $file ? array( $file => 'featured' ) : array(),
				),
			);
		}

		return array();
	}

	private static function legacy_alumni_map( $target, $keys ) {
		$pick = function( $candidates ) use ( $keys ) {
			foreach ( $candidates as $key ) if ( in_array( $key, $keys, true ) ) return $key;
			return '';
		};

		if ( 'person_greeting' === $target
			&& class_exists( '\\AlumniCore\\Includes\\Modules\\Content\\Post_Type' ) ) {
			$content = '\\AlumniCore\\Includes\\Modules\\Content\\Post_Type';
			$title = $pick( array( 'content_name', 'name', 'title' ) );
			$body  = $pick( array( 'body', 'content' ) );
			if ( ! $title ) return array();

			$meta = array();
			$pairs = array(
				$content::META_PERSON_NAME   => array( 'person_name', 'name' ),
				$content::META_PERSON_KANA   => array( 'person_kana', 'kana' ),
				$content::META_PERSON_TITLE  => array( 'person_title', 'role', 'position' ),
				$content::META_PERSON_TERM   => array( 'person_term', 'term' ),
				$content::META_PERSON_TENURE => array( 'person_tenure', 'tenure' ),
			);
			foreach ( $pairs as $meta_key => $candidates ) {
				$key = $pick( $candidates );
				if ( $key ) $meta[ $meta_key ] = $key;
			}

			$files = array();
			$photo = $pick( array( 'photo', 'profile_photo', 'image' ) );
			if ( $photo ) $files[ $photo ] = $content::META_PERSON_PHOTO_ID;

			return array(
				'post_type'  => $content::SLUG,
				'fixed_meta' => array( $content::META_KIND => $content::KIND_PERSON_GREETING ),
				'map'        => array( 'title' => $title, 'content' => $body, 'meta' => $meta, 'files' => $files ),
			);
		}

		if ( 'news_event' === $target
			&& class_exists( '\\AlumniCore\\Includes\\Modules\\NewsEvents\\Post_Type' ) ) {
			$news = '\\AlumniCore\\Includes\\Modules\\NewsEvents\\Post_Type';
			$title = $pick( array( 'title', 'name' ) );
			if ( ! $title ) return array();
			$content = $pick( array( 'content', 'body' ) );
			$date = $pick( array( 'event_date', 'date' ) );
			$photo = $pick( array( 'photo', 'image' ) );
			return array(
				'post_type'  => $news::SLUG,
				'fixed_meta' => array( $news::META_CONTENT_TYPE => $date ? $news::TYPE_EVENT : $news::TYPE_NEWS ),
				'map'        => array(
					'title'   => $title,
					'content' => $content,
					'meta'    => $date ? array( $news::META_EVENT_DATE => $date ) : array(),
					'files'   => $photo ? array( $photo => 'featured' ) : array(),
				),
			);
		}

		return array();
	}

}
