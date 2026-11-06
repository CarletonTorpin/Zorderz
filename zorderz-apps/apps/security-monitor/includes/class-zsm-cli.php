<?php
/**
 * ZSM_CLI — `wp zsm …`. WP-CLI runs with no request wall, so it can finish a feed sync or a code
 * walk in one go, and it is the place to import a review report made elsewhere. Registered only
 * when WP_CLI is defined. Never prints the feed key.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

class ZSM_CLI {

	/**
	 * Run a full scan (updates, drift, supply chain, feed match) and print the counts.
	 *
	 * [--digest]
	 * : Also send the digest if something changed.
	 */
	public function scan( $args, $assoc ) {
		$r = ZSM::run_scan();
		WP_CLI::log( sprintf( 'Scanned %d components.', (int) $r['scanned'] ) );
		$this->print_counts();
		if ( isset( $assoc['digest'] ) ) {
			$n = ZSM_Report::maybe_send_digest( $r );
			WP_CLI::log( $n ? "Digest sent to {$n} recipient(s)." : 'Digest evaluated: nothing new to send.' );
		}
		WP_CLI::success( 'Scan complete.' );
	}

	/**
	 * Download and read the feed to the end, in the same time-boxed steps the background job
	 * uses, printing each step and the measurements. Keeps to the provider's one download per
	 * 30 minutes. Does nothing when the feed is not connected.
	 *
	 * ## OPTIONS
	 *
	 * [--slice=<seconds>]
	 * : Time box per step. Default: sized for this host.
	 *
	 * [--scan]
	 * : Scan again when the sync finishes.
	 */
	public function sync( $args, $assoc ) {
		$slice = isset( $assoc['slice'] ) ? max( 2.0, (float) $assoc['slice'] ) : (float) ZSM::budget()['slice'];
		$n     = 0;
		$r     = ZSM_Feed::run_all(
			$slice,
			function ( $step ) use ( &$n ) {
				$n++;
				WP_CLI::log(
					sprintf(
						'  step %3d  %-11s %5.1f%%  rows=%-6d mem=%.0f MB  %s',
						$n,
						$step['status'],
						100 * (float) $step['progress'],
						(int) $step['rows'],
						memory_get_peak_usage( true ) / 1048576,
						$step['message']
					)
				);
			}
		);
		if ( 'done' === $r['status'] ) {
			$m = ZSM_Feed::metrics();
			WP_CLI::log(
				sprintf(
					'  measured: %s bytes, download %.1f s, %d steps, read %.1f s (longest step %.1f s), peak %.0f MB of %s',
					number_format( (int) $m['bytes'] ),
					(float) $m['download_s'],
					(int) $m['slices'],
					(float) $m['parse_s'],
					(float) $m['max_slice_s'],
					(float) $m['peak_mb'],
					(string) $m['memory_limit']
				)
			);
			if ( isset( $assoc['scan'] ) ) {
				ZSM::run_scan();
				$this->print_counts();
			}
			WP_CLI::success( $r['message'] );
			return;
		}
		if ( 'failed' === $r['status'] ) {
			WP_CLI::error( 'Feed sync failed: ' . $r['message'] . ' (the last good copy is kept).', false );
			return;
		}
		WP_CLI::warning( $r['message'] );
	}

	/**
	 * Read your own code and update the code findings, in the same steps as the daily job.
	 *
	 * [--slice=<seconds>]
	 * : Time box per step. Default: sized for this host.
	 */
	public function codescan( $args, $assoc ) {
		$slice = isset( $assoc['slice'] ) ? max( 2.0, (float) $assoc['slice'] ) : (float) ZSM::budget()['slice'];
		$t0    = microtime( true );
		do {
			$r = ZSM_Codescan::step( $slice );
			WP_CLI::log( '  ' . $r['message'] );
		} while ( 'scanning' === $r['status'] );
		WP_CLI::log( sprintf( '  %.1f s, peak memory %.0f MB', microtime( true ) - $t0, memory_get_peak_usage( true ) / 1048576 ) );
		ZSM::run_scan();
		$this->print_counts();
		WP_CLI::success( $r['message'] );
	}

	/** Send the digest now to every recipient, whether or not anything changed. */
	public function digest() {
		$n = ZSM_Report::maybe_send_digest( ZSM::run_scan(), true );
		if ( $n > 0 ) {
			WP_CLI::success( sprintf( 'Digest sent to %d recipient(s).', $n ) );
			return;
		}
		WP_CLI::error( 'The digest reached no one.' );
	}

	/** Armed state, recipients, feed connection and freshness, measurements and counts. Never prints the key. */
	public function status() {
		$job = ZSM_Feed::job();
		$m   = ZSM_Feed::metrics();
		$cs  = ZSM_Codescan::last();
		$b   = ZSM::budget();
		WP_CLI::log( 'Zorderz Security Monitor ' . ZSM::VERSION );
		WP_CLI::log( '  armed:          ' . ( ZSM::enabled() ? 'yes' : 'no' ) );
		WP_CLI::log(
			'  recipients:     ' . implode(
				', ',
				array_map(
					function ( $id ) {
						$u = get_userdata( $id );
						return $u ? $u->user_login : (string) $id;
					},
					ZSM::recipient_ids()
				)
			)
		);
		WP_CLI::log( '  baseline:       ' . ( ZSM::baseline_follows() ? 'follows deploys' : 'manual' ) . ', ' . count( ZSM_Currency::baseline() ) . ' components' );
		WP_CLI::log( '  code check:     ' . ( empty( $cs['at'] ) ? 'never' : sprintf( '%d files, %d findings, %s ago', (int) $cs['files'], (int) $cs['findings'], human_time_diff( (int) $cs['at'] ) ) ) );
		WP_CLI::log( '  feed:           ' . ( ZSM::has_feed_key() ? 'connected (key set, sealed)' : 'not connected (no key)' ) . ( ZSM::key_managed_elsewhere() ? ', managed on Zorderz → Credentials' : '' ) );
		WP_CLI::log( '  feed address:   ' . ZSM_Feed::variant() . '  ' . ZSM_Feed::url() );
		WP_CLI::log( '  freshness:      ' . ZSM_Feed::freshness_line() );
		WP_CLI::log( sprintf( '  host budget:    %d s wall, %.0f s steps, %d s download', (int) $b['wall'], (float) $b['slice'], (int) $b['download_timeout'] ) );
		if ( ZSM_Feed::backoff_until() > time() ) {
			WP_CLI::log( '  paused until:   ' . gmdate( 'Y-m-d H:i', ZSM_Feed::backoff_until() ) . ' UTC' );
		}
		if ( $job ) {
			WP_CLI::log( sprintf( '  job:            %d%% read, %d records, %d steps', floor( 100 * (int) $job['offset'] / max( 1, (int) $job['bytes'] ) ), (int) $job['records'], (int) $job['slices'] ) );
		}
		if ( $m ) {
			WP_CLI::log( '  last metrics:   ' . wp_json_encode( $m ) );
		}
		$this->print_counts();
	}

	/**
	 * Throw away an in-progress feed job (its file and shadow table). The live cache is untouched.
	 *
	 * @subcommand abort-sync
	 */
	public function abort_sync() {
		if ( ! ZSM_Feed::abort( 'wp zsm abort-sync' ) ) {
			WP_CLI::warning( 'A feed sync is running right now; try again when it finishes.' );
			return;
		}
		WP_CLI::success( 'Feed job cleared.' );
	}

	/** Record today's versions of your own components as the expected ones. */
	public function baseline() {
		$n = ZSM_Currency::set_baseline( ZSM_Inventory::collect() );
		WP_CLI::success( sprintf( 'Baseline recorded for %d of your own components.', $n ) );
	}

	/**
	 * Import a Plugin Check or Semgrep JSON report about your own code.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON report.
	 *
	 * @subcommand import-code
	 */
	public function import_code( $args ) {
		$n = ZSM_Code::import_file( (string) ( $args[0] ?? '' ) );
		if ( $n < 0 ) {
			WP_CLI::error( 'Could not read that report.' );
		}
		WP_CLI::success( sprintf( 'Imported %d code finding(s).', $n ) );
	}

	/**
	 * List the open findings.
	 *
	 * @subcommand list-findings
	 */
	public function list_findings() {
		$rows = ZSM_Findings::list( 'active' );
		if ( empty( $rows ) ) {
			WP_CLI::success( 'No open findings in scope.' );
			return;
		}
		$out = array();
		foreach ( $rows as $f ) {
			$out[] = array(
				'sev'       => $f['severity'],
				'kind'      => $f['kind'],
				'plane'     => $f['plane'],
				'component' => $f['slug'],
				'installed' => $f['installed_version'],
				'fix'       => $f['fixed_version'],
				'cve'       => $f['cve'],
				'status'    => $f['status'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $out, array( 'sev', 'kind', 'plane', 'component', 'installed', 'fix', 'cve', 'status' ) );
	}

	private function print_counts() {
		$c = ZSM_Findings::counts();
		WP_CLI::log( sprintf( '  critical=%d high=%d medium=%d low=%d total=%d', $c['critical'], $c['high'], $c['medium'], $c['low'], $c['total'] ) );
	}
}

WP_CLI::add_command( 'zsm', 'ZSM_CLI' );
