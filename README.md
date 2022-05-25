# Crawl the top 1m domains using PHP

This uses ReactPHP to crawl over the top 1m domains, as from https://tranco-list.eu/.

This code is not in a working state.

TODO:
 - Site categorization and platform detection.
 - Present stats on the crawl afterwards
 - Allow comparing stats to last run
 - Switch to a Streaming interface, so that we can close the request when we have enough data to categorize on. https://github.com/reactphp/http#streaming-response
