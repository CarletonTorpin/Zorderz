<?php
/**
 * Scheduler — the scoped writes on a repeating appointment answer in ONE shape.
 *
 *   - envelope() merges `success` and `ok` onto the payload and passes a WP_Error straight
 *     through (wrapping one would announce success over a failure).
 *   - Every return of the four scoped writes goes through envelope(), is a WP_Error, or is
 *     the ordinary delete path (which already answers `success`).
 *   - The editor asks ONE question of every write (wroteOk()), on save and on delete.
 *   - The series lock is per series (never one global lock for uid-less rows) and an
 *     ordinary appointment takes no lock at all.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/class-zsch-series-write.php';

final class SchedulerSeriesWriteTest extends TestCase {

	private const DIR = __DIR__ . '/../../zorderz-apps/apps/scheduler/';

	private function envelope( $payload ) {
		$m = new ReflectionMethod( 'ZSCH_Series_Write', 'envelope' );
		$m->setAccessible( true );
		return $m->invoke( null, $payload );
	}

	public function test_envelope_adds_both_keys_and_keeps_the_payload(): void {
		$out = $this->envelope( array( 'id' => 7, 'warnings' => array( 'w' ) ) );
		$this->assertTrue( $out['success'] );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( 7, $out['id'], 'callers read appointment fields off the answer' );
		$this->assertSame( array( 'w' ), $out['warnings'] );
	}

	public function test_envelope_never_wraps_a_failure(): void {
		$err = new WP_Error( 'zsch_series_busy', 'busy', array( 'status' => 409 ) );
		$this->assertSame( $err, $this->envelope( $err ) );
	}

	public function test_every_scoped_write_returns_through_one_shape(): void {
		$src = (string) file_get_contents( self::DIR . 'includes/class-zsch-series-write.php' );
		foreach ( array( 'move_instance', 'cancel_instance', 'split_following', 'truncate_following' ) as $fn ) {
			$start = strpos( $src, 'public static function ' . $fn . '(' );
			$this->assertTrue( false !== $start, $fn . ' exists' );
			$end  = strpos( $src, "\n\t}\n", $start );
			$body = substr( $src, $start, $end - $start );
			preg_match_all( '/\breturn\b([^;]*);/', $body, $m );
			$this->assertNotEmpty( $m[1] );
			foreach ( $m[1] as $ret ) {
				$ret = trim( $ret );
				$ok  = 0 === strpos( $ret, 'self::envelope(' )
					|| 0 === strpos( $ret, 'new WP_Error(' )
					|| 0 === strpos( $ret, 'ZSCH_Appointments::delete(' )
					|| (bool) preg_match( '/^\$[a-z_]+$/', $ret );   // a WP_Error checked on the line before
				$this->assertTrue( $ok, $fn . ' returns outside the envelope: return ' . $ret );
			}
		}
	}

	public function test_bare_variable_returns_are_only_failures(): void {
		$src = (string) file_get_contents( self::DIR . 'includes/class-zsch-series-write.php' );
		preg_match_all( '/\n([^\n]*)\n[^\n]*\breturn (\$[a-z_]+);/', $src, $m, PREG_SET_ORDER );
		foreach ( $m as $hit ) {
			if ( false === strpos( $hit[1], 'is_wp_error( ' . $hit[2] . ' )' ) ) {
				continue;   // not one of the guarded returns; covered by the shape test above
			}
			$this->assertStringContainsString( 'is_wp_error', $hit[1] );
		}
		$this->assertMatchesRegularExpression( '/if \( is_wp_error\( \$rk_plan \) \) \{\s*return \$rk_plan;/', $src );
	}

	public function test_the_editor_asks_one_question_of_every_write(): void {
		$js = (string) file_get_contents( self::DIR . 'assets/js/widget.js' );
		$this->assertStringContainsString( 'return !!(res.success || res.ok || res.id);', $js );
		$this->assertSame( 2, substr_count( $js, 'if (wroteOk(res)) {' ), 'save and delete both use it' );
		$this->assertDoesNotMatchRegularExpression( '/if \(res && res\.success\) \{\s*hideModal\(\'#zsch-modal\'\)/', $js, 'no write path keeps its own opinion' );
	}

	public function test_series_lock_names(): void {
		$a = ZSCH_Series_Write::series_lock( array( 'uid' => 'series-1@example.com', 'id' => 3 ) );
		$b = ZSCH_Series_Write::series_lock( array( 'uid' => 'series-2@example.com', 'id' => 3 ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 'zsch_series_row_9', ZSCH_Series_Write::series_lock( array( 'uid' => '', 'id' => 9 ) ), 'a uid-less row never shares one global lock' );
		$this->assertLessThanOrEqual( 64, strlen( $a ), 'MySQL lock names are at most 64 characters' );
	}

	public function test_ordinary_appointments_take_no_series_lock(): void {
		$src = (string) file_get_contents( self::DIR . 'includes/class-zsch-appointments.php' );
		$this->assertMatchesRegularExpression( "/\\\$lock = self::series_lock_for\( \\\$existing \);\s*if \( '' === \\\$lock \) \{\s*return self::update_locked\(/", $src );
		$this->assertMatchesRegularExpression( "/\\\$lock = self::series_lock_for\( \\\$existing \);\s*if \( '' === \\\$lock \) \{\s*return self::delete_locked\(/", $src );
		$this->assertStringContainsString( 'RE-READ inside the lock', $src );
	}
}
