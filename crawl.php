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
	echo "$domain returned HTTP " . $response->getStatusCode() . ' and ' . strlen( $response->getBody() ) . " bytes \n";
}

/**
 * The callback for when we timeout on a domain.
 */
function callback_failure( $domain, $exception ) {
	echo "$domain threw an error: " . $exception->getMessage() . "\n";
}

$browser = new Browser(
	new Connector(
		[
			'timeout' => TIMEOUT
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
		return $browser->get( "http://{$domain}/" );
	}
);

// Fill the Queue up every now and then.
$timer = Loop::addPeriodicTimer( 1.0, function () use( &$timer, $que ) {
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

