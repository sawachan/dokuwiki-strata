<?php
/**
 * Strata Basic, data entry plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Brend Wanders <b.wanders@utwente.nl>
 */
 
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
 
/**
 * Data entry syntax for dedicated data blocks.
 */
class syntax_plugin_stratabasic_entry extends DokuWiki_Syntax_Plugin {
    function syntax_plugin_stratabasic_entry() {
        $this->helper =& plugin_load('helper', 'stratabasic');
        $this->types =& plugin_load('helper', 'stratastorage_types');
        $this->triples =& plugin_load('helper', 'stratastorage_triples', false);
        $this->triples->initialize();
    }

    function getType() {
        return 'substition';
    }

    function getPType() {
        return 'block';
    }

    function getSort() {
        return 450;
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<data(?: +[^#>]+?)?(?: *#[^>]*?)?>\n(?:.+?\n)*?</data>',$mode, 'plugin_stratabasic_entry');
    }

    function handle($match, $state, $pos, &$handler) {
        $lines = explode("\n",$match);
        $header = array_shift($lines);
        $footer = array_pop($lines);

        $result = array(
            'entry'=>'',
            'data'=> array(
                $this->triples->getIsaKey() => array(),
                $this->triples->getTitleKey() => array()
            )
        );


        // allow subclasses to mangle header
        $header = $this->handleHeader($header, $result);

        // extract header, and match it to get classes and fragment
        preg_match('/^( +[^#>]+)?(?: *#([^>]*?))?$/', $header, $header);

        // process the classes into triples
        foreach(preg_split('/\s+/',trim($header[1])) as $class) {
            if($class == '') continue;
            $result['data'][$this->triples->getIsaKey()][] = array('value'=>$class,'type'=>'text', 'hint'=>null);
        }

        // process the fragment if necessary
        $result['entry'] = $header[2];
        if($result['entry'] != '') {
            $result['data'][$this->triples->getTitleKey()][] = array('value'=>$result['entry'], 'type'=>'text', 'hint'=>null);
        }

        // parse tree
        $tree = $this->helper->constructTree($lines,'data entry');

        // allow subclasses first pick in the tree
        $this->handleBody($tree, $result);
        
        // fetch all lines
        $lines = $this->helper->extractText($tree);

        // sanity check
        if(count($tree['cs'])) {
            msg(sprintf($this->getLang('error_entry_block'), ($tree['cs'][0]['tag']?sprintf($this->getLang('named_group'),utf8_tohtml(hsc($tree['cs'][0]['tag']))):$this->getLang('unnamed_group')), utf8_tohtml(hsc($result['entry']))),-1);
            return array();
        }

        // now handle all lines
        foreach($lines as $line) {
            // match a "property_type(hint)*: value" pattern
            // (the * is only used to indicate that the value is actually a comma-seperated list)
            if(preg_match('/^('.STRATABASIC_PREDICATE.'?)(?:_([a-z0-9]+)(?:\(([^)]+)\))?)?(\*)?\s*:(.*)$/',$line,$parts)) {
                // assign useful names
                list($match, $property, $type, $hint, $multi, $values) = $parts;

                // trim property so we don't get accidental 'name   ' keys
                $property = utf8_trim($property);

                // lazy create key bucket
                if(!isset($result['data'][$property])) {
                    $result['data'][$property] = array();
                }

                // determine values, splitting on commas if necessary
                if($multi == '*') {
                    $values = explode(',',$values);
                } else {
                    $values = array($values);
                }

                // generate triples from the values
                foreach($values as $v) {
                    $v = utf8_trim($v);
                    if($v == '') continue;
                    // replace the [[]] quasi-magic token with the empty string
                    if($v == '[[]]') $v = '';
                    if(!isset($type) || $type == '') {
                        list($type, $hint) = $this->types->getDefaultType();
                    }
                    $result['data'][$property][] = array('value'=>$v,'type'=>$type,'hint'=>($hint?:null));
                }
            } else {
                msg(sprintf($this->getLang('error_entry_line'), utf8_tohtml(hsc($line))),-1);
            }
        }

        // normalize data:
        // - Normalize all values
        // - Deduplicate all values
        $buckets = $result['data'];
        $result['data'] = array();

        foreach($buckets as $property=>&$bucket) {
            // array with seen values
            $seen = array();

            foreach($bucket as &$triple) {
                // normalize the value
                $type = $this->types->loadType($triple['type']);
                $triple['value'] = $type->normalize($triple['value'], $triple['hint']);

                // normalize the predicate
                $property = $this->helper->normalizePredicate($property);

                // lazy create property bucket
                if(!isset($result['data'][$property])) {
                    $result['data'][$property] = array();
                }

                // uniqueness check
                if(!in_array($triple['value'], $seen)) {
                    $seen[] = $triple['value'];
                    $result['data'][$property][] = $triple;
                }
            }
        }

        $footer = $this->handleFooter($footer, $result);

        return $result;
    }

    /**
     * Handles the header of the syntax. This method is called before
     * the header is handled.
     *
     * @param header string the complete header
     * @param result array the result array passed to the render method
     * @return a string containing the unhandled parts of the header
     */
    function handleHeader($header, &$result) {
        // remove prefix and suffix
        return preg_replace('/(^<data)|( *>$)/','',$header);
    }

    /**
     * Handles the body of the syntax. This method is called before any
     * of the body is handled.
     *
     * @param tree array the parsed tree
     * @param result array the result array passed to the render method
     */
    function handleBody(&$tree, &$result) {
    }

    /**
     * Handles the footer of the syntax. This method is called after the
     * data has been parsed and normalized.
     * 
     * @param footer string the footer string
     * @param result array the result array passed to the render method
     * @return a string containing the unhandled parts of the footer
     */
    function handleFooter($footer, &$result) {
        return '';
    }


    function render($mode, &$R, $data) {
        global $ID;

        if($data == array()) {
            return false;
        }

        if($mode == 'xhtml') {
            // render table header
            $R->table_open();
            $R->tablerow_open();
            $R->tableheader_open(2);

            // determine actual header text
            $heading = '';
            if(isset($data['data'][$this->triples->getTitleKey()])) {
                // use title triple of possible
                $heading = $data['data'][$this->triples->getTitleKey()][0]['value'];
            } elseif (useHeading('content')) {
                // fall back to page title, depending on wiki configuration
                $heading = p_get_first_heading($ID);
            } else {
                // use page id if all else fails
                $heading = noNS($ID);
            }
            $R->doc .= $R->_xmlEntities($heading);

            // display a comma-separated list of classes if the entry has classes
            if(isset($data['data'][$this->triples->getIsaKey()])) {
                $R->emphasis_open();
                $R->doc .= ' (';
                $values = $data['data'][$this->triples->getIsaKey()];
                for($i=0;$i<count($values);$i++) {
                    $triple =& $values[$i];
                    if($i!=0) $R->doc .= ', ';
                    $type = $this->types->loadType($triple['type']);
                    $type->render($mode, $R, $this->triples, $triple['value'], $triple['hint']);
                }
                $R->doc .= ')';
                $R->emphasis_close();
            }
            $R->tableheader_close();
            $R->tablerow_close();

            // render a row for each key, displaying the values as comma-separated list
            foreach($data['data'] as $key=>$values) {
                // skip isa and title keys
                if($key == $this->triples->getTitleKey() || $key == $this->triples->getIsaKey()) continue;
                
                // render row header
                $R->tablerow_open();
                $R->tableheader_open();
                $this->helper->renderPredicate($mode, $R, $this->triples, $key);
                $R->tableheader_close();

                // render row content
                $R->tablecell_open();
                for($i=0;$i<count($values);$i++) {
                    $triple =& $values[$i];
                    if($i!=0) $R->doc .= ', ';
                    $type = $this->types->loadType($triple['type']);
                    $type->render($mode, $R, $this->triples, $triple['value'], $triple['hint']);
                }
                $R->tablecell_close();
                $R->tablerow_close();
           }

            $R->table_close();
            
            return true;

        } elseif($mode == 'metadata') {
            $triples = array();
            $subject = $ID.'#'.$data['entry'];

            // resolve the subject to normalize everything
            resolve_pageid(getNS($ID),$subject,$exists);

            foreach($data['data'] as $property=>$bucket) {
                $this->helper->renderPredicate($mode, $R, $this->triples, $property);

                foreach($bucket as $triple) {
                    // render values for things like backlinks
                    $type = $this->types->loadType($triple['type']);
                    $type->render($mode, $R, $this->triples, $triple['value'], $triple['hint']);

                    // prepare triples for storage
                    $triples[] = array('subject'=>$subject, 'predicate'=>$property, 'object'=>$triple['value']);
                }
            }

            // batch-store triples
            $this->triples->addTriples($triples, $ID);
            return true;
        }

        return false;
    }
}
