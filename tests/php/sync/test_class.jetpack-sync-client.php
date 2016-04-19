<?php

$sync_dir = dirname( __FILE__ ) . '/../../../sync/';
$sync_server_dir = dirname( __FILE__ ) . '/server/';
	
require_once $sync_dir . 'class.jetpack-sync-server.php';
require_once $sync_dir . 'class.jetpack-sync-client.php';

require_once $sync_server_dir . 'interface.jetpack-sync-replicastore.php';
require_once $sync_server_dir . 'class.jetpack-sync-server-replicastore.php';
require_once $sync_server_dir . 'class.jetpack-sync-server-replicator.php';
require_once $sync_server_dir . 'class.jetpack-sync-server-eventstore.php';
require_once $sync_server_dir . 'class.jetpack-sync-wp-replicastore.php';

/*
 * Base class for Sync tests - establishes connection between local
 * Jetpack_Sync_Client and dummy server implementation,
 * and registers a Replicastore and Eventstore implementation to 
 * process events.
 */

class WP_Test_Jetpack_New_Sync_Base extends WP_UnitTestCase {
	protected $client;
	protected $server;
	protected $server_replica_storage;
	protected $server_event_storage;

	public function setUp() {
		parent::setUp();

		$this->client = Jetpack_Sync_Client::getInstance();
		$this->client->set_sync_queue( new Jetpack_Sync_Queue( 'sync', 100 ) );

		$server       = new Jetpack_Sync_Server();
		$this->server = $server;

		// bind the client to the server
		add_filter( 'jetpack_sync_client_send_data', function( $data ) use ( &$server ) {
			$this->server->receive( $data );
			return $data;
		} );

		// bind the two storage systems to the server events
		$this->server_replica_storage = new Jetpack_Sync_Server_Replicastore();
		$this->server_replicator      = new Jetpack_Sync_Server_Replicator( $this->server_replica_storage );
		$this->server_replicator->init();

		$this->server_event_storage = new Jetpack_Sync_Server_Eventstore();
		$this->server_event_storage->init();

	}

	public function tearDown() {
		parent::tearDown();
		$this->client->reset_state();
	}

	public function test_pass() {
		// so that we don't have a failing test
		$this->assertTrue( true );
	}

	protected function assertDataIsSynced() {
		$local  = new Jetpack_Sync_WP_Replicastore();
		$remote = $this->server_replica_storage;

		$this->assertEquals( $local->get_posts(), $remote->get_posts() );
		$this->assertEquals( $local->get_comments(), $remote->get_comments() );
	}



	// TODO:
	// send in near-time cron job if sending buffer fails
	// limit overall rate of sending
}

class WP_Test_Jetpack_New_Sync_Client extends WP_Test_Jetpack_New_Sync_Base {
	protected $action_ran;
	protected $encoded_data;

	public function test_add_post_fires_sync_data_action_on_do_sync() {
		$this->action_ran = false;

		add_filter( 'jetpack_sync_client_send_data', array( $this, 'action_ran' ) );

		$this->client->do_sync();

		$this->assertEquals( true, $this->action_ran );
	}

	public function test_client_allows_optional_codec() {

		// build a codec
		$codec = $this->getMockBuilder( 'iJetpack_Sync_Codec' )->getMock();
		$codec->method( 'encode' )->willReturn( 'foo' );

		// set it on the client
		$this->client->set_codec( $codec );

		// if we don't do this the server will try to decode the dummy data
		remove_all_actions( 'jetpack_sync_client_send_data' );

		$this->encoded_data = null;
		add_filter( 'jetpack_sync_client_send_data', array( $this, 'set_encoded_data' ) );

		$this->client->do_sync();

		$this->assertEquals( "foo", $this->encoded_data );
	}

	function test_clear_actions_on_client() {
		$this->factory->post->create();
		$this->assertNotEmpty( $this->client->get_all_actions() );
		$this->client->do_sync();

		$this->client->reset_state();
		$this->assertEmpty( $this->client->get_all_actions() );

	}

	function action_ran( $data ) {
		$this->action_ran = true;
		return $data;
	}

	function set_encoded_data( $data ) {
		$this->encoded_data = $data;
		return $data;
	}
}