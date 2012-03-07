<?php
require_once(DOKU_INC.'_test/lib/unittest.php');
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/plugin.php');
require_once(DOKU_INC.'lib/plugins/stratastorage/helper/triples.php');
class Strata_UnitTestCase extends Doku_UnitTestCase {

	function setup() {
		// Setup a new database (uncomment the one to use)
		$this->_triples = new helper_plugin_stratastorage_triples();

		// Use SQLite (default)
		$this->_triples->initialize('sqlite::memory:');

		// Use MySQL, which is set up with:
		// CREATE DATABASE strata_test;
		// GRANT ALL ON strata_test.* TO ''@localhost;
		//$this->_triples->initialize('mysql:dbname=strata_test');
	}

	function teardown() {
		// Remove the database
		$this->_triples->_db->removeDatabase();
	}
}

