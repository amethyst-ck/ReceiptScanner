<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use MediaWiki\User\UserFactory;
use WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Sorted list of real (non-bot, non-system) usernames for the assignee
 * autocomplete; cached for a day. System users are detected via
 * User::isSystemUser() so externally-authenticated accounts (empty
 * local password) aren't filtered out.
 */
class UserStore {

	private const CACHE_TTL = WANObjectCache::TTL_DAY;

	public function __construct(
		private readonly WANObjectCache $cache,
		private readonly IConnectionProvider $dbProvider,
		private readonly UserFactory $userFactory
	) {
	}

	/**
	 * @return string[] Usernames, alphabetical, bots + system users excluded.
	 */
	public function getUsernames(): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'receiptscanner-users-real' ),
			self::CACHE_TTL,
			function () {
				$db = $this->dbProvider->getReplicaDatabase();
				$botIds = $db->newSelectQueryBuilder()
					->select( 'ug_user' )
					->from( 'user_groups' )
					->where( [ 'ug_group' => 'bot' ] )
					->caller( __METHOD__ )
					->fetchFieldValues();
				$qb = $db->newSelectQueryBuilder()
					->select( [ 'user_id', 'user_name' ] )
					->from( 'user' )
					->orderBy( 'user_name' )
					->caller( __METHOD__ );
				if ( $botIds ) {
					$qb->where( $db->expr( 'user_id', '!=', $botIds ) );
				}
				$out = [];
				foreach ( $qb->fetchResultSet() as $row ) {
					$user = $this->userFactory->newFromId( (int)$row->user_id );
					if ( !$user->isSystemUser() ) {
						$out[] = $row->user_name;
					}
				}
				return $out;
			}
		);
	}
}
