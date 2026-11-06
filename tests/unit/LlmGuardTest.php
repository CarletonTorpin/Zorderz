<?php
/**
 * The zero-data-retention guard: everything outside the eligible set is refused before it is sent, never stripped;
 * the model must be one the router's slots name; the region is exactly the configured one; the native adapter's own
 * bodies always pass it.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/llm-test-doubles.php';

final class LlmGuardTest extends TestCase {

	const URL = 'https://api.anthropic.com/v1/messages';

	private static function pol( $geo = '' ) {
		return array( 'models' => array( 'example-large-1', 'example-small-1' ), 'geo' => $geo );
	}

	private static function body( array $more = array() ) {
		return $more + array(
			'model'         => 'example-large-1',
			'max_tokens'    => 1000,
			'messages'      => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'Hello' ) ) ) ),
			'thinking'      => array( 'type' => 'adaptive' ),
			'output_config' => array( 'effort' => 'medium' ),
		);
	}

	private static function headers() {
		return array( 'x-api-key' => 'k', 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	public function test_a_plain_request_passes(): void {
		$this->assertSame( array(), ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body(), false, self::pol() ) );
		$this->assertNull( ZDZ_LLM_Guard::check( self::URL, self::headers(), self::body(), false, self::pol() ) );
	}

	public function test_endpoints_other_than_the_messages_api_are_refused(): void {
		foreach ( array( self::URL . '/batches', 'https://api.anthropic.com/v1/files', self::URL . '?x=1', 'https://api.anthropic.com:8443/v1/messages', 'http://api.anthropic.com/v1/messages', 'https://example.com/v1/messages', 42 ) as $u ) {
			$this->assertContains( 'endpoint', ZDZ_LLM_Guard::problems( $u, self::headers(), self::body(), false, self::pol() ), (string) $u );
		}
	}

	public function test_any_beta_header_is_refused(): void {
		$h = self::headers() + array( 'Anthropic-Beta' => 'anything' );
		$this->assertContains( 'beta header', ZDZ_LLM_Guard::problems( self::URL, $h, self::body(), false, self::pol() ) );
		$this->assertContains( 'beta header', ZDZ_LLM_Guard::problems( self::URL, array( 'anthropic-beta: x' ), self::body(), false, self::pol() ) );
	}

	public function test_fields_outside_the_eligible_set_are_refused_not_stripped(): void {
		$p = ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'mcp_servers' => array(), 'container' => 'x' ) ), false, self::pol() );
		$this->assertContains( 'field mcp_servers', $p );
		$this->assertContains( 'field container', $p );
		$this->assertContains( 'body is not an object', ZDZ_LLM_Guard::problems( self::URL, self::headers(), array( 1, 2 ), false, self::pol() ) );
	}

	public function test_only_a_model_the_slots_name_may_be_sent(): void {
		$this->assertContains( 'model', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'model' => 'some-other-model' ) ), false, self::pol() ) );
		$this->assertContains( 'model', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body(), false, array( 'models' => array(), 'geo' => '' ) ), 'no slots configured: nothing passes' );
		// read from the router's own configuration when no policy is passed
		$GLOBALS['zdz_test_options']['zdz_model_slot_llm_primary'] = 'example-large-1';
		$this->assertNotContains( 'model', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body() ) );
		$this->assertContains( 'model', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'model' => 'example-small-1' ) ) ) );
	}

	public function test_the_region_is_exactly_the_configured_one(): void {
		$this->assertContains( 'inference_geo', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'inference_geo' => 'us' ) ), false, self::pol( '' ) ), 'a region nobody configured' );
		$this->assertContains( 'inference_geo', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body(), false, self::pol( 'eu' ) ), 'a configured region left off' );
		$this->assertContains( 'inference_geo', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'inference_geo' => 'us' ) ), false, self::pol( 'eu' ) ) );
		$this->assertSame( array(), ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'inference_geo' => 'eu' ) ), false, self::pol( 'eu' ) ) );
	}

	public function test_streaming_thinking_and_output_config_shapes(): void {
		$this->assertContains( 'stream', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'stream' => true ) ), false, self::pol() ) );
		$this->assertContains( 'thinking', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'thinking' => array( 'type' => 'enabled', 'budget_tokens' => 9 ) ) ), false, self::pol() ) );
		$this->assertContains( 'output_config', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'output_config' => array( 'effort' => 'medium', 'other' => 1 ) ) ), false, self::pol() ) );
		$this->assertContains( 'effort', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'output_config' => array( 'effort' => 'extreme' ) ) ), false, self::pol() ) );
		$this->assertContains( 'output_config format', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'output_config' => array( 'format' => array( 'type' => 'json_object' ) ) ) ), false, self::pol() ) );
		$ok = self::body( array( 'output_config' => array( 'effort' => 'high', 'format' => array( 'type' => 'json_schema', 'schema' => array( 'type' => 'object' ) ) ) ) );
		$this->assertSame( array(), ZDZ_LLM_Guard::problems( self::URL, self::headers(), $ok, false, self::pol() ) );
	}

	public function test_tools_code_execution_and_dynamic_web_search_are_refused(): void {
		$web = array( 'type' => ZDZ_LLM_Guard::WEB_SEARCH_OK, 'name' => 'web_search', 'max_uses' => 3, 'allowed_callers' => array( 'direct' ) );
		$this->assertContains( 'web search is off for this call', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'tools' => array( $web ) ) ), false, self::pol() ) );
		$this->assertSame( array(), ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'tools' => array( $web ) ) ), true, self::pol() ) );
		$dyn = array( 'allowed_callers' => array( 'code_execution' ) ) + $web;
		$this->assertContains( 'web search callers', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'tools' => array( $dyn ) ) ), true, self::pol() ) );
		$p = ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'tools' => array( array( 'type' => 'code_execution_20250825', 'name' => 'x' ), array( 'type' => 'web_search_20260209', 'name' => 'web_search' ) ) ) ), true, self::pol() );
		$this->assertContains( 'tool type code_execution_20250825', $p );
		$this->assertContains( 'tool type web_search_20260209', $p );
		$this->assertContains( 'tools is not a list', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'tools' => array( 'a' => $web ) ) ), true, self::pol() ) );
	}

	public function test_files_api_sources_and_odd_content_are_refused(): void {
		$file = array( 'role' => 'user', 'content' => array( array( 'type' => 'document', 'source' => array( 'type' => 'file', 'file_id' => 'f1' ) ) ) );
		$p    = ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'messages' => array( $file ) ) ), false, self::pol() );
		$this->assertContains( 'source file', $p );
		$this->assertContains( 'file_id', $p );
		$obj = array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => 'x' ) );
		$this->assertContains( 'message content', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'messages' => array( $obj ) ) ), false, self::pol() ) );
		$this->assertContains( 'system block', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'system' => array( array( 'type' => 'image' ) ) ) ), false, self::pol() ) );
		$this->assertContains( 'messages', ZDZ_LLM_Guard::problems( self::URL, self::headers(), self::body( array( 'messages' => array() ) ), false, self::pol() ) );
	}

	public function test_check_says_what_is_wrong_never_the_content(): void {
		$e = ZDZ_LLM_Guard::check( self::URL . '/batches', self::headers(), self::body( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Riley Example, 555-0142' ) ) ) ), false, self::pol() );
		$this->assertInstanceOf( 'WP_Error', $e );
		$this->assertSame( 'zdz_llm_guard', $e->get_error_code() );
		$this->assertSame( 'guard', $e->get_error_data()['kind'] );
		$this->assertFalse( $e->get_error_data()['billed'] );
		$this->assertStringNotContainsString( 'Riley', $e->get_error_message() );
		$this->assertStringNotContainsString( '555', $e->get_error_message() );
	}

	public function test_the_native_adapters_own_bodies_pass(): void {
		$n = array(
			'model'         => 'example-large-1',
			'system'        => 'Per person.',
			'system_prefix' => 'Same for everyone.',
			'system_tier'   => 'Same at this level.',
			'messages'      => array( array( 'role' => 'user', 'content' => array(
				array( 'type' => 'text', 'text' => 'data', 'cache' => '1h' ),
				array( 'type' => 'image', 'media_type' => 'image/png', 'data' => 'AAAA' ),
				array( 'type' => 'pdf', 'data' => 'AAAA' ),
				array( 'type' => 'text', 'text' => 'question' ),
			) ) ),
			'json_schema'   => array( 'type' => 'object' ),
			'effort'        => 'high',
			'max_output'    => 2000,
			'cache_latest'  => true,
		);
		$this->assertSame( array(), ZDZ_LLM_Guard::problems( self::URL, ZDZ_LLM_Anthropic::headers( 'k' ), ZDZ_LLM_Anthropic::body( $n, '' ), false, self::pol() ) );
		$this->assertSame( array(), ZDZ_LLM_Guard::problems( self::URL, ZDZ_LLM_Anthropic::headers( 'k' ), ZDZ_LLM_Anthropic::body( $n, 'eu' ), false, self::pol( 'eu' ) ) );
		$web = array( 'model' => 'example-large-1', 'system' => '', 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'q' ) ) ) ), 'web' => true, 'max_output' => 500 );
		$this->assertSame( array(), ZDZ_LLM_Guard::problems( self::URL, ZDZ_LLM_Anthropic::headers( 'k' ), ZDZ_LLM_Anthropic::body( $web, '' ), true, self::pol() ) );
		$b = ZDZ_LLM_Anthropic::body( $n, '' );
		$this->assertArrayNotHasKey( 'inference_geo', $b );
		$this->assertCount( 3, $b['system'], 'prefix, tier, then the per-person part' );
		$this->assertSame( '1h', $b['system'][0]['cache_control']['ttl'] );
		$this->assertSame( '1h', $b['system'][1]['cache_control']['ttl'] );
		$this->assertArrayNotHasKey( 'cache_control', $b['system'][2] );
		foreach ( array_keys( ZDZ_LLM_Anthropic::headers( 'k' ) ) as $h ) {
			$this->assertStringNotContainsString( 'beta', strtolower( $h ) );
		}
	}
}
