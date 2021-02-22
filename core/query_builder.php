<?php


class QueryBuilder implements IQueryBuilder
{
    private $id;
    private $selectFields = [];
    private $source;
    private $sourceAlias;
    public $joins = [];
    public $criteria;
    public $orders = [];
    public $groups = [];
    public $limit = 0;
    public $offset = 0;

    public function __construct($source, string $sourceAlias = "")
    {
        $this->id = "QB".uniqid();
        $this->criteria = new QueryBuilderCriteria();

        if(!is_a($source, "QueryBuilder")&&
            !is_string($source)) {
            throw new Exception("Source must be a string or another QueryBuilder");
        }
        $this->source = $source;
        $this->sourceAlias = $sourceAlias;
    }

    public function crashIt(): void
    {
        $this->render()->crashIt();
    }

    public function addSelectField(string $name, string $alias = ""): void
    {
        $result = $name;
        if(!empty($alias)) {
            $result .= " $alias";
        }

        $this->selectFields[]= $result;
    }

    public function addJoin(string $type, $source, string $sourceAlias = ""): QueryBuilderJoin
    {
        $join = new QueryBuilderJoin($type,$source, $sourceAlias);
        $this->joins[]=$join;
        return $join;
    }

    public function addOrder(string $field, bool $ascending = true): void
    {
        $order = new QueryBuilderOrder($field, $ascending);
        $this->orders[]=$order;
    }

    public function addGroup(string $field, bool $ascending = true): void
    {
        $order = new QueryBuilderGroup($field, $ascending);
        $this->orders[]=$order;
    }

    public function addOrCriteria(): QueryBuilderCriteria
    {
        $output = new QueryBuilderCriteria("OR");
        $this->criteria[]=$output;
        return $output;
    }

    public function addCriterion($left, string $comparison, $right, array $parameters): void
    {
        $this->criteria->addCriterion($left, $comparison, $right, $parameters);
    }
    public function addManualCriterion(string $statement, array $parameters = []): void
    {
        $this->criteria->addManualCriterion($statement, $parameters);
    }


    public function toSql(bool $omitOrders = false, bool $humanReadable = true): string
    {
        $output = "SELECT ";
        $output .= join(", ", $this->selectFields);
        if($humanReadable) {
            $output .= "\r\n";
        }
        $output .= " FROM ";
        if(is_a($this->source, "IQueryBuilder"))
        {
            $output .= $this->source->toSql($omitOrders, $humanReadable);
        } else {
            $output .= " ".$this->source." ";
        }
        if(!empty($this->sourceAlias)) {
            $output .= " ".$this->sourceAlias." ";
        }

        if($humanReadable) {
            $output .= "\r\n";
        }

        if(!empty($this->joins)) {
            foreach($this->joins as $join) {
                $output .= " ".$join->toSql($omitOrders, $humanReadable)." ";
                if($humanReadable) {
                    $output .= "\r\n";
                }
            }
        }

        if(!$this->criteria->isEmpty()) {
            $output .= " WHERE ";
            $output .= $this->criteria->toSql($omitOrders, $humanReadable);
        }
        if($humanReadable) {
            $output .= "\r\n";
        }

        if(!empty($this->groups)) {
            $output .= " GROUP BY ";
            foreach ($this->groups as $group) {
                $output .= $group->toSql($omitOrders, $humanReadable);
                $output .= ", ";
            }
            $output = substr($output, 0, strlen($output) - 2);
        }

        if(!$omitOrders && !empty($this->orders)) {
            $output .= " ORDER BY ";
            foreach ($this->orders as $order) {
                $output .= $order->toSql($omitOrders, $humanReadable);
                $output .= ", ";
            }
            $output = substr($output, 0, strlen($output) - 2);
        }

        if($this->limit>0) {
            $output .= " LIMIT :" . $this->id . "_limit ";
        }
        if($this->offset>0) {
            $output .= " OFFSET :" . $this->id . "_offset ";
        }


        return $output;
    }

    public function compileParameters(): array
    {
        $output = [];

        if(is_a($this->source, "IQueryBuilder"))
        {
            $output = array_merge($output, $this->source->compileParameters());
        }

        if(!$this->criteria->isEmpty()) {
            $output = array_merge($output, $this->criteria->compileParameters());
        }

        if($this->limit>0) {
            $output[$this->id . "_limit"] = $this->limit;
        }

        if($this->offset>0) {
            $output[$this->id . "_offset"] = $this->offset;
        }


        return $output;
    }

    public function render(bool $omitOrders = false, bool $humanReadable = true): RenderedQuery
    {
        $output = new RenderedQuery();
        $output->sql= $this->toSql($omitOrders, $humanReadable);
        $output->parameters = $this->compileParameters();
        return $output;
    }

    public  function renderForCount(bool $humanReadable = true): RenderedQuery
    {
        $fieldsTemp = $this->selectFields;

        $this->selectFields = ["COUNT(*)"];

        $output = $this->render(true, $humanReadable);

        $this->selectFields = $fieldsTemp;

        return $output;
    }
}

class RenderedQuery
{
    public $sql;
    public $parameters = [];

    public function crashIt(): void
    {
        var_dump($this->parameters);;
        throw new Exception($this->sql);
    }
}

class QueryBuilderCriteria implements IQueryBuilder
{
    private $criteria = [];
    private $operator = "AND";

    public function __construct(string $operator = "AND")
    {
        if($operator!=="AND"&&$operator!=="OR") {
            throw new Exception("operator must be \"AND\" or \"OR\"");
        }
        $this->operator = $operator;
    }

    public function isEmpty(): bool
    {
        return empty($this->criteria);
    }

    public function addManualCriterion(string $statement, array $parameters): void
    {
        $this->criteria[]=new ManualQueryBuilderCriterion($statement, $parameters);
    }

    public function addCriterion($left, string $comparison, $right, array $parameters): void
    {
        $this->criteria[]=new QueryBuilderCriterion($left, $comparison, $right, $parameters);
    }

    public function toSql(bool $omitOrders, bool $humanReadable): string
    {
        $output = "";
        if(empty($this->criteria)) {
            throw new Exception("No criterion set");
        }

        foreach ($this->criteria as $criterion) {
            $output .= $criterion->toSql($omitOrders, $humanReadable);
            $output .= " ".$this->operator." ";
        }
        $output = substr($output, 0, strlen($output) - strlen($this->operator) - 2);

        if(sizeof($this->criteria)>1) {
            $output = " ($output) ";
        } else {
            $output = " $output ";
        }
        return $output;
    }

    public function compileParameters(): array
    {
        $output = [];

        if(empty($this->criteria)) {
            throw new Exception("No criterion set");
        }

        foreach ($this->criteria as $criteria) {
            $output = array_merge($output, $criteria->compileParameters());
        }

        return $output;
    }

}

interface IQueryBuilder
{
    public function toSql(bool $omitOrders, bool $humanReadable): string;
    public function compileParameters(): array;

}

class QueryBuilderCriterion implements  IQueryBuilder
{
    private $left;
    private $right;
    private $comparison;
    private $parameters = [];

    public function __construct($left, string $comparison, $right, array $parameters)
    {
        $this->left = $left;
        $this->comparison = $comparison;
        $this->right = $right;
        $this->parameters = $parameters;
    }

    public function toSql(bool $omitOrders, bool $humanReadable): string
    {
        if(is_a($this->left,"IQueryBuilder")) {
            $output = "(".$this->left->toSql($omitOrders, $humanReadable).")";
        } else {
            $output = $this->left;
        }
        $output .= " ".$this->comparison." ";
        if(is_a($this->right,"IQueryBuilder")) {
            $output .= "(".$this->right->toSql($omitOrders, $humanReadable).")";
        } else {
            $output .= $this->right;
        }
        return $output;
    }

    public function compileParameters(): array
    {
        $output = $this->parameters;
        if(is_a($this->left,"IQueryBuilder")) {
            $output = array_merge($output, $this->left->compileParameters());
        }
        if(is_a($this->right,"IQueryBuilder")) {
            $output = array_merge($output, $this->right->compileParameters());
        }

        return $output;
    }
}

class ManualQueryBuilderCriterion implements  IQueryBuilder
{
    private $statement;
    private $parameters = [];

    public function __construct(string $statement, array $parameters)
    {
        $this->statement = $statement;
        $this->parameters = $parameters;
    }

    public function toSql(bool $omitOrders, bool $humanReadable): string
    {
        return $this->statement;
    }

    public function compileParameters(): array
    {
        return $this->parameters;
    }
}

class QueryBuilderOrder implements  IQueryBuilder
{
    private $field;
    private $ascending;
    private $parameters = [];

    public function __construct(string $field, bool $ascending)
    {
        $this->field = $field;
        $this->ascending = $ascending;
    }

    public function toSql(bool $omitOrders, bool $humanReadable): string
    {
        if(is_a($this->field,"IQueryBuilder")) {
            $output = "(".$this->field->toSql($omitOrders, $humanReadable).")";
        } else {
            $output = $this->field;
        }

        return $output;
    }

    public function compileParameters(): array
    {
        $output = $this->parameters;
        if(is_a($this->field,"IQueryBuilder")) {
            $output = array_merge($output, $this->field->compileParameters());
        }
        return $output;
    }
}

class QueryBuilderGroup implements  IQueryBuilder
{
    private $field;
    private $ascending;
    private $parameters = [];

    public function __construct(string $field, bool $ascending)
    {
        $this->field = $field;
        $this->ascending = $ascending;
    }

    public function toSql(bool $omitOrders, bool $humanReadable): string
    {
        if(is_a($this->field,"IQueryBuilder")) {
            $output = "(".$this->field->toSql($omitOrders, $humanReadable).")";
        } else {
            $output = $this->field;
        }

        return $output;
    }

    public function compileParameters(): array
    {
        $output = $this->parameters;
        if(is_a($this->field,"IQueryBuilder")) {
            $output = array_merge($output, $this->field->compileParameters());
        }
        return $output;
    }
}


class QueryBuilderJoin implements IQueryBuilder
{
    private $source;
    private $sourceAlias;
    private $criteria;
    private $type;

    public function __construct(string $type, $source, string $sourceAlias = "")
    {
        $this->criteria = new QueryBuilderCriteria();

        if(!is_a($source, "QueryBuilder")&&
            !is_string($source)) {
            throw new Exception("Source must be a string or another QueryBuilder");
        }
        $this->source = $source;
        $this->sourceAlias = $sourceAlias;

        if($type!=="INNER"&&$type!=="LEFT"&&$type!=="RIGHT"&&$type!=="LEFT OUTER"&&$type!=="RIGHT OUTER") {
            throw new Exception("Join type \"$type\" not recognized");
        }
        $this->type = $type;
    }

    public function addOrCriteria(): QueryBuilderCriteria
    {
        $output = new QueryBuilderCriteria("OR");
        $this->criteria[]=$output;
        return $output;
    }

    public function addCriterion($left, string $comparison, $right, array $parameters): void
    {
        $this->criteria->addCriterion($left, $comparison, $right, $parameters);
    }
    public function addManualCriterion(string $statement, array $parameters = []): void
    {
        $this->criteria->addManualCriterion($statement, $parameters);
    }


    public function toSql(bool $omitOrders, bool $humanReadable): string
    {
        $output = " ".$this->type." JOIN ";

        if(is_a($this->source,"IQueryBuilder")) {
            $output .= "(".$this->source->toSql($omitOrders, $humanReadable).")";
        } else {
            $output .= $this->source;
        }
        if(!empty($this->sourceAlias)) {
            $output .= " ".$this->sourceAlias." ";
        }

        $output .= " ON ". $this->criteria->toSql($omitOrders, $humanReadable);

        return $output;
    }

    public function compileParameters(): array
    {
        return $this->criteria->compileParameters();
    }
}

