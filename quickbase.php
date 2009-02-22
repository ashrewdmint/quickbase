<?php

/*
Quickbase - a lightweight, easy to use database class for PHP
	Github:  http://github.com/ashrewdmint/quickbase/
	
Copyright (C) 2009 Andrew Smith
	Email:   andrew.caleb.smith@gmail.com
	Twitter: ashrewdmint
	
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

class Quickbase {
	var $host;
	var $user;
	var $pass;
	var $name;
	var $tabledesc;
	var $status = true;
	var $log    = array();
	
	function Quickbase($host, $user, $pass, $name) {
		$this->host = $host;
		$this->user = $user;
		$this->pass = $pass;
		$this->name = $name;
		
		$this->connect();
	}
	
	// EXTERNAL METHODS
	
	// MySQL query function
	// Returns everything as an associative array (if nothing went wrong)
	function q($query, $collapse = false) {
		$result = array();
		
		$resource = mysql_query($query);
		
		// If we got back some data
		if (gettype($resource) != 'boolean') {
			// If there were multiple rows, loop through them all and put them in an associative array
			if (mysql_num_rows($resource) > 1) {
				while($row = mysql_fetch_assoc($resource)) {
					$result[] = $row;
				}
				if ($collapse) {
					$breach = false;
					$new = array();
					foreach($result as $row) {
						if (is_array($row) && count($row) == 1) {
							$new[] = join('', $row);
						} else {
							$breach = true;
						}
					}
					if (! $breach) $result = $new;
				}
			// If there's only one row, put it in an array
			} else {
				$result = mysql_fetch_assoc($resource);
				if (! $collapse) $result = array($result);
			}
		} else {
			$result = $resource;
		}
		
		$this->__log($query);
		
		return $result;
	}
	
	// Makes a simple SELECT query from an array, or a string
	// To make a WHERE statement in the query, add an associative array inside the first array
	// Modifiers for array keys are: #regexp, #regexpbin, #like, #notlike, #>, #<
	/*	array(
			'username',
			'id#order#desc' // Will order by the "id" column, descending.
			'where' => array(
				'username' => 'fordprefect'
				'id#>'     => 3
			)
		)
	*/
	function find($name, $fields = '*', $collapse = false) {
		if (is_string($fields)) $fields = array($fields);
		
		$desc    = '';
		$orderby = array();
		$where   = array();
		
		foreach($fields as $i => $field) {
			if (is_array($field)) {
				unset($fields[$i]);
				
				foreach($field as $key => $value) {	
					$where[] = $this->__where($key, $value);
				}
			} elseif (strstr($field, '#order')) {
				$desc = '';
				if (strstr($field, '#desc')) {
					$desc = ' DESC';
				}
				$field = preg_replace('/#order|#desc/', '', $field);
				$fields[$i] = $field;
				$orderby[] = $field;
			}
		}
		
		if (count($where) > 0) {
			$where = ' WHERE '.join(' AND ', $where);
		} else {
			$where = '';
		}
		
		if (count($orderby) > 0) {
			$orderby = ' ORDER BY '.join(', ', $orderby).$desc;
		} else {
			$orderby = '';
		}
		
		$query = 'SELECT '.join(', ', $fields)." FROM `$name`".$where.$orderby;
		$result = $this->q($query, $collapse);
		return $result;
	}
	
	// Adds multiple rows from an array
	// All rows must have the same number of columns
	/*  array(
			array(
				'username' => 'arthurdent',
				'likes'    => 'tea'
			),
			array(
				...
			),
		)
	*/
	function addRows($name, $array, $die = false) {
		$columns = 0;
		foreach($array as $row) {
			if (! is_array($row)) return false;
			// All rows must have the same number of columns, yo!
			if ($columns != 0 && count($row) != $columns) return false;
			$result = $this->addRow($name, $row, $die);
			$columns = count($row);
		}
		return $result;
	}

	// Adds one row from an array
	/*  array(
			'username' => 'arthurdent'
			'likes'    => 'tea'
		)
	*/
	function addRow($name, $array, $die = false) {
		// Do we know what this table is like already?
		if (isset($this->tabledesc[$name])) {
			$desc = $this->tabledesc[$name];
		// If not, ask the database for a description
		} else {
			$desc = $this->q('DESCRIBE '.$name);
			if (!$desc) return false;
			$this->tabledesc[$name] = $desc;
			$fields = array();
		}
		
		foreach($desc as $column) {
			$fields[] = $column['Field'];
		}
		
		$complete = array();
		
		foreach($fields as $field) {
			$value = '';
			if (isset($array[$field])) {
				$value = $array[$field];
			}
			$complete[] = $this->__sanitize($value);
		}
		
		$query = 'INSERT INTO `'.$name.'` VALUES ('.join(', ', $complete).');';
		
		$result = $this->q($query);
		return $result;
	}
	
	// Updates multiple rows from an array
	/*  array(
			array(
				'username#where' => 'arthurdent',
				'likes'          => 'trillian'
			),
			array(
				...
			),
		)
	*/
	function updateRows() {
		foreach($array as $row) {
			if (! is_array($row)) return false;
			$result = $this->updateRow($name, $row, $die);
		}
		return $result;
	}
	
	// Updates one row from an array
	// For any qualifications, add array items with a key beginning with
	// "where#" and the specific column name. Examples: "where#id", "where#username"
	// You can also put an associative array inside the first array to make a WHERE statement
	/*  array(
			'username#where' => 'zaphodbeeblebrox',
			'likes'          => 'himself'
		)
	*/
	function updateRow($name, $array, $die = false) {
		$where    = array();
		$newdata  = array();
		$desc     = $this->q('DESCRIBE '.$name);
		$fields   = array();
		
		if (!$desc) return false;
		
		foreach($desc as $column) {
			$fields[] = $column['Field'];
		}
		
		foreach($array as $key => $value) {
			if (is_array($value)) {
				unset($array[$i]);
				
				foreach($value as $k => $v) {	
					$where[] = $this->__where($k, $v);
				}
			} else {
				if (preg_match('/#where/', $key)) {
					$n = preg_replace('/#where/', '', $key);
					$where[] = $this->__where($n, $value);
				} else {
					if (in_array($key, $fields)) {
						$newdata[] = "$key = ".$this->__sanitize($value);
					}
				}
			}
		}
		
		if (count($newdata) == 0 || count($where) == 0) return false;
		
		$query = "UPDATE `$name` SET ".join(', ', $newdata);
		$query .= ' WHERE '.join(' AND ', $where);
		
		$result = $this->q($query);
		return $result;
	}
	
	// Updates all rows from an array
	// Like, seriously, all the rows
	// Don't use this unless you want to update all the rows!
	function updateAll($name, $array) {
		$newdata = array();
		
		foreach($array as $key => $value) {
			$value = $this->__sanitize($value);
			$newdata[] = $key.' = '.$value;
		}
		
		$query = "UPDATE `$name` SET ".join(', ', $newdata);
		
		$result = $this->q($query);
		return $result;
	}
	
	// Merges a table with the provided array
	// Existing rows are updated, new rows are created
	// The $compare parameter tells the class what to search for when looking for existing rows
	/*  array(
			array(
				'username' => 'zaphodbeeblebrox',
				'likes'    => 'himself'
			),
			...
		)
	*/
	function merge($name, $compare, $array) {
		foreach($array as $row) {
			// If this exists, update it
			$found = $this->find($name,
				array(
					$compare,
					'where' => array($compare => $row[$compare])
				)
			);
			if ($found) {
				//Prepare $row for updateRow()
				$compareValue = $row[$compare];
				unset($row[$compare]);
				$row[$compare.'#where'] = $compareValue;
				
				// Update this specific row
				$this->updateRow($name, $row, $die);
			// If it doesn't exist, create it
			} else {
				$this->addRow($name, $row);
			}
		}
		return $result;
	}
	
	// DeleteRows and deleteRow work in pretty much the same way as updateRows and updateRow
	function deleteRows($name, $array) {
		foreach($array as $row) {
			if (! is_array($row)) return false;
			$result = $this->deleteRow($name, $row);
		}
		return $result;
	}
	
	function deleteRow($name, $array) {
		foreach($array as $key => $value) {
			unset($array[$key]);
			$array[] = $this->__where($key, $value);
		}
		
		$query = "DELETE FROM `$name` WHERE ".join(' AND ', $array);
		
		$result = $this->q($query);
		return $result;
	}
	
	// Returns the log array, then clears the log
	// Useful for debugging stuff
	function dump() {
		$log = $this->log;
		$this->log = array();
		return $log;
	}
	
	// INTERNAL METHODS
	
	function connect() {
		$link = @mysql_connect($this->host, $this->user, $this->pass);
		$this->status = $link && mysql_select_db($this->name) ? true : false;
		return $this->status;
	}
	
	function __log($text) {
		$this->log[] = array(
			$text,
			date('r'),
			mysql_error()
		);
	}
	
	// Turns '' into NULL, turns quotes and adds slashes to a string, and leaves numbers unchanged
	function __sanitize($value) {
		if (! is_numeric($value)) {
			if ($value == '') {
				$value = 'NULL';
			} else {
				$value = "'".addslashes($value)."'";
			}
		}
		return $value;
	}
	
	// Takes a key and a value, and spits out things like "key REGEXP '^hithere$'" or "key LIKE value" or "key = value"
	function __where($key, $value) {
		
		// Find correct operator
		if (preg_match('/#([^$]*)$/', $key, $mod)) {
			$key = preg_replace('/#([^$]*)$/', '', $key);
			$key = "`$key`";
			
			$mod = $mod[1];
			
			switch($mod) {
				case '>':
					$first = "$key $mod";
					break;
				case '<':
					$first = "$key $mod";
					break;
				case 'regexp':
					$first = "$key REGEXP";
					break;
				case 'regexpbin':
					$first = "$key REGEXP BINARY";
					break;
				case 'like':
					$first = "$key LIKE";
					break;
				case 'notlike':
					$first = "$key NOT LIKE";
					break;
			}
		} else {
			$first = "`$key` =";
		}
		// Find value
		if (is_array($value)) {
			$result = '( ';
			foreach($value as $v) {
				$result .= $first.' '.$this->__sanitize($v).' OR ';
			}
			// Chop off last "OR"
			$result = preg_replace('/OR $/', ')', $result);
		} else {
			$value = $this->__sanitize($value);
			$result = $first.' '.$value;
		}
		
		return $result;
	}
}

?>