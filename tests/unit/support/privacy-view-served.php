<?php
/**
 * A local web server for the Privacy View gate's served tests.
 *
 * The gate reads what PHP itself makes of a request: $_GET, $_POST, $_FILES, $_COOKIE, the address, and the body's
 * own text (php://input). A test that hands the gate those values by hand cannot show how PHP really fills them. So
 * the served tests start PHP's own web server on this machine (127.0.0.1 only, a free port, stopped at the end), and
 * send it real requests, each written by hand on a socket so a test sends exactly the bytes it means to.
 *
 * Where a local server cannot be started the tests say so and skip the served part.
 *
 * @package Zorderz\Tests
 */

final class ZDZ_Test_Kpv_Server {

	/** @var int */
	public $port = 0;
	/** @var string The server's PHP error log. */
	public $log = '';
	/** @var resource|null */
	private $proc = null;

	/**
	 * Starts the server with $router answering every request. $ini: php.ini values for it (name => value). $docroot:
	 * its document root ('' for the router's folder). Returns the server, or null when none could be started.
	 */
	public static function start( $router, array $ini = array(), $docroot = '' ) {
		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'stream_socket_server' ) ) {
			return null;
		}
		$me = new self();
		for ( $try = 0; $try < 20 && ! $me->port; $try++ ) {
			$p = random_int( 20000, 60000 );
			$s = @stream_socket_server( "tcp://127.0.0.1:$p" );
			if ( $s ) {
				fclose( $s );
				$me->port = $p;
			}
		}
		if ( ! $me->port ) {
			return null;
		}
		$me->log = (string) tempnam( sys_get_temp_dir(), 'zdzkpvlog' );
		$cmd     = array( PHP_BINARY, '-d', 'error_log=' . $me->log, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_reporting=-1' );
		foreach ( $ini as $k => $v ) {
			$cmd[] = '-d';
			$cmd[] = $k . '=' . $v;
		}
		array_push( $cmd, '-S', '127.0.0.1:' . $me->port, '-t', '' !== $docroot ? $docroot : dirname( $router ), $router );
		$spec = array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) );
		$proc = @proc_open( $cmd, $spec, $pipes ); // sentinel:allow an argument list (no shell is involved), all fixed here: this machine's own PHP as a web server on 127.0.0.1 for a test
		if ( ! is_resource( $proc ) ) {
			@unlink( $me->log );
			return null;
		}
		$me->proc = $proc;
		for ( $i = 0; $i < 60; $i++ ) {
			usleep( 100000 );
			$r = $me->send( 'GET', '/' );
			if ( is_array( $r ) && $r['status'] > 0 ) {
				return $me;   // it answers (whatever it says)
			}
		}
		$me->stop();
		return null;
	}

	/**
	 * One real request. $body: its bytes (null for none). A Content-Length is added unless $headers gives a
	 * Transfer-Encoding or its own. Returns [ status, head (the reply's header lines), text (its body), json (the body
	 * decoded, or null) ], or null when the server did not answer.
	 */
	public function send( $method, $uri, $body = null, array $headers = array() ) {
		$fp = @stream_socket_client( 'tcp://127.0.0.1:' . $this->port, $errno, $errstr, 3 );
		if ( ! $fp ) {
			return null;
		}
		$req = "$method $uri HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
		foreach ( $headers as $k => $v ) {
			$req .= "$k: $v\r\n";
		}
		if ( null !== $body && ! isset( $headers['Transfer-Encoding'] ) && ! isset( $headers['Content-Length'] ) ) {
			$req .= 'Content-Length: ' . strlen( $body ) . "\r\n";
		}
		fwrite( $fp, $req . "\r\n" . (string) $body );
		stream_set_timeout( $fp, 10 );
		$out = (string) stream_get_contents( $fp );
		fclose( $fp );
		$at   = strpos( $out, "\r\n\r\n" );
		$text = false === $at ? '' : (string) substr( $out, $at + 4 );
		$json = json_decode( $text, true );
		return array(
			'status' => preg_match( '#^HTTP/1\.[01] (\d{3})#', $out, $m ) ? (int) $m[1] : 0,
			'head'   => false === $at ? $out : substr( $out, 0, $at ),
			'text'   => $text,
			'json'   => is_array( $json ) ? $json : null,
		);
	}

	/** The lines the server's PHP wrote to its error log (warnings, notices, errors, and the code's own log lines). */
	public function log_lines() {
		$text = '' !== $this->log && is_readable( $this->log ) ? (string) file_get_contents( $this->log ) : '';
		return array_values( array_filter( explode( "\n", $text ), function ( $l ) {
			return '' !== trim( $l );
		} ) );
	}

	public function stop() {
		if ( is_resource( $this->proc ) ) {
			proc_terminate( $this->proc );
			proc_close( $this->proc );
		}
		$this->proc = null;
		if ( '' !== $this->log ) {
			@unlink( $this->log );
		}
	}

	/** Removes a folder a test made, links and all (a link is removed, never followed). */
	public static function remove_tree( $dir ) {
		foreach ( (array) @scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f || ! is_string( $f ) ) {
				continue;
			}
			$p = $dir . '/' . $f;
			if ( is_link( $p ) || ! is_dir( $p ) ) {
				@unlink( $p );
			} else {
				self::remove_tree( $p );
			}
		}
		@rmdir( $dir );
	}
}
