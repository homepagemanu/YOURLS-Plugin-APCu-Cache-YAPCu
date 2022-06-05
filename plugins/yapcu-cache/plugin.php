<?php
/*
Plugin Name: APCu Cache (YAPuCache)
Plugin URI: https://github.com/tipichris/YAPCache
Description: An APCu based cache to reduce database load.
Version: 1.0
Author: Ian Barber, Chris Hastie, Manuel Freund
Author URI: http://freund.fm/
*/

// Verify APC is installed, suggested by @ozh
if( !function_exists( 'apcu_exists' ) ) {
   yourls_die( 'This plugin requires the APCu extension: https://pecl.php.net/package/APCu' );
}

// keys for APC storage
if(!defined('YAPCU_ID')) {
	define('YAPCU_ID', 'yapcache-');
}
define('YAPCU_LOG_INDEX', YAPCU_ID . 'log_index');
define('YAPCU_LOG_TIMER', YAPCU_ID . 'log_timer');
define('YAPCU_LOG_UPDATE_LOCK', YAPCU_ID . 'log_update_lock');
define('YAPCU_CLICK_INDEX', YAPCU_ID . 'click_index');
define('YAPCU_CLICK_TIMER', YAPCU_ID . 'click_timer');
define('YAPCU_CLICK_KEY_PREFIX', YAPCU_ID . 'clicks-');
define('YAPCU_CLICK_UPDATE_LOCK', YAPCU_ID . 'click_update_lock');
define('YAPCU_KEYWORD_PREFIX', YAPCU_ID . 'keyword-');
define('YAPCU_ALL_OPTIONS', YAPCU_ID . 'get_all_options');
define('YAPCU_YOURLS_INSTALLED', YAPCU_ID . 'yourls_installed');
define('YAPCU_BACKOFF_KEY', YAPCU_ID . 'backoff');
define('YAPCU_CLICK_INDEX_LOCK', YAPCU_ID . 'click_index_lock');

// configurable options
if(!defined('YAPCU_WRITE_CACHE_TIMEOUT')) {
	define('YAPCU_WRITE_CACHE_TIMEOUT', 120);
}
if(!defined('YAPCU_READ_CACHE_TIMEOUT')) {
	define('YAPCU_READ_CACHE_TIMEOUT', 3600);
}
if(!defined('YAPCU_LONG_TIMEOUT')) {
	define('YAPCU_LONG_TIMEOUT', 86400);
}
if(!defined('YAPCU_MAX_LOAD')) {
	define('YAPCU_MAX_LOAD', 0.7);
}
if(!defined('YAPCU_MAX_UPDATES')) {
	define('YAPCU_MAX_UPDATES', 200);
}
if(!defined('YAPCU_MAX_CLICKS')) {
	define('YAPCU_MAX_CLICKS', 30);
}
if(!defined('YAPCU_BACKOFF_TIME')) {
	define('YAPCU_BACKOFF_TIME', 30);
}
if(!defined('YAPCU_WRITE_CACHE_HARD_TIMEOUT')) {
	define('YAPCU_WRITE_CACHE_HARD_TIMEOUT', 600);
}
if(!defined('YAPCU_LOCK_TIMEOUT')) {
	define('YAPCU_LOCK_TIMEOUT', 30);
}
if(!defined('YAPCU_API_USER')) {
	define('YAPCU_API_USER', '');
}

yourls_add_action( 'pre_get_keyword', 'yapcu_pre_get_keyword' );
yourls_add_filter( 'get_keyword_infos', 'yapcu_get_keyword_infos' );
if(!defined('YAPCU_SKIP_CLICKTRACK')) {
	yourls_add_filter( 'shunt_update_clicks', 'yapcu_shunt_update_clicks' );
	yourls_add_filter( 'shunt_log_redirect', 'yapcu_shunt_log_redirect' );
}
if(defined('YAPCU_REDIRECT_FIRST') && YAPCU_REDIRECT_FIRST) {
	// set a very low priority to ensure any other plugins hooking here run first,
	// as we die at the end of yapcu_redirect_shorturl
	yourls_add_action( 'redirect_shorturl', 'yapcu_redirect_shorturl', 999);
}
yourls_add_filter( 'shunt_all_options', 'yapcu_shunt_all_options' );
yourls_add_filter( 'get_all_options', 'yapcu_get_all_options' );
yourls_add_action( 'add_option', 'yapcu_option_change' );
yourls_add_action( 'delete_option', 'yapcu_option_change' );
yourls_add_action( 'update_option', 'yapcu_option_change' );
yourls_add_filter( 'edit_link', 'yapcu_edit_link' );
yourls_add_filter( 'api_actions', 'yapcu_api_filter' );

/**
 * Return cached options is available
 *
 * @param bool $false
 * @return bool true
 */
function yapcu_shunt_all_options($false) {
	global $ydb;

	$key = YAPCU_ALL_OPTIONS;
	if(apcu_exists($key)) {
		$ydb->option = apcu_fetch($key);
		$ydb->installed = apcu_fetch(YAPCU_YOURLS_INSTALLED);
		return true;
	}

	return false;
}

/**
 * Cache all_options data.
 *
 * @param array $options
 * @return array options
 */
function yapcu_get_all_options($option) {
	apcu_store(YAPCU_ALL_OPTIONS, $option, YAPCU_READ_CACHE_TIMEOUT);
	// Set timeout on installed property twice as long as the options as otherwise there could be a split second gap
	apcu_store(YAPCU_YOURLS_INSTALLED, true, (2 * YAPCU_READ_CACHE_TIMEOUT));
	return $option;
}

/**
 * Clear the options cache if an option is altered
 * This covers changes to plugins too
 *
 * @param string $plugin
 */
function yapcu_option_change($args) {
	apcu_delete(YAPCU_ALL_OPTIONS);
}

/**
 * If the URL data is in the cache, stick it back into the global DB object.
 *
 * @param string $args
 */
function yapcu_pre_get_keyword($args) {
	global $ydb;
	$keyword = $args[0];
	$use_cache = isset($args[1]) ? $args[1] : true;

	// Lookup in cache
	if($use_cache && apcu_exists(yapcu_get_keyword_key($keyword))) {
		$ydb->infos[$keyword] = apcu_fetch(yapcu_get_keyword_key($keyword));
	}
}

/**
 * Store the keyword info in the cache
 *
 * @param array $info
 * @param string $keyword
 */
function yapcu_get_keyword_infos($info, $keyword) {
	// Store in cache
	apcu_store(yapcu_get_keyword_key($keyword), $info, YAPCU_READ_CACHE_TIMEOUT);
	return $info;
}

/**
 * Delete a cache entry for a keyword if that keyword is edited.
 *
 * @param array $return
 * @param string $url
 * @param string $keyword
 * @param string $newkeyword
 * @param string $title
 * @param bool $new_url_already_there
 * @param bool $keyword_is_ok
 */
function yapcu_edit_link( $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok ) {
	if($return['status'] != 'fail') {
		apcu_delete(yapcu_get_keyword_key($keyword));
	}
	return $return;
}

/**
 * Update the number of clicks in a performant manner.  This manner of storing does
 * mean we are pretty much guaranteed to lose a few clicks.
 *
 * @param string $keyword
 */
function yapcu_shunt_update_clicks($false, $keyword) {

	// initalize the timer.
	if(!apcu_exists(YAPCU_CLICK_TIMER)) {
		apcu_add(YAPCU_CLICK_TIMER, time());
	}

	if(defined('YAPCU_STATS_SHUNT')) {
		if(YAPCU_STATS_SHUNT == "drop") {
			return true;
		} else if(YAPCU_STATS_SHUNT == "none"){
			return false;
		}
	}

	$keyword = yourls_sanitize_string( $keyword );
	$key = YAPCU_CLICK_KEY_PREFIX . $keyword;

	// Store in cache
	$added = false;
	$clicks = 1;
	if(!apcu_exists($key)) {
		$added = apcu_add($key, $clicks);
	}
	if(!$added) {
		$clicks = yapcu_key_increment($key);
	}

	/* we need to keep a record of which keywords we have
	 * data cached for. We do this in an associative array
	 * stored at YAPCU_CLICK_INDEX, with keyword as the keyword
	 */
	$idxkey = YAPCU_CLICK_INDEX;
	yapcu_lock_click_index();
	if(apcu_exists($idxkey)) {
		$clickindex = apcu_fetch($idxkey);
	} else {
		$clickindex = array();
	}
	$clickindex[$keyword] = 1;
	apcu_store ( $idxkey, $clickindex);
	yapcu_unlock_click_index();

	if(yapcu_write_needed('click', $clicks)) {
		yapcu_write_clicks();
	}

	return true;
}

/**
 * write any cached clicks out to the database
 */
function yapcu_write_clicks() {
	global $ydb;
	yapcu_debug("write_clicks: Writing clicks to database");
	$updates = 0;
	// set up a lock so that another hit doesn't start writing too
	if(!apcu_add(YAPCU_CLICK_UPDATE_LOCK, 1, YAPCU_LOCK_TIMEOUT)) {
		yapcu_debug("write_clicks: Could not lock the click index. Abandoning write", true);
		return $updates;
	}

	if(apcu_exists(YAPCU_CLICK_INDEX)) {
		yapcu_lock_click_index();
		$clickindex = apcu_fetch(YAPCU_CLICK_INDEX);
		if($clickindex === false || !apcu_delete(YAPCU_CLICK_INDEX)) {
			// if apcu_delete fails it's because the key went away. We probably have a race condition
			yapcu_unlock_click_index();
			yapcu_debug("write_clicks: Index key disappeared. Abandoning write", true);
			apcu_store(YAPCU_CLICK_TIMER, time());
			return $updates;
		}
		yapcu_unlock_click_index();

		/* as long as the tables support transactions, it's much faster to wrap all the updates
		* up into a single transaction. Reduces the overhead of starting a transaction for each
		* query. The down side is that if one query errors we'll loose the log
		*/
		$ydb->query("START TRANSACTION");
		foreach ($clickindex as $keyword => $z) {
			$key = YAPCU_CLICK_KEY_PREFIX . $keyword;
			$value = 0;
			if(!apcu_exists($key)) {
				yapcu_debug("write_clicks: Click key $key dissappeared. Possible data loss!", true);
				continue;
			}
			$value += yapcu_key_zero($key);
			yapcu_debug("write_clicks: Adding $value clicks for $keyword");
			// Write value to DB
			$ydb->query("UPDATE `" .
							YOURLS_DB_TABLE_URL.
						"` SET `clicks` = clicks + " . $value .
						" WHERE `keyword` = '" . $keyword . "'");
			$updates++;
		}
		yapcu_debug("write_clicks: Committing changes");
		$ydb->query("COMMIT");
	}
	apcu_store(YAPCU_CLICK_TIMER, time());
	apcu_delete(YAPCU_CLICK_UPDATE_LOCK);
	yapcu_debug("write_clicks: Updated click records for $updates URLs");
	return $updates;
}

/**
 * Update the log in a performant way. There is a reasonable chance of losing a few log entries.
 * This is a good trade off for us, but may not be for everyone.
 *
 * @param string $keyword
 */
function yapcu_shunt_log_redirect($false, $keyword) {

	if(defined('YAPCU_STATS_SHUNT')) {
		if(YAPCU_STATS_SHUNT == "drop") {
			return true;
		} else if(YAPCU_STATS_SHUNT == "none"){
			return false;
		}
	}
	// respect setting in YOURLS_NOSTATS. Why you'd want to enable the plugin and
	// set YOURLS_NOSTATS true I don't know ;)
	if ( !yourls_do_log_redirect() )
		return true;

	// Initialise the time.
	if(!apcu_exists(YAPCU_LOG_TIMER)) {
		apcu_add(YAPCU_LOG_TIMER, time());
	}
	$ip = yourls_get_IP();
	$args = array(
		date( 'Y-m-d H:i:s' ),
		yourls_sanitize_string( $keyword ),
		( isset( $_SERVER['HTTP_REFERER'] ) ? yourls_sanitize_url( $_SERVER['HTTP_REFERER'] ) : 'direct' ),
		yourls_get_user_agent(),
		$ip,
		yourls_geo_ip_to_countrycode( $ip )
	);

	// Separated out the calls to make a bit more readable here
	$key = YAPCU_LOG_INDEX;
	$logindex = 0;
	$added = false;

	if(!apcu_exists($key)) {
		$added = apcu_add($key, 0);
	}


	$logindex = yapcu_key_increment($key);


	// We now have a reserved logindex, so lets cache
	apcu_store(yapcu_get_logindex($logindex), $args, YAPCU_LONG_TIMEOUT);

	// If we've been caching for over a certain amount do write
	if(yapcu_write_needed('log')) {
		// We can add, so lets flush the log cache
		yapcu_write_log();
	}

	return true;
}

/**
 * write any cached log entries out to the database
 */
function yapcu_write_log() {
	global $ydb;
	$updates = 0;
	// set up a lock so that another hit doesn't start writing too
	if(!apcu_add(YAPCU_LOG_UPDATE_LOCK, 1, YAPCU_LOCK_TIMEOUT)) {
		yapcu_debug("write_log: Could not lock the log index. Abandoning write", true);
		return $updates;
	}
	yapcu_debug("write_log: Writing log to database");

	$key = YAPCU_LOG_INDEX;
	$index = apcu_fetch($key);
	if($index === false) {
		yapcu_debug("write_log: key $key has disappeared. Abandoning write.");
		apcu_store(YAPCU_LOG_TIMER, time());
		apcu_delete(YAPCU_LOG_UPDATE_LOCK);
		return $updates;
	}
	$fetched = 0;
	$n = 0;
	$loop = true;
	$values = array();

	// Retrieve all items and reset the counter
	while($loop) {
		for($i = $fetched+1; $i <= $index; $i++) {
			$row = apcu_fetch(yapcu_get_logindex($i));
			if($row === false) {
				yapcu_debug("write_log: log entry " . yapcu_get_logindex($i) . " disappeared. Possible data loss!!", true);
			} else {
				$values[] = $row;
			}
		}

		$fetched = $index;
		$n++;

		if(apcu_cas($key, $index, 0)) {
			$loop = false;
		} else {
			usleep(500);
			$index = apcu_fetch($key);
		}
	}
	yapcu_debug("write_log: $fetched log entries retrieved; index reset after $n tries");
	// Insert all log message - we're assuming input filtering happened earlier
	$query = "";

	foreach($values as $value) {
		if(!is_array($value)) {
		  yapcu_debug("write_log: log row is not an array. Skipping");
		  continue;
		}
		if(strlen($query)) {
			$query .= ",";
		}
		$row = "('" .
			$value[0] . "', '" .
			$value[1] . "', '" .
			$value[2] . "', '" .
			$value[3] . "', '" .
			$value[4] . "', '" .
			$value[5] . "')";
		yapcu_debug("write_log: row: $row");
		$query .= $row;
		$updates++;
	}
	$ydb->query( "INSERT INTO `" . YOURLS_DB_TABLE_LOG . "`
				(click_time, shorturl, referrer, user_agent, ip_address, country_code)
				VALUES " . $query);
	apcu_store(YAPCU_LOG_TIMER, time());
	apcu_delete(YAPCU_LOG_UPDATE_LOCK);
	yapcu_debug("write_log: Added $updates entries to log");
	return $updates;

}

/**
 * Helper function to return a cache key for the log index.
 *
 * @param string $key
 * @return string
 */
function yapcu_get_logindex($key) {
	return YAPCU_LOG_INDEX . "-" . $key;
}

/**
 * Helper function to return a keyword key.
 *
 * @param string $key
 * @return string
 */
function yapcu_get_keyword_key($keyword) {
	return YAPCU_KEYWORD_PREFIX . $keyword;
}

/**
 * Helper function to do an atomic increment to a variable,
 *
 *
 * @param string $key
 * @return void
 */
function yapcu_key_increment($key) {
	$n = 1;
	while(!$result = apcu_inc($key)) {
		usleep(500);
		$n++;
	}
	if($n > 1) yapcu_debug("key_increment: took $n tries on key $key");
	return $result;
}

/**
 * Reset a key to 0 in a atomic manner
 *
 * @param string $key
 * @return old value before the reset
 */
function yapcu_key_zero($key) {
	$old = 0;
	$n = 1;
	$old = apcu_fetch($key);
	if($old == 0) {
		return $old;
	}
	while(!apcu_cas($key, $old, 0)) {
		usleep(500);
		$n++;
		$old = apcu_fetch($key);
		if($old == 0) {
			yapcu_debug("key_zero: Key zeroed by someone else. Try $n. Key $key");
			return $old;
		}
	}
	if($n > 1) yapcu_debug("key_zero: Key $key zeroed from $old after $n tries");
	return $old;
}

/**
 * Helper function to manage a voluntary lock on YAPCU_CLICK_INDEX
 *
 * @return true when locked
 */
function yapcu_lock_click_index() {
	$n = 1;
	// we always unlock as soon as possilbe, so a TTL of 1 should be fine
	while(!apcu_add(YAPCU_CLICK_INDEX_LOCK, 1, 1)) {
		$n++;
		usleep(500);
	}
	if($n > 1) yapcu_debug("lock_click_index: Locked click index in $n tries");
	return true;
}

/**
 * Helper function to unlock a voluntary lock on YAPCU_CLICK_INDEX
 *
 * @return void
 */
function yapcu_unlock_click_index() {
	apcu_delete(YAPCU_CLICK_INDEX_LOCK);
}

/**
 * Send debug messages to PHP's error log
 *
 * @param string $msg
 * @param bool $important
 * @return void
 */
function yapcu_debug ($msg, $important=false) {
	if ($important || (defined('YAPCU_DEBUG') && YAPCU_DEBUG)) {
		error_log("yourls_apc_cache: " . $msg);
	}
}

/**
 * Check if the server load is above our maximum threshold for doing DB writes
 *
 * @return bool true if load exceeds threshold, false otherwise
 */
function yapcu_load_too_high() {
	if(YAPCU_MAX_LOAD == 0)
		// YAPCU_MAX_LOAD of 0 means don't do load check
		return false;
	if (stristr(PHP_OS, 'win'))
		// can't get load on Windows, so just assume it's OK
		return false;
	$load = sys_getloadavg();
	if ($load[0] < YAPCU_MAX_LOAD)
		return false;
	return true;
}

/**
 * Count number of click updates that are cached
 *
 * @return int number of keywords with cached clicks
 */
function yapcu_click_updates_count() {
	$count = 0;
	if(apcu_exists(YAPCU_CLICK_INDEX)) {
		$clickindex = apcu_fetch(YAPCU_CLICK_INDEX);
		$count = count($clickindex);
	}
	return $count;
}


/**
 * Check if we need to do a write to DB yet
 * Considers time since last write, system load etc
 *
 * @param string $type either 'click' or 'log'
 * @param int $clicks number of clicks cached for current URL
 * @return bool true if a DB write is due, false otherwise
 */
function yapcu_write_needed($type, $clicks=0) {

	if($type == 'click') {
		$timerkey = YAPCU_CLICK_TIMER;
		$count = yapcu_click_updates_count();
	} elseif ($type = 'log') {
		$timerkey = YAPCU_LOG_TIMER;
		$count = apcu_fetch(YAPCU_LOG_INDEX);
	} else {
		return false;
	}
	if (empty($count)) $count = 0;
	yapcu_debug("write_needed: Info: $count $type updates in cache");

	if (!empty($clicks)) yapcu_debug("write_needed: Info: current URL has $clicks cached clicks");

	if(apcu_exists($timerkey)) {
		$lastupdate = apcu_fetch($timerkey);
		$elapsed = time() - $lastupdate;
		yapcu_debug("write_needed: Info: Last $type write $elapsed seconds ago at " . strftime("%T" , $lastupdate));

		/**
		 * in the tests below YAPCU_WRITE_CACHE_TIMEOUT of 0 means never do a write on the basis of
		 * time elapsed, YAPCU_MAX_UPDATES of 0 means never do a write on the basis of number
		 * of queued updates, YAPCU_MAX_CLICKS of 0 means never write on the basis of the number
		 * clicks pending
		 **/

		// if we reached YAPCU_WRITE_CACHE_HARD_TIMEOUT force a write out no matter what
		if ( !empty(YAPCU_WRITE_CACHE_TIMEOUT) && $elapsed > YAPCU_WRITE_CACHE_HARD_TIMEOUT) {
			yapcu_debug("write_needed: True: Reached hard timeout (" . YAPCU_WRITE_CACHE_HARD_TIMEOUT ."). Forcing write for $type after $elapsed seconds");
			return true;
		}

		// if we've backed off because of server load, don't write
		if( apcu_exists(YAPCU_BACKOFF_KEY)) {
			yapcu_debug("write_needed: False: Won't do write for $type during backoff period");
			return false;
		}

		// have we either reached YAPCU_WRITE_CACHE_TIMEOUT or exceeded YAPCU_MAX_UPDATES or YAPCU_MAX_CLICKS
		if(( !empty(YAPCU_WRITE_CACHE_TIMEOUT) && $elapsed > YAPCU_WRITE_CACHE_TIMEOUT )
		    || ( !empty(YAPCU_MAX_UPDATES) && $count > YAPCU_MAX_UPDATES )
		    || (!empty(YAPCU_MAX_CLICKS) && !empty($clicks) && $clicks > YAPCU_MAX_CLICKS) ) {
			// if server load is high, delay the write and set a backoff so we won't try again
			// for a short while
			if(yapcu_load_too_high()) {
				yapcu_debug("write_needed: False: System load too high. Won't try writing to database for $type", true);
				apcu_add(YAPCU_BACKOFF_KEY, time(), YAPCU_BACKOFF_TIME);
				return false;
			}
			yapcu_debug("write_needed: True: type: $type; count: $count; elapsed: $elapsed; clicks: $clicks; YAPCU_WRITE_CACHE_TIMEOUT: " . YAPCU_WRITE_CACHE_TIMEOUT . "; YAPCU_MAX_UPDATES: " . YAPCU_MAX_UPDATES . "; YAPCU_MAX_CLICKS: " . YAPCU_MAX_CLICKS);
			return true;
		}

		return false;
	}

	// The timer key went away. Better do an update to be safe
	yapcu_debug("write_needed: True: reason: no $type timer found");
	return true;

}

/**
 * Add the flushcache method to the API
 *
 * @param array $api_action
 * @return array $api_action
 */
function yapcu_api_filter($api_actions) {
	$api_actions['flushcache'] = 'yapcu_force_flush';
	return $api_actions;
}

/**
 * Force a write of both clicks and logs to the database
 *
 * @return array $return status of updates
 */
function yapcu_force_flush() {
	/* YAPCU_API_USER of false means disable API.
	 * YAPCU_API_USER of empty string means allow
	 * any user to use API. Otherwise only the specified
	 * user is allowed
	 */
	$user = defined( 'YOURLS_USER' ) ? YOURLS_USER : '-1';
	if(YAPCU_API_USER === false) {
		yapcu_debug("force_flush: Attempt to use API flushcache function whilst it is disabled. User: $user", true);
		$return = array(
			'simple'    => 'Error: The flushcache function is disabled',
			'message'   => 'Error: The flushcache function is disabled',
			'errorCode' => 403,
		);
	}
	elseif(!empty(YAPCU_API_USER) && YAPCU_API_USER != $user) {
		yapcu_debug("force_flush: Unauthorised attempt to use API flushcache function by $user", true);
		$return = array(
			'simple'    => 'Error: User not authorised to use the flushcache function',
			'message'   => 'Error: User not authorised to use the flushcache function',
			'errorCode' => 403,
		);
	} else {
		yapcu_debug("force_flush: Forcing write to database from API call");
		$start = microtime(true);
		$log_updates = yapcu_write_log();
		$log_time = sprintf("%01.3f", 1000*(microtime(true) - $start));
		$click_updates = yapcu_write_clicks();
		$click_time = sprintf("%01.3f", 1000*(microtime(true) - $start));
		$return = array(
			'clicksUpdated'   => $click_updates,
			'clickUpdateTime' => $click_time,
			'logsUpdated' => $log_updates,
			'logUpdateTime' => $log_time,
			'statusCode' => 200,
			'simple'     => "Updated clicks for $click_updates URLs in ${click_time} ms. Logged $log_updates hits in ${log_time} ms.",
			'message'    => 'Success',
		);
	}
	return $return;
}

/**
 * Replaces yourls_redirect. Does redirect first, then does logging and click
 * recording afterwards so that redirect is not delayed
 * This is somewhat fragile and may be broken by other plugins that hook on
 * pre_redirect, redirect_location or redirect_code
 *
 */
function yapcu_redirect_shorturl( $args ) {
	$code = defined('YAPCU_REDIRECT_FIRST_CODE')?YAPCU_REDIRECT_FIRST_CODE:301;
	$location = $args[0];
	$keyword = $args[1];
	yourls_do_action( 'pre_redirect', $location, $code );
	$location = yourls_apply_filter( 'redirect_location', $location, $code );
	$code     = yourls_apply_filter( 'redirect_code', $code, $location );
	// Redirect, either properly if possible, or via Javascript otherwise
	if( !headers_sent() ) {
		yourls_status_header( $code );
		header( "Location: $location" );
		// force the headers to be sent
		echo "Redirecting to $location\n";
		@ob_end_flush();
		@ob_flush();
		flush();
	} else {
		yourls_redirect_javascript( $location );
	}

	$start = microtime(true);
	// Update click count in main table
	$update_clicks = yourls_update_clicks( $keyword );

	// Update detailed log for stats
	$log_redirect = yourls_log_redirect( $keyword );
	$lapsed = sprintf("%01.3f", 1000*(microtime(true) - $start));
	yapcu_debug("redirect_shorturl: Database updates took $lapsed ms after sending redirect");

	die();
}
