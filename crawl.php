<?php

namespace dd32\CrawlaMillion;
use Exception;
use Clue\React\Mq\Queue;
use React\Promise\Timer;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Socket\Connector;

const FILE = './top10milliondomains.csv';

const CONCURRENCY = 75;
const QUEUE_SIZE  = CONCURRENCY * 2;
const TIMEOUT     = 30.0;
const MAX_DOMAINS = 1000; // Maximum number to process, set to 0 for all.
const USER_AGENT  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36';

const STAT_PRINT_TIME = 30.0; // How often to print the stats.

$stats = [
	'success' => 0,
	'error'   => 0,
	'code' => [],
	'error-reasons' => [],
	'wp'      => [
		'yes'   => 0,
		'maybe' => 0,
		'no'    => 0,
	],
	'generator' => [],
];

function stat( $stat, $sub = null ) {
	global $stats;
	if ( ! isset( $stats[ $stat ] ) ) {
		$stats[ $stat ] = 0;
	}

	if ( is_null( $sub ) ) {
		if ( is_int( $stats[ $stat ] ) ) {
			$stats[ $stat ]++;
		}
	} else {
		if ( ! is_array( $stats[ $stat ] ) ) {
			$stats[ $stat ] = [
				$sub => 0
			];
		} elseif ( ! isset( $stats[ $stat ][ $sub ] ) ) {
			$stats[ $stat ][ $sub ] = 0;
		}

		$stats[ $stat ][ $sub ]++;
	}
}

function print_stats() {
	global $stats;

	ob_start();

	echo "\n";

	printf(
		"Processed %d sites, success rate of %s\n",
		$stats['success'] + $stats['error'],
		round( ( $stats['success'] / ( $stats['success'] + $stats['error'] ) ) * 100, 1 ) . '%'
	);
	printf(
		"WordPress was seen on %s of successful sites.\n",
		round( ( $stats['wp']['yes'] + $stats['wp']['maybe'] ) / $stats['success'] * 100, 2 ) . '%'
	);

	echo "\n";

	asort( $stats['error-reason'] );
	ksort( $stats['code'] );
	asort( $stats['generator'] );

	print_r( $stats );

	$output = ob_get_flush();

	file_put_contents( '/tmp/scanner/output/' . time() . '.txt', $output );

}

require __DIR__ . '/vendor/autoload.php';

/**
 * Return each of the 1m domains.
 */
function gen_domains() {
	$f = fopen( FILE, 'r' );
	if ( ! $f ) {
		return false;
	}

	$domain = 0;

	try {
		while ( ( $line = fgetcsv( $f ) ) && ( ! MAX_DOMAINS || $domain++ < MAX_DOMAINS ) ) {
			if ( 'Domain' === $line[1] ) continue;
			yield $line[1];
		}
	} finally {
		fclose( $f );
	}

	return false;
}


/**
 * The callback for when we have the HMTL content of a domain.
 */
function callback_success( $domain, $response ) {
	$code = $response->getStatusCode();

	stat( 'success' );
	stat( 'code', $code );

	$body         = $response->getBody();
	$headers      = $response->getHeaders();
	$link_headers = implode( ' ', $headers['Link'] ?? [] );

	if (
		str_contains( $link_headers, '.w.org' ) ||
		str_contains( $link_headers, '/wp-json' ) ||
		str_contains( $link_headers, '?rest_route=' ) ||
		str_contains( $body, '/wp-' ) ||
		str_contains( $body, '.w.org' ) ||
		str_contains( $body, 'WordPress/' ) ||
		str_contains( $body, '/xmlrpc.php' )
	) {
		stat( 'wp', 'yes' );
	} elseif (
		// Probably..
		str_contains( $body, '?rest_route=' ) ||
		str_contains( $body, '.wp.com/' )
	) {
		stat( 'wp', 'maybe' );
	} else {
		stat( 'wp', 'no' );
	}

	if ( preg_match( '/<meta[^>]+generator[^>]+>/i', $body, $m ) && preg_match( '/content=(["\'])([^"\']+)\\1/i', $m[0], $n ) ) {
		// Strip versions..
		$generator = preg_replace( '/^(.+?)\s*[0-9-].*$/', '$1', $n[2] );

		stat( 'generator', strtolower( $generator ) );
	} else {
		stat( 'generator', 'none' );
	}

	echo "$domain returned HTTP " . $code . ' and ' . strlen( $response->getBody() ) . " bytes \n";
}

/**
 * The callback for when we timeout on a domain.
 */
function callback_failure( $domain, Exception $e ) {
	stat('error');

	$message = $e->getMessage();

	// (error code)
	if ( preg_match( '!\(([^)]+)\)[^)]*$!', $message, $m ) ) {
		stat( 'error-reason', $m[1] );
	}

	// HTTP Status error
	if ( preg_match( '!HTTP status code (\d+)!i', $message, $m ) ) {
		stat( 'code', $m[1] );
	}

	echo "$domain threw an error: " . $message . "\n";
}

$browser = new Browser(
	new Connector(
		[
			'timeout' => TIMEOUT,
			'tls'     => [
				'verify_peer'      => false,
				'verify_peer_name' => false
			]
		]
	)
);

// Timeout after..
$browser->withTimeout( TIMEOUT );

// We will handle 4xx and 5xx errors.
$browser->withRejectErrorResponse( false );

// Create a queue, and have it process these domains.
$que = new Queue(
	CONCURRENCY,
	QUEUE_SIZE,
	function( $domain ) use( $browser ) {
		return $browser->get(
			"http://{$domain}/",
			[
				'User-Agent' => USER_AGENT
			]
		);
	}
);

// Fill the Queue up every now and then.
$filler_timer = Loop::addPeriodicTimer( 5.0, function( $timer ) use( $que ) {
	// Setup the domains..
	static $domains = false;
	if ( ! $domains ) {
		$domains = gen_domains();
	}

	echo "Queue Size: " . $que->count() . "\n";

	while ( $que->count() < QUEUE_SIZE ) {
		$domain = $domains->current();
		if ( ! $domain ) {
			// Cancel once there's nothing still running.
			if ( ! $que->count() ) {
				Loop::cancelTimer( $timer );
			}
			return;
		}
		$domains->next();

		$que( $domain )->then(
			function( $response ) use( $domain ) {
				return callback_success( $domain, $response );
			},
			function ( $exception ) use( $domain ) {
				return callback_failure( $domain, $exception );
			}
		);
	}
} );

// Print out the status occasionally..
Loop::addPeriodicTimer( STAT_PRINT_TIME, function( $timer ) use ( $que ) {
	if ( ! $que->count() ) {
		Loop::cancelTimer( $timer );
	}

	// Red for visibility.
	echo "\e[0;31m";
	print_stats();
	echo "\e[0m\n";
} );

Loop::addSignal( SIGINT, $signal_function = function( int $signal ) use( $filler_timer, $que ) {
	if ( defined( '___KILLIT' ) ) {
		print_stats();
		exit( "\nCaught angry user interrupt signal.. killing..\n\n" );
	}

	echo "\nCaught user interrupt signal.. gracefully shutting down.. " . $que->count() . " items still to process.. \n";
	Loop::cancelTimer( $filler_timer );

	define( '___KILLIT', true );
} );
Loop::addPeriodicTimer( 10.0, function( $timer ) use ( $signal_function, $que ) {
	if ( $que->count() ) {
		return;
	}

	echo "Removing Signal catcher..";
	Loop::removeSignal( SIGINT, $signal_function );
	Loop::cancelTimer( $timer );

} );

Loop::run();

print_stats();
