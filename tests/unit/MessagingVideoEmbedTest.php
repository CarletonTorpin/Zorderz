<?php
/**
 * A chapter embed pasted into a message is distilled to a [zdz-video] token before the sanitizer: Zorderz's own class
 * names out of the box, another producer's when a business names them with the zim_video_embed_classes filter.
 * Made-up video ids only.
 */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}

final class MessagingVideoEmbedTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters']['zim_video_embed_classes'], $GLOBALS['zdz_test_options'][ ZIM_Messages::VIDEO_CLASSES_OPT ] );
	}

	private static function embed( string $box, string $ch, string $title ): string {
		return '<div class="' . $box . '"><iframe src="https://player.vimeo.com/video/123456789?h=abc123"></iframe>'
			. '<a class="' . $ch . '" data-t="0"><span class="' . $title . '">Start</span></a>'
			. '<a class="' . $ch . '" data-t="75.5"><span class="' . $title . '">Fitting</span></a></div>';
	}

	private static function decode( string $body ): array {
		if ( ! preg_match( '/\[zdz-video\]([0-9a-f]+)\[\/zdz-video\]/', $body, $m ) ) {
			return array();
		}
		return (array) json_decode( hex2bin( $m[1] ), true );
	}

	public function test_zorderz_classes_carry_the_chapters(): void {
		$p = self::decode( ZIM_Messages::extract_video_embeds( self::embed( 'zdz-video-embed', 'zdz-video-chapter', 'zdz-video-title' ) ) );
		$this->assertSame( '123456789', $p['v'] );
		$this->assertSame( array( array( 0, 'Start' ), array( 75, 'Fitting' ) ), $p['c'] );
	}

	public function test_another_producers_classes_need_the_filter(): void {
		$html = self::embed( 'other-embed', 'other-ch', 'other-title' );
		$this->assertSame( array(), self::decode( ZIM_Messages::extract_video_embeds( $html ) )['c'] ?? array(), 'the player alone' );
		$GLOBALS['zdz_test_filters']['zim_video_embed_classes'] = function () {
			return array( 'embed' => 'other-embed', 'chapter' => 'other-ch', 'heading' => 'other-heading', 'title' => 'other-title' );
		};
		$this->assertCount( 2, self::decode( ZIM_Messages::extract_video_embeds( $html ) )['c'] );
	}

	public function test_an_identity_pack_names_the_businesss_own_classes(): void {
		$html = self::embed( 'other-embed', 'other-ch', 'other-title' );
		$GLOBALS['zdz_test_options'][ ZIM_Messages::VIDEO_CLASSES_OPT ] = array( 'embed' => 'other-embed', 'chapter' => 'other-ch', 'title' => 'other-title' );
		$this->assertCount( 2, self::decode( ZIM_Messages::extract_video_embeds( $html ) )['c'], 'the stored names are read; heading stays Core\'s' );

		if ( ! class_exists( 'ZDZ_Identity_Pack' ) ) {
			require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';
		}
		unset( $GLOBALS['zdz_test_options'][ ZIM_Messages::VIDEO_CLASSES_OPT ] );
		$plan = ZIM_Messages::plan_video_classes( array( 'messaging.video_embed_classes' => array( 'embed' => 'other-embed', 'chapter' => 'x|.*', 'colour' => 'red' ) ) );
		$this->assertSame( array( array( 'option' => ZIM_Messages::VIDEO_CLASSES_OPT, 'key' => null, 'value' => array( 'embed' => 'other-embed' ) ) ), $plan['writes'] );
		$this->assertSame( array( 'messaging.video_embed_classes.chapter', 'messaging.video_embed_classes.colour' ), array_column( $plan['refused'], 'path' ) );
		$this->assertNotEmpty( ZIM_Messages::plan_video_classes( array( 'messaging.video_embed_classes' => 'other-embed' ) )['refused'] );
		$c = ZIM_Messages::pack_consumer( array() );
		$this->assertSame( array( 'mappings' ), $c['messaging-video']['files'] );
		$this->assertSame( array( 'mappings' => array( 'messaging.video_embed_classes' ) ), $c['messaging-video']['paths'] );
	}

	public function test_a_class_name_never_changes_the_pattern(): void {
		$GLOBALS['zdz_test_filters']['zim_video_embed_classes'] = function () {
			return array( 'embed' => 'x|.*', 'chapter' => 'zdz-video-chapter' );
		};
		$p = self::decode( ZIM_Messages::extract_video_embeds( self::embed( 'zdz-video-embed', 'zdz-video-chapter', 'zdz-video-title' ) ) );
		$this->assertCount( 2, $p['c'], 'a bad class name falls back to Core\'s own' );
	}
}
