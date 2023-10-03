<?php

namespace MediaWiki\Extension\Thanks;

use Config;
use IContextSource;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * A service class that caches the list of IDs (revisions, logs) a user has thanked for.
 * It uses a session as a backend.
 */
class ThanksCache {
	private const THANKED_IDS_SESSION_KEY_PATTERN = "thanks-thanked-ids-%d";
	/** @var ILoadBalancer */
	private $lb;
	/** @var Config */
	private $config;

	public function __construct( ILoadBalancer $lb, Config $config ) {
		$this->lb = $lb;
		$this->config = $config;
	}

	/**
	 * Gets a cached list of entires for which a user has thanked.
	 * Entires are refetched on cache miss.
	 *
	 * Each entry is in the same format as saved by the store (i.e. a string formatted as "$type-$id").
	 * @param IContextSource $ctx - request context.
	 * @param int $thankerActorId - actor ID of the user who thanked.
	 * @return array An array of strings formatted as "$type-$id"
	 */
	public function getUserThanks( IContextSource $ctx, int $thankerActorId ): array {
		$dbr = $this->lb->getConnection( DB_REPLICA );
		$session = $ctx->getRequest()->getSession();

		$key = sprintf( self::THANKED_IDS_SESSION_KEY_PATTERN, $thankerActorId );
		$cachedRevs = $session->get( $key );
		if ( $cachedRevs !== null ) {
			return $cachedRevs;
		}
		$maxDays = $this->config->get( 'ThanksMaxLoadThankedPeriodDays' );
		$dbEntries = $dbr->newSelectQueryBuilder()
			->select( 'ls_value' )
			->from( 'logging' )
			->join( 'log_search', null, [ 'log_id = ls_log_id' ] )
			->where(
				[
					'log_actor' => $thankerActorId,
					'log_type' => 'thanks',
					'log_timestamp BETWEEN ' .
					$dbr->addQuotes( $dbr->timestamp( time() - 86400 * $maxDays ) ) .
					' AND ' .
					$dbr->addQuotes( $dbr->timestamp() ),
					'ls_field' => 'thankid',
				]
			)
			->orderBy( 'log_timestamp', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$session->set( $key, $dbEntries );
		return $dbEntries;
	}

	/**
	 * Adds a new entry to the list of entires for which a user has thanked.
	 * Note that it only updates the cache - clients have to make sure that the underlying data is consistent.
	 * @param IContextSource $ctx - request context.
	 * @param int $thankerActorId - actor ID of the user who thanked.
	 * @param string $id - entry ID in the same format as saved by the store (i.e. a string formatted as "$type-$id").
	 * @return array An array of strings formatted as "$type-$id"
	 */
	public function appendUserThanks( IContextSource $ctx, int $thankerActorId, string $id ): array {
		$session = $ctx->getRequest()->getSession();
		$key = sprintf( self::THANKED_IDS_SESSION_KEY_PATTERN, $thankerActorId );

		$thankedCached = $session->get( $key );
		if ( $thankedCached === null ) {
			$thankedCached = self::getUserThanks( $ctx, $thankerActorId );
		}
		$thankedCached[] = $id;
		$session->set( $key, $thankedCached );
		return $thankedCached;
	}

	public function haveThanked( IContextSource $ctx, int $thankerActorId, int $id, string $type = 'rev' ): bool {
		if ( $type === 'revision' ) {
			$type = 'rev';
		}
		$value = "$type-$id";
		return in_array( $value, self::getUserThanks( $ctx, $thankerActorId ) );
	}
}
