<?php

namespace dd32\CrawlaMillion;

use Clue\React\Mq\Queue;
use React\Promise\Timer;
use React\EventLoop\Loop;
// use React\Http\Browser;

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

$domains = gen_domains();
$domains->rewind();

$browser = new Browser();

// A Handler to process each domain.
$domain_handler_timeout = function( $domain ) use( $browser ) {
	return Timer\timeout( $browser->get( "https://{$domain}/" ), TIMEOUT );
};

// Create a queue, and have it process these domains.
$que = new Queue( CONCURRENCY, QUEUE_SIZE, $domain_handler_timeout );

// Fill the Queue up every now and then.
$timer = null; // So it's availble to the function..
$timer = Loop::addPeriodicTimer( 2.0, function () use( &$timer, $que, $domains ) {
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
				echo "$domain returned " . strlen( $response->getBody() ) . " bytes \n";
			},
			function ( Exception $error ) use( $domain ) {
				echo "$domain threw an error: " . $error->getMessage() . "\n";
			}
		);
	}
} );

