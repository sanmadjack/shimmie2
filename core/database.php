<?php
/**
 * A class for controlled database access
 */
class Database
{
    /**
     * The PDO database connection object, for anyone who wants direct access.
     * @var null|PDO
     */
    private $db = null;
    
    /**
     * @var float
     */
    public $dbtime = 0.0;

    /**
     * Meta info about the database engine.
     * @var DBEngine|null
     */
    private $engine = null;

    /**
     * The currently active cache engine.
     * @var Cache|null
     */
    public $cache = null;

    /**
     * A boolean flag to track if we already have an active transaction.
     * (ie: True if beginTransaction() already called)
     *
     * @var bool
     */
    public $transaction = false;

    /**
     * How many queries this DB object has run
     */
    public $query_count = 0;

    /**
     * For now, only connect to the cache, as we will pretty much certainly
     * need it. There are some pages where all the data is in cache, so the
     * DB connection is on-demand.
     */
    public function __construct()
    {
        $this->cache = new Cache(CACHE_DSN);
    }

    private function connect_db()
    {
        # FIXME: detect ADODB URI, automatically translate PDO DSN

        /*
         * Why does the abstraction layer act differently depending on the
         * back-end? Because PHP is deliberately retarded.
         *
         * http://stackoverflow.com/questions/237367
         */
        $matches = [];
        $db_user=null;
        $db_pass=null;
        if (preg_match("/user=([^;]*)/", DATABASE_DSN, $matches)) {
            $db_user=$matches[1];
        }
        if (preg_match("/password=([^;]*)/", DATABASE_DSN, $matches)) {
            $db_pass=$matches[1];
        }

        // https://bugs.php.net/bug.php?id=70221
        $ka = DATABASE_KA;
        if (version_compare(PHP_VERSION, "6.9.9") == 1 && $this->get_driver_name() == "sqlite") {
            $ka = false;
        }

        $db_params = [
            PDO::ATTR_PERSISTENT => $ka,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ];
        $this->db = new PDO(DATABASE_DSN, $db_user, $db_pass, $db_params);

        $this->connect_engine();
        $this->engine->init($this->db);

        $this->beginTransaction();
    }

    private function connect_engine()
    {
        if (preg_match("/^([^:]*)/", DATABASE_DSN, $matches)) {
            $db_proto=$matches[1];
        } else {
            throw new SCoreException("Can't figure out database engine");
        }

        if ($db_proto === "mysql") {
            $this->engine = new MySQL();
        } elseif ($db_proto === "pgsql") {
            $this->engine = new PostgreSQL();
        } elseif ($db_proto === "sqlite") {
            $this->engine = new SQLite();
        } else {
            die('Unknown PDO driver: '.$db_proto);
        }
    }

    public function beginTransaction()
    {
        if ($this->transaction === false) {
            $this->db->beginTransaction();
            $this->transaction = true;
        }
    }

    public function commit(): bool
    {
        if (!is_null($this->db)) {
            if ($this->transaction === true) {
                $this->transaction = false;
                return $this->db->commit();
            } else {
                throw new SCoreException("<p><b>Database Transaction Error:</b> Unable to call commit() as there is no transaction currently open.");
            }
        } else {
            throw new SCoreException("<p><b>Database Transaction Error:</b> Unable to call commit() as there is no connection currently open.");
        }
    }

    public function rollback(): bool
    {
        if (!is_null($this->db)) {
            if ($this->transaction === true) {
                $this->transaction = false;
                return $this->db->rollback();
            } else {
                throw new SCoreException("<p><b>Database Transaction Error:</b> Unable to call rollback() as there is no transaction currently open.");
            }
        } else {
            throw new SCoreException("<p><b>Database Transaction Error:</b> Unable to call rollback() as there is no connection currently open.");
        }
    }

    public function escape(string $input): string
    {
        if (is_null($this->db)) {
            $this->connect_db();
        }
        return $this->db->Quote($input);
    }

    public function scoreql_to_sql(string $input): string
    {
        if (is_null($this->engine)) {
            $this->connect_engine();
        }
        return $this->engine->scoreql_to_sql($input);
    }

    public function get_driver_name(): string
    {
        if (is_null($this->engine)) {
            $this->connect_engine();
        }
        return $this->engine->name;
    }

    private function count_execs(string $sql, array $inputarray)
    {
        if ((DEBUG_SQL === true) || (is_null(DEBUG_SQL) && @$_GET['DEBUG_SQL'])) {
            $sql = trim(preg_replace('/\s+/msi', ' ', $sql));
            if (isset($inputarray) && is_array($inputarray) && !empty($inputarray)) {
                $text = $sql." -- ".join(", ", $inputarray)."\n";
            } else {
                $text = $sql."\n";
            }
            file_put_contents("data/sql.log", $text, FILE_APPEND);
        }
        if (!is_array($inputarray)) {
            $this->query_count++;
        }
        # handle 2-dimensional input arrays
        elseif (is_array(reset($inputarray))) {
            $this->query_count += sizeof($inputarray);
        } else {
            $this->query_count++;
        }
    }

    private function count_time(string $method, float $start)
    {
        if ((DEBUG_SQL === true) || (is_null(DEBUG_SQL) && @$_GET['DEBUG_SQL'])) {
            $text = $method.":".(microtime(true) - $start)."\n";
            file_put_contents("data/sql.log", $text, FILE_APPEND);
        }
        $this->dbtime += microtime(true) - $start;
    }

    public function execute(string $query, array $args=[]): PDOStatement
    {
        try {
            if (is_null($this->db)) {
                $this->connect_db();
            }
            $this->count_execs($query, $args);
            $stmt = $this->db->prepare(
                "-- " . str_replace("%2F", "/", urlencode(@$_GET['q'])). "\n" .
                $query
            );
            // $stmt = $this->db->prepare($query);
            if (!array_key_exists(0, $args)) {
                foreach ($args as $name=>$value) {
                    if (is_numeric($value)) {
                        $stmt->bindValue(':'.$name, $value, PDO::PARAM_INT);
                    } else {
                        $stmt->bindValue(':'.$name, $value, PDO::PARAM_STR);
                    }
                }
                $stmt->execute();
            } else {
                $stmt->execute($args);
            }
            return $stmt;
        } catch (PDOException $pdoe) {
            throw new SCoreException($pdoe->getMessage()."<p><b>Query:</b> ".$query);
        }
    }

    /**
     * Execute an SQL query and return a 2D array.
     */
    public function get_all(string $query, array $args=[]): array
    {
        $_start = microtime(true);
        $data = $this->execute($query, $args)->fetchAll();
        $this->count_time("get_all", $_start);
        return $data;
    }

    /**
     * Execute an SQL query and return a single row.
     */
    public function get_row(string $query, array $args=[])
    {
        $_start = microtime(true);
        $row = $this->execute($query, $args)->fetch();
        $this->count_time("get_row", $_start);
        return $row ? $row : null;
    }

    /**
     * Execute an SQL query and return the first column of each row.
     */
    public function get_col(string $query, array $args=[]): array
    {
        $_start = microtime(true);
        $stmt = $this->execute($query, $args);
        $res = [];
        foreach ($stmt as $row) {
            $res[] = $row[0];
        }
        $this->count_time("get_col", $_start);
        return $res;
    }

    /**
     * Execute an SQL query and return the the first row => the second row.
     */
    public function get_pairs(string $query, array $args=[]): array
    {
        $_start = microtime(true);
        $stmt = $this->execute($query, $args);
        $res = [];
        foreach ($stmt as $row) {
            $res[$row[0]] = $row[1];
        }
        $this->count_time("get_pairs", $_start);
        return $res;
    }

    /**
     * Execute an SQL query and return a single value.
     */
    public function get_one(string $query, array $args=[])
    {
        $_start = microtime(true);
        $row = $this->execute($query, $args)->fetch();
        $this->count_time("get_one", $_start);
        return $row[0];
    }

    /**
     * Get the ID of the last inserted row.
     */
    public function get_last_insert_id(string $seq): int
    {
        if ($this->engine->name == "pgsql") {
            return $this->db->lastInsertId($seq);
        } else {
            return $this->db->lastInsertId();
        }
    }

    /**
     * Create a table from pseudo-SQL.
     */
    public function create_table(string $name, string $data): void
    {
        if (is_null($this->engine)) {
            $this->connect_engine();
        }
        $data = trim($data, ", \t\n\r\0\x0B");  // mysql doesn't like trailing commas
        $this->execute($this->engine->create_table_sql($name, $data));
    }

    /**
     * Returns the number of tables present in the current database.
     *
     * @throws SCoreException
     */
    public function count_tables(): int
    {
        if (is_null($this->db) || is_null($this->engine)) {
            $this->connect_db();
        }

        if ($this->engine->name === "mysql") {
            return count(
                $this->get_all("SHOW TABLES")
            );
        } elseif ($this->engine->name === "pgsql") {
            return count(
                $this->get_all("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")
            );
        } elseif ($this->engine->name === "sqlite") {
            return count(
                $this->get_all("SELECT name FROM sqlite_master WHERE type = 'table'")
            );
        } else {
            throw new SCoreException("Can't count tables for database type {$this->engine->name}");
        }
    }
}

class MockDatabase extends Database
{
    /** @var int */
    private $query_id = 0;
    /** @var array */
    private $responses = [];
    /** @var \NoCache|null  */
    public $cache = null;

    public function __construct(array $responses = [])
    {
        $this->cache = new NoCache();
        $this->responses = $responses;
    }

    public function execute(string $query, array $params=[]): PDOStatement
    {
        log_debug(
            "mock-database",
            "QUERY: " . $query .
            "\nARGS: " . var_export($params, true) .
            "\nRETURN: " . var_export($this->responses[$this->query_id], true)
        );
        return $this->responses[$this->query_id++];
    }

    public function _execute(string $query, array $params=[])
    {
        log_debug(
            "mock-database",
            "QUERY: " . $query .
            "\nARGS: " . var_export($params, true) .
            "\nRETURN: " . var_export($this->responses[$this->query_id], true)
        );
        return $this->responses[$this->query_id++];
    }

    public function get_all(string $query, array $args=[]): array
    {
        return $this->_execute($query, $args);
    }
    public function get_row(string $query, array $args=[])
    {
        return $this->_execute($query, $args);
    }
    public function get_col(string $query, array $args=[]): array
    {
        return $this->_execute($query, $args);
    }
    public function get_pairs(string $query, array $args=[]): array
    {
        return $this->_execute($query, $args);
    }
    public function get_one(string $query, array $args=[])
    {
        return $this->_execute($query, $args);
    }

    public function get_last_insert_id(string $seq): int
    {
        return $this->query_id;
    }

    public function scoreql_to_sql(string $sql): string
    {
        return $sql;
    }

    public function create_table(string $name, string $def): void
    {
    }

    public function connect_engine()
    {
    }
}