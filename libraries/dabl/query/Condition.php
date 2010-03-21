<?php
/**
 * Last Modified September 11th 2009
 */

/**
 * Represents/contains "AND" or "OR" statements
 *
 * $q = new Query("table");
 * $q->setAction("SELECT");
 *
 * Example:
 *
 * $c = new Condition;
 * $c->addAnd('Column',$value);			- $c statement = "Column=$value"
 * $c->addOr('Column2',$value2,"<"); 	- $c statement = "Column=$value OR Column2<$value2"
 *
 * ..could also be written like this:
 * $c->addAnd('Column',$value)->addOr('Column2',$value2,"<");
 *
 * $c2 = new Condition;
 * $c2->addAnd('Column3',$value3);	- $c2 statement = "Column3=$value3"
 * $c2->addAnd('Column4',$value4);	- $c2 statement = "Column3=$value3 AND Column4=$value4"
 *
 * $c->addOr($c2);					- $c statement = "Column=$value OR Column2<$value2 OR (Column3=$value3 AND Column4=$value4)"
 *
 * $q->addAnd($c);					- $q string = "SELECT * FROM table WHERE Column=$value OR Column2<$value2 OR (Column3=$value3 AND Column4=$value4)"
 */
class Condition{
	const QUOTE_LEFT = 1;
	const QUOTE_RIGHT = 2;
	const QUOTE_BOTH = 3;
	const QUOTE_NONE = 4;

	private $ands = array();
	private $ors = array();

	/**
	 * @return string
	 */
	private function processCondition($left = null, $right=null, $operator=Query::EQUAL, $quote = self::QUOTE_RIGHT){
		$statement = new QueryStatement;

		if($left===null)
			return null;

		//Left can be a Condition
		if($left instanceof self){
			$clause_statement = $left->getClause();
			if(!$clause_statement)
				return null;
			$clause_statement->setString('('.$clause_statement->getString().')');
			return $clause_statement;
		}

		//You can skip $operator and specify $quote with parameter 3
		if(is_int($operator) && !$quote){
			$quote = $operator;
			$operator = Query::EQUAL;
		}

		//Get rid of white-space on sides of $operator
		$operator = trim($operator);

		//Escape $left
		if($quote == self::QUOTE_LEFT || $quote == self::QUOTE_BOTH){
			$statement->addParam($left);
			$left = '?';
		}

		$is_array = false;
		if(is_array($right) || ($right instanceof Query && $right->getLimit()!==1))
			$is_array = true;

		//Right can be a Query, if you're trying to nest queries, like "WHERE MyColumn = (SELECT OtherColumn From MyTable LIMIT 1)"
		if($right instanceof Query){
			if(!$right->getTable())
				throw new Exception("$right does not have a table, so it cannot be nested.");

			$clause_statement = $right->getQuery();
			if(!$clause_statement)
				return null;

			$right = '('.$clause_statement->getString().')';
			$statement->addParams($clause_statement->getParams());
			if($quote != self::QUOTE_LEFT)
				$quote = self::QUOTE_NONE;
		}

		//$right can be an array
		if($is_array){
			//BETWEEN
			if(is_array($right) && count($right)==2 && $operator==Query::BETWEEN){
				$statement->setString("$left $operator ? AND ?");
				$statement->addParams($right);
				return $statement;
			}

			//Convert any sort of equal operator to something suitable
			//for arrays
			switch($operator){
				//Various forms of equal
				case Query::IN:
				case Query::EQUAL:
					$operator=Query::IN;
					break;
				//Various forms of not equal
				case Query::NOT_IN:
				case Query::NOT_EQUAL:
				case Query::ALT_NOT_EQUAL:
					$operator=Query::NOT_IN;
					break;
				default:
					throw new Exception("$operator unknown for comparing an array.");
			}

			//Handle empty arrays
			if(is_array($right) && !$right){
				if($operator==Query::IN){
					$statement->setString('0');
					return $statement;
				}
				elseif($operator==Query::NOT_IN)
					return null;
			}

			//IN or NOT_IN
			if($quote == self::QUOTE_RIGHT || $quote == self::QUOTE_BOTH){
				$statement->addParams($right);
				$placeholders = array();
				foreach($right as $r)
					$placeholders[] = '?';
				$right = '('.implode(',', $placeholders).')';
			}
		}
		else{
			//IS NOT NULL
			if($right===null && ($operator==Query::NOT_EQUAL || $operator==Query::ALT_NOT_EQUAL))
				$operator=Query::IS_NOT_NULL;

			//IS NULL
			elseif($right===null && $operator==Query::EQUAL)
				$operator=Query::IS_NULL;

			if($operator==Query::IS_NULL || $operator==Query::IS_NOT_NULL)
				$right=null;
			elseif($quote == self::QUOTE_RIGHT || $quote == self::QUOTE_BOTH){
				$statement->addParam($right);
				$right = '?';
			}
		}
		$statement->setString("$left $operator $right");

		return $statement;
	}

	/**
	 * Alias of addAnd
	 * @return Condition
	 */
	function add($left, $right=null, $operator=Query::EQUAL, $quote = self::QUOTE_RIGHT){
		return $this->addAnd($left, $right, $operator, $quote);
	}

	/**
	 * Adds an "AND" condition to the array of conditions.
	 * @param $left mixed
	 * @param $right mixed[optional]
	 * @param $operator string[optional]
	 * @param $quote int[optional]
	 * @return Condition
	 */
	function addAnd($left, $right=null, $operator=Query::EQUAL, $quote = self::QUOTE_RIGHT){
		if(is_array($left)){
			foreach($left as $key => $value)
				$this->addAnd($key, $value);
			return $this;
		}

		$condition = $this->processCondition($left, $right, $operator, $quote);
		if($condition)
			$this->ands[] = $condition;
		return $this;
	}

	/**
	 * Adds an "OR" condition to the array of conditions
	 * @param $left mixed
	 * @param $right mixed[optional]
	 * @param $operator string[optional]
	 * @param $quote int[optional]
	 * @return Condition
	 */
	function addOr($left, $right=null, $operator=Query::EQUAL, $quote = self::QUOTE_RIGHT){
		if(is_array($left)){
			foreach($left as $key => $value)
				$this->addOr($key, $value);
			return $this;
		}

		$condition = $this->processCondition($left, $right, $operator, $quote);
		if($condition)
			$this->ors[] = $condition;
		return $this;
	}

	/**
	 * Builds and returns a string representation of $this Condition
	 * @return QueryStatement
	 */
	function getClause(){
		$statement = new QueryStatement;
		$string = "";

		$and_strings = array();
		foreach($this->ands as $and_statement){
			$and_strings[] = $and_statement->getString();
			$statement->addParams($and_statement->getParams());
		}
		if($and_strings) $AND = implode(" AND ", $and_strings);

		$or_strings = array();
		foreach($this->ors as $or_statement){
			$or_strings[] = $or_statement->getString();
			$statement->addParams($or_statement->getParams());
		}
		if($or_strings) $OR = implode(" OR ", $or_strings);

		if($and_strings || $or_strings){
			if($and_strings && $or_strings)
				$string .= " $AND OR $OR ";
			elseif($and_strings)
				$string .= " $AND ";
			elseif($or_strings)
				$string .= " $OR ";
			$statement->setString($string);
			return $statement;
		}
		return null;
	}

	/**
	 * Builds and returns a string representation of $this Condition
	 * @return string
	 */
	function __toString(){
		return (string)$this->getClause();
	}
}