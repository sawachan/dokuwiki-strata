<?php
/**
 * DokuWiki Plugin stratastorage (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Brend Wanders <b.wanders@utwente.nl>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'stratastorage/driver/driver.php');

class helper_plugin_stratastorage_triples extends DokuWiki_Plugin {
    function getMethods() {
        $result = array();
        $result[] = array(
            'name'=> 'initialize',
            'desc'=> 'Sets up a connection to the triple storage.',
            'params'=> array(
                'dsn (optional)'=>'string'
            ),
            'return' => 'boolean'
        );

        return $result;
    }
    
    function initialize($dsn=null) {
        if($dsn == null) {
            $dsn = $this->getConf('default_dsn');

            if($dsn == '') {
                global $conf;
                $file = "{$conf['metadir']}/strata.sqlite";
                $init = (!@file_exists($file) || ((int) @filesize($file) < 3));
                $dsn = "sqlite:$file";
            }
        }

        $this->_dsn = $dsn;

        list($driver,$connection) = explode(':',$dsn,2);
        $driverFile = DOKU_PLUGIN."stratastorage/driver/$driver.php";
        if(!@file_exists($driverFile)) {
            msg('Strata storage: no complementary driver for PDO driver '.$driver.'.',-1);
            return false;
        }
        require_once($driverFile);
        $driverClass = "plugin_strata_driver_$driver";
        $this->_driver = new $driverClass();

        try {
            $this->_db = new PDO($dsn);
        } catch(PDOException $e) {
            if($this->getConf('debug')) msg(hsc("Strata storage: failed to open DSN '$dsn': ".$e->getMessage()),-1);
            return false;
        }

        if($init) {
            $this->_setupDatabase();
        }

        return true;
    }

    function _setupDatabase() {
        list($driver,$connection) = explode(':',$this->_dsn,2);
        if($this->getConf('debug')) msg('Strata storage: Setting up '.$driver.' database.');

        $sqlfile = DOKU_PLUGIN."stratastorage/sql/setup-$driver.sql";

        $sql = io_readFile($sqlfile, false);
        $sql = explode(';', $sql);

        $this->_db->beginTransaction();
        foreach($sql as $s) {
            $s = preg_replace('/^\s*--.*$/','',$s);
            $s = trim($s);
            if($s == '') continue;

            if($this->getConf('debug')) msg(hsc('Strata storage: Executing \''.$s.'\'.'));
            if(!$this->_query($s, 'Failed to set up database')) {
                $this->_db->rollback();
                return false;
            }
        }
        $this->_db->commit();

        msg('Strata storage: Database set up succesful!',1);

        return true;
    }

    function _prepare($query) {
        $result = $this->_db->prepare($query);
        if($result === false) {
            $error = $this->_db->errorInfo();
            msg(hsc('Strata storage: Failed to prepare query \''.$query.'\': '.$error[2]),-1);
            return false;
        }

        return $result;
    }

    function _query($query, $message="Query failed") {
        $res = $this->_db->query($query);
        if($res === false) {
            $error = $this->_db->errorInfo();
            msg(hsc('Strata storage: '.$message.' (with \''.$query.'\'): '.$error[2]),-1);
            return false;
        }
        return true;
    }

    function removeTriples($subject=null, $predicate=null, $object=null, $graph=null) {
        $filters = array('1');
        foreach(array('subject','predicate','object','graph') as $param) {
            if($$param != null) {
                $filters[]="$param LIKE ?";
                $values[] = $$param;
            }
        }

        $sql .= "DELETE FROM data WHERE ". implode(" AND ", $filters);

        $query = $this->_prepare($sql);
        if($query == false) return;
        $res = $query->execute($values);
        if($res === false) {
            $error = $query->errorInfo();
            msg(hsc('Strata storage: Failed to remove triples: '.$error[2]),-1);
        }
        $query->closeCursor();
    }

    function fetchTriples($subject=null, $predicate=null, $object=null, $graph=null) {
        $filters = array('1');
        foreach(array('subject','predicate','object','graph') as $param) {
            if($$param != null) {
                $filters[]="$param = ?";
                $values[] = $$param;
            }
        }

        $sql .= "SELECT * FROM data WHERE ". implode(" AND ", $filters);

        $query = $this->_prepare($sql);
        if($query == false) return;
        $res = $query->execute($values);
        if($res === false) {
            $error = $query->errorInfo();
            msg(hsc('Strata storage: Failed to fetch triples: '.$error[2]),-1);
        }

        $result = $query->fetchAll();
        $query->closeCursor();
        return $result;
    }

    function addTriple($subject, $predicate, $object, $graph=null) {
        return $this->addTriples(array(array('subject'=>$subject, 'predicate'=>$predicate, 'object'=>$object)), $graph);
    }

    function addTriples($triples, $graph=null) {
        if($graph == null) {
            $graph = $this->getConf('default_graph');
        }

        $sql = "INSERT INTO data(subject, predicate, object, graph) VALUES(?, ?, ?, ?)";
        $query = $this->_prepare($sql);
        if($query == false) return;

        $this->_db->beginTransaction();
        foreach($triples as $t) {
            $values = array($t['subject'],$t['predicate'],$t['object'],$graph);
            $res = $query->execute($values);
            if($res === false) {
                $error = $query->errorInfo();
                msg(hsc('Strata storage: Failed to add triples: '.$error[2]),-1);
                $this->_db->rollback();
                return;
            }
            $query->closeCursor();
        }
        $this->_db->commit();
    }
}
