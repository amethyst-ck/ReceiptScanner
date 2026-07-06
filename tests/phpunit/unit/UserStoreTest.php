<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\Services\UserStore;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\UserStore
 */
class UserStoreTest extends MediaWikiUnitTestCase {

	/**
	 * Real WANObjectCache over an in-process HashBagOStuff — its final
	 * getWithSetCallback()/makeKey() can't be mocked, so tests observe
	 * genuine cache behavior.
	 */
	private function newCache(): WANObjectCache {
		return new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
	}

	public function testGetUsernamesCachesAcrossCalls(): void {
		// A single computed value must be reused: the DB is consulted on
		// the first call and served from cache on the second.
		$db = $this->createMock( IReadableDatabase::class );

		$botBuilder = $this->makeFluentBuilder();
		$botBuilder->method( 'fetchFieldValues' )->willReturn( [] );

		$userBuilder = $this->makeFluentBuilder();
		$userBuilder->method( 'fetchResultSet' )->willReturn( new FakeResultWrapper( [
			(object)[ 'user_id' => 1, 'user_name' => 'Alice' ],
			(object)[ 'user_id' => 2, 'user_name' => 'Bob' ],
		] ) );

		$db->method( 'newSelectQueryBuilder' )
			->willReturnOnConsecutiveCalls( $botBuilder, $userBuilder );

		$provider = $this->createMock( IConnectionProvider::class );
		// getReplicaDatabase must fire once — the second getUsernames()
		// call is served from cache and never touches the DB.
		$provider->expects( $this->once() )
			->method( 'getReplicaDatabase' )->willReturn( $db );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromId' )->willReturnMap( [
			[ 1, $this->mockUser( false ) ],
			[ 2, $this->mockUser( false ) ],
		] );

		$list = new UserStore( $this->newCache(), $provider, $userFactory );
		$this->assertSame( [ 'Alice', 'Bob' ], $list->getUsernames() );
		$this->assertSame( [ 'Alice', 'Bob' ], $list->getUsernames() );
	}

	public function testCallbackFiltersBotsAndSystemUsers(): void {
		// Real cache: an empty cache misses, so getWithSetCallback runs
		// the callback that queries the DB and filters.
		$cache = $this->newCache();

		// DB scaffolding: two SelectQueryBuilder calls — one for the
		// bot-IDs lookup, one for the full user list. Each returns a
		// fluent self-reference for select/from/where/caller/orderBy.
		$db = $this->createMock( IReadableDatabase::class );

		$botBuilder = $this->makeFluentBuilder();
		$botBuilder->method( 'fetchFieldValues' )
			->willReturn( [ '5', '6' ] );

		$userBuilder = $this->makeFluentBuilder();
		$userBuilder->method( 'fetchResultSet' )->willReturn( new FakeResultWrapper( [
			(object)[ 'user_id' => 1, 'user_name' => 'Alice' ],
			(object)[ 'user_id' => 2, 'user_name' => 'SystemBot' ],
			(object)[ 'user_id' => 3, 'user_name' => 'Bob' ],
		] ) );

		// First call returns bot lookup; second returns user list.
		$db->method( 'newSelectQueryBuilder' )
			->willReturnOnConsecutiveCalls( $botBuilder, $userBuilder );

		// $db->expr() is called when botIds is non-empty to build the
		// exclusion predicate. Return value isn't inspected by the
		// userBuilder mock — just has to satisfy the declared type.
		$db->method( 'expr' )->willReturn(
			$this->createMock( \Wikimedia\Rdbms\Expression::class )
		);

		$provider = $this->createMock( IConnectionProvider::class );
		$provider->method( 'getReplicaDatabase' )->willReturn( $db );

		// UserFactory: id=1 is real, id=2 is a system user, id=3 is real.
		$alice = $this->mockUser( false );
		$sys = $this->mockUser( true );
		$bob = $this->mockUser( false );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromId' )->willReturnMap( [
			[ 1, $alice ],
			[ 2, $sys ],
			[ 3, $bob ],
		] );

		$result = ( new UserStore( $cache, $provider, $userFactory ) )->getUsernames();

		// Bot rows never reach the loop (they were excluded server-side),
		// the system user got filtered client-side.
		$this->assertSame( [ 'Alice', 'Bob' ], $result );
	}

	private function makeFluentBuilder(): SelectQueryBuilder {
		$b = $this->createMock( SelectQueryBuilder::class );
		foreach ( [ 'select', 'from', 'where', 'caller', 'orderBy' ] as $m ) {
			$b->method( $m )->willReturnSelf();
		}
		return $b;
	}

	private function mockUser( bool $isSystem ): User {
		$u = $this->createMock( User::class );
		$u->method( 'isSystemUser' )->willReturn( $isSystem );
		return $u;
	}
}
