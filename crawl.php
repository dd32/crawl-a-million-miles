<?php

namespace dd32\CrawlaMillion;
use Exception;
use Clue\React\Mq\Queue;
use React\Promise\Timer;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Socket\Connector;

const FILE = './top-1m.csv';

const CONCURRENCY = 5;
const QUEUE_SIZE  = CONCURRENCY * 10;
const TIMEOUT     = 5.0;
const MAX_DOMAINS = 100; // Maximum number to process, set to 0 for all.
const USER_AGENT  = 'dd32-CrawlaMillion/1.0; https://github.com/dd32/crawl-a-million-miles';

$stats = [
	'success' => 0,
	'error'   => 0,
	'wp'      => [
		'yes'   => 0,
		'maybe' => 0,
		'no'    => 0,
	]
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
		str_contains( $link_headers, 'https://api.w.org/' ) ||
		str_contains( $link_headers, '/wp-json' ) ||
		str_contains( $link_headers, '?rest_route=' ) ||
		str_contains( $body, '/wp-' ) ||
		str_contains( $body, 'WordPress/' ) ||
		str_contains( $body, '/xmlrpc.php">' )
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

	echo "$domain returned HTTP " . $code . ' and ' . strlen( $response->getBody() ) . " bytes \n";
}

/**
 * The callback for when we timeout on a domain.
 */
function callback_failure( $domain, Exception $e ) {
	stat('error');

	$message = $e->getMessage();
	$code    = '';
	if ( preg_match( '!\(([^)]+)\)[^)]*$!', $message, $m ) ) {
		$code = $m[1];
	}

	if ( $code ) {
		stat( 'error-reason', $code );
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
Loop::addPeriodicTimer( 1.0, function( $timer ) use( $que ) {
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

Loop::addSignal( SIGINT, function( int $signal ) {
    echo "\nCaught user interrupt signal\n";
	Loop::stop();
} );

Loop::run();

print_r( $stats );

printf(
	"Processed %d sites, success rate of %s\n",
	$stats['success'] + $stats['error'],
	round( ( $stats['success'] / ( $stats['success'] + $stats['error'] ) ) * 100, 1 ) . '%'
);
printf(
	"WordPress was seen on %s of successful sites.\n",
	round( ( $stats['wp']['yes'] + $stats['wp']['maybe'] ) / $stats['success'] * 100, 2 ) . '%'
);
