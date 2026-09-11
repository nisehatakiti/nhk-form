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
		self::register_alumni_core();
		self::register_alumni_core_form_templates();
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
