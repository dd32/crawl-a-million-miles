# Crawl the top 1m domains using PHP

This uses ReactPHP to crawl over the top 1m domains, as from https://tranco-list.eu/.

This code is not in a working state.

TODO:
 - Site categorization and platform detection.
 - Error handling, ReactPHP HTTP Client isn't throwing an error (or success!) for DNS resolution failures.
 - Present stats on the crawl afterwards
 - Allow comparing stats to last run
