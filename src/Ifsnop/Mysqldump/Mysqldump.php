<?php

/**
 * PHP version of mysqldump cli that comes with MySQL.
 *
 * Modified for Database Janitor (performance, coding standards, etc), unlikely
 * to be merged upstream due potential major breaking changes.
 *
 * @category Library
 * @package Ifsnop\Mysqldump
 * @author Diego Torres <ifsnop@github.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link https://github.com/ifsnop/mysqldump-php
 * @link https://github.com/gmemstr/database-janitor
 */

namespace Ifsnop\Mysqldump;

use Exception;
use PDO;
use PDOException;

/**
 * Class Mysqldump.
 *
 * @package Ifsnop\Mysqldump
 */
class Mysqldump {

  const MAXLINESIZE = 1000000;

  const GZIP = 'Gzip';
  const BZIP2 = 'Bzip2';
  const NONE = 'None';

  const UTF8 = 'utf8';
  const UTF8MB4 = 'utf8mb4';

  /**
   * Database username.
   *
   * @var string
   */
  public $user;

  /**
   * Database password.
   *
   * @var string
   */
  public $pass;

  /**
   * Connection string for PDO.
   *
   * @var string
   */
  public $dsn;

  /**
   * Destination filename, defaults to stdout.
   *
   * @var string
   */
  public $fileName = 'php://output';

  private $tables = [];
  private $views = [];
  private $triggers = [];
  private $procedures = [];
  private $events = [];
  private $dbHandler = NULL;
  private $dbType = '';
  private $compressManager;
  private $typeAdapter;
  private $dumpSettings = [];
  private $pdoSettings = [];
  private $version;
  private $tableColumnTypes = [];
  private $transformColumnValueCallable;

  /**
   * Database name, parsed from dsn.
   *
   * @var string
   */
  private $dbName;

  /**
   * Host name, parsed from dsn.
   *
   * @var string
   */
  private $host;

  /**
   * Dsn string parsed as an array.
   *
   * @var array
   */
  private $dsnArray = [];

  /**
   * Constructor of Mysqldump.
   *
   * Note that in the case of an SQLite database connection, the filename must
   * be in the $db parameter.
   *
   * @param string $dsn
   *   PDO DSN connection string.
   * @param string $user
   *   SQL account username.
   * @param string $pass
   *   SQL account password.
   * @param array $dumpSettings
   *   SQL database settings.
   * @param array $pdoSettings
   *   PDO configured attributes.
   *
   * @throws \Exception
   */
  public function __construct(
    $dsn = '',
    $user = '',
    $pass = '',
    $dumpSettings = [],
    $pdoSettings = []
  ) {
    $dumpSettingsDefault = [
      'include-tables'             => [],
      'exclude-tables'             => [],
      'compress'                   => Mysqldump::NONE,
      'init_commands'              => [],
      'no-data'                    => [],
      'reset-auto-increment'       => FALSE,
      'add-drop-database'          => FALSE,
      'add-drop-table'             => FALSE,
      'add-drop-trigger'           => TRUE,
      'add-locks'                  => TRUE,
      'complete-insert'            => FALSE,
      'databases'                  => FALSE,
      'default-character-set'      => Mysqldump::UTF8,
      'disable-keys'               => TRUE,
      'extended-insert'            => TRUE,
      'events'                     => FALSE,
      'hex-blob'                   => TRUE, /* faster than escaped content */
      'insert-ignore'              => FALSE,
      'net_buffer_length'          => self::MAXLINESIZE,
      'no-autocommit'              => TRUE,
      'no-create-info'             => FALSE,
      'lock-tables'                => TRUE,
      'routines'                   => FALSE,
      'single-transaction'         => TRUE,
      'skip-triggers'              => FALSE,
      'skip-tz-utc'                => FALSE,
      'skip-comments'              => FALSE,
      'skip-dump-date'             => FALSE,
      'skip-definer'               => FALSE,
      'where'                      => '',
      'keep-data'                  => [],
      /* deprecated */
      'disable-foreign-keys-check' => TRUE,
    ];

    $pdoSettingsDefault = [
      PDO::ATTR_PERSISTENT => TRUE,
      PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
    ];

    $this->user = $user;
    $this->pass = $pass;
    $this->parseDsn($dsn);

    // This drops MYSQL dependency, only use the constant if it's defined.
    if ("mysql" === $this->dbType) {
      $pdoSettingsDefault[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = FALSE;
    }

    $this->pdoSettings = array_replace_recursive($pdoSettingsDefault, $pdoSettings);
    $this->dumpSettings = array_replace_recursive($dumpSettingsDefault, $dumpSettings);
    $this->dumpSettings['init_commands'][] = 'SET NAMES ' . $this->dumpSettings['default-character-set'];

    if (FALSE === $this->dumpSettings['skip-tz-utc']) {
      $this->dumpSettings['init_commands'][] = "SET TIME_ZONE='+00:00'";
    }

    $diff = array_diff(array_keys($this->dumpSettings), array_keys($dumpSettingsDefault));
    if (count($diff) > 0) {
      throw new Exception("Unexpected value in dumpSettings: (" . implode(",", $diff) . ")");
    }

    if (!is_array($this->dumpSettings['include-tables']) ||
      !is_array($this->dumpSettings['exclude-tables'])) {
      throw new Exception("Include-tables and exclude-tables should be arrays");
    }

    // Dump the same views as tables, mimic mysqldump behaviour.
    $this->dumpSettings['include-views'] = $this->dumpSettings['include-tables'];

    // Create a new compressManager to manage compressed output.
    $this->compressManager = CompressManagerFactory::create($this->dumpSettings['compress']);
  }

  /**
   * Destructor of Mysqldump. Unsets dbHandlers and database objects.
   */
  public function __destruct() {
    $this->dbHandler = NULL;
  }

  /**
   * Parse DSN string and extract dbname value.
   *
   * Several examples of a DSN string
   *   mysql:host=localhost;dbname=testdb
   *   mysql:host=localhost;port=3307;dbname=testdb
   *   mysql:unix_socket=/tmp/mysql.sock;dbname=testdb.
   *
   * @param string $dsn
   *   Dsn string to parse.
   *
   * @return bool
   *   TRUE if the DSN string is valid.
   *
   * @throws \Exception
   */
  private function parseDsn($dsn) {
    if (empty($dsn) || (FALSE === ($pos = strpos($dsn, ":")))) {
      throw new Exception('Empty DSN string');
    }

    $this->dsn    = $dsn;
    $this->dbType = strtolower(substr($dsn, 0, $pos));

    if (empty($this->dbType)) {
      throw new Exception('Missing database type from DSN string');
    }

    $dsn = substr($dsn, $pos + 1);

    foreach (explode(";", $dsn) as $kvp) {
      $kvpArr = explode("=", $kvp);
      $this->dsnArray[strtolower($kvpArr[0])] = $kvpArr[1];
    }

    if (empty($this->dsnArray['host']) &&
      empty($this->dsnArray['unix_socket'])) {
      throw new Exception("Missing host from DSN string");
    }
    $this->host = (!empty($this->dsnArray['host'])) ?
      $this->dsnArray['host'] : $this->dsnArray['unix_socket'];

    if (empty($this->dsnArray['dbname'])) {
      throw new Exception("Missing database name from DSN string");
    }

    $this->dbName = $this->dsnArray['dbname'];

    return TRUE;
  }

  /**
   * Connect with PDO.
   */
  private function connect() {
    // Connecting with PDO.
    try {
      switch ($this->dbType) {
        case 'sqlite':
          $this->dbHandler = @new PDO("sqlite:" . $this->dbName, NULL, NULL, $this->pdoSettings);
          break;

        case 'mysql':
        case 'pgsql':
        case 'dblib':
          $this->dbHandler = @new PDO(
            $this->dsn,
            $this->user,
            $this->pass,
            $this->pdoSettings
          );
          // Execute init commands once connected.
          foreach ($this->dumpSettings['init_commands'] as $stmt) {
            $this->dbHandler->exec($stmt);
          }
          // Store server version.
          $this->version = $this->dbHandler->getAttribute(PDO::ATTR_SERVER_VERSION);
          break;

        default:
          throw new Exception("Unsupported database type (" . $this->dbType . ")");
      }
    }
    catch (PDOException $e) {
      throw new Exception(
        "Connection to " . $this->dbType . " failed with message: " .
        $e->getMessage()
      );
    }

    if (is_null($this->dbHandler)) {
      throw new Exception("Connection to " . $this->dbType . "failed");
    }

    $this->dbHandler->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
    $this->typeAdapter = TypeAdapterFactory::create($this->dbType, $this->dbHandler, $this->dumpSettings);
  }

  /**
   * Primary function, triggers dumping.
   *
   * @param string $filename
   *   Name of file to write sql dump to.
   *
   * @throws \Exception
   */
  public function start($filename = '') {
    // Output file can be redefined here.
    if (!empty($filename)) {
      $this->fileName = $filename;
    }

    // Connect to database.
    $this->connect();

    // Create output file.
    $this->compressManager->open($this->fileName);

    // Write some basic info to output file.
    if (!$this->dumpSettings['skip-comments']) {
      $this->compressManager->write($this->getDumpFileHeader());
    }

    // Store server settings and use sanner defaults to dump.
    $this->compressManager->write(
      $this->typeAdapter->backup_parameters()
    );

    if ($this->dumpSettings['databases']) {
      $this->compressManager->write(
        $this->typeAdapter->getDatabaseHeader($this->dbName)
      );
      if ($this->dumpSettings['add-drop-database']) {
        $this->compressManager->write(
          $this->typeAdapter->add_drop_database($this->dbName)
        );
      }
    }

    // Get table, view, trigger, procedures and events structures from database.
    $this->getDatabaseStructureTables();
    $this->getDatabaseStructureViews();
    $this->getDatabaseStructureTriggers();
    $this->getDatabaseStructureProcedures();
    $this->getDatabaseStructureEvents();

    if ($this->dumpSettings['databases']) {
      $this->compressManager->write(
        $this->typeAdapter->databases($this->dbName)
      );
    }

    // If there still are some tables/views in include-tables array, that means
    // that some tables or views weren't found.
    // Give proper error and exit.
    // This check will be removed once include-tables supports regexps.
    if (0 < count($this->dumpSettings['include-tables'])) {
      $name = implode(",", $this->dumpSettings['include-tables']);
      throw new Exception("Table (" . $name . ") not found in database");
    }

    $this->exportTables();
    $this->exportTriggers();
    $this->exportViews();
    $this->exportProcedures();
    $this->exportEvents();

    // Restore saved parameters.
    $this->compressManager->write(
      $this->typeAdapter->restore_parameters()
    );
    // Write some stats to output file.
    if (!$this->dumpSettings['skip-comments']) {
      $this->compressManager->write($this->getDumpFileFooter());
    }
    // Close output file.
    $this->compressManager->close();
  }

  /**
   * Returns header for dump file.
   *
   * @return string
   *   Header for the top of the dump file.
   */
  private function getDumpFileHeader() {
    // Some info about software, source and time.
    $header = "-- mysqldump-php https://github.com/ifsnop/mysqldump-php" . PHP_EOL .
      "--" . PHP_EOL .
      "-- Host: {$this->host}\tDatabase: {$this->dbName}" . PHP_EOL .
      "-- ------------------------------------------------------" . PHP_EOL;

    if (!empty($this->version)) {
      $header .= "-- Server version \t" . $this->version . PHP_EOL;
    }

    if (!$this->dumpSettings['skip-dump-date']) {
      $header .= "-- Date: " . date('r') . PHP_EOL . PHP_EOL;
    }
    return $header;
  }

  /**
   * Returns footer for dump file.
   *
   * @return string
   *   Dump file footer.
   */
  private function getDumpFileFooter() {
    $footer = '-- Dump completed';
    if (!$this->dumpSettings['skip-dump-date']) {
      $footer .= ' on: ' . date('r');
    }
    $footer .= PHP_EOL;

    return $footer;
  }

  /**
   * Reads table names from database.
   *
   * Fills $this->tables array so they will be dumped later.
   */
  private function getDatabaseStructureTables() {
    // Listing all tables from database.
    if (empty($this->dumpSettings['include-tables'])) {
      // Include all tables for now, blacklisting happens later.
      foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->dbName)) as $row) {
        $this->tables[] = current($row);
      }
    }
    else {
      // Include only the tables mentioned in include-tables.
      foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->dbName)) as $row) {
        if (in_array(current($row), $this->dumpSettings['include-tables'], TRUE)) {
          $this->tables[] = current($row);
          $elem = array_search(
            current($row),
            $this->dumpSettings['include-tables'],
            FALSE
          );
          unset($this->dumpSettings['include-tables'][$elem]);
        }
      }
    }
  }

  /**
   * Reads view names from database.
   *
   * Fills $this->tables array so they will be dumped later.
   */
  private function getDatabaseStructureViews() {
    // Listing all views from database.
    if (empty($this->dumpSettings['include-views'])) {
      // Include all views for now, blacklisting happens later.
      foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->dbName)) as $row) {
        $this->views[] = current($row);
      }
    }
    else {
      // Include only the tables mentioned in include-tables.
      foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->dbName)) as $row) {
        if (in_array(current($row), $this->dumpSettings['include-views'], TRUE)) {
          $this->views[] = current($row);
          $elem          = array_search(
            current($row),
            $this->dumpSettings['include-views'],
            FALSE
          );
          unset($this->dumpSettings['include-views'][$elem]);
        }
      }
    }
  }

  /**
   * Reads trigger names from database.
   *
   * Fills $this->tables array so they will be dumped later.
   */
  private function getDatabaseStructureTriggers() {
    // Listing all triggers from database.
    if (FALSE === $this->dumpSettings['skip-triggers']) {
      foreach ($this->dbHandler->query($this->typeAdapter->show_triggers($this->dbName)) as $row) {
        $this->triggers[] = $row['Trigger'];
      }
    }
  }

  /**
   * Reads procedure names from database.
   *
   * Fills $this->tables array so they will be dumped later.
   */
  private function getDatabaseStructureProcedures() {
    // Listing all procedures from database.
    if ($this->dumpSettings['routines']) {
      foreach ($this->dbHandler->query($this->typeAdapter->show_procedures($this->dbName)) as $row) {
        $this->procedures[] = $row['procedure_name'];
      }
    }
  }

  /**
   * Reads event names from database.
   *
   * Fills $this->tables array so they will be dumped later.
   */
  private function getDatabaseStructureEvents() {
    // Listing all events from database.
    if ($this->dumpSettings['events']) {
      foreach ($this->dbHandler->query($this->typeAdapter->show_events($this->dbName)) as $row) {
        $this->events[] = $row['event_name'];
      }
    }
  }

  /**
   * Compare if $table name matches with a definition inside $arr.
   *
   * @param string $table
   *   Table name.
   * @param array $arr
   *   Array with strings or patterns.
   *
   * @return bool
   *   Whether or not the table name was found in the array.
   */
  private function matches($table, array $arr) {
    $match = FALSE;

    foreach ($arr as $pattern) {
      if ('/' !== $pattern[0]) {
        continue;
      }
      if (1 == preg_match($pattern, $table)) {
        $match = TRUE;
      }
    }

    return in_array($table, $arr, FALSE) || $match;
  }

  /**
   * Exports all the tables selected from database.
   */
  private function exportTables() {
    // Exporting tables one by one.
    foreach ($this->tables as $table) {
      if ($this->matches($table, $this->dumpSettings['exclude-tables'])) {
        continue;
      }
      $this->getTableStructure($table);
      // Don't break compatibility with old trigger.
      if (FALSE === $this->dumpSettings['no-data']) {
        $this->listValues($table);
      }
      elseif (TRUE === $this->dumpSettings['no-data']
        || $this->matches($table, $this->dumpSettings['no-data'])) {
        continue;
      }
      else {
        $this->listValues($table);
      }
    }
  }

  /**
   * Exports all the views found in database.
   */
  private function exportViews() {
    if (FALSE === $this->dumpSettings['no-create-info']) {
      // Exporting views one by one.
      foreach ($this->views as $view) {
        if ($this->matches($view, $this->dumpSettings['exclude-tables'])) {
          continue;
        }
        $this->tableColumnTypes[$view] = $this->getTableColumnTypes($view);
        $this->getViewStructureTable($view);
      }
      foreach ($this->views as $view) {
        if ($this->matches($view, $this->dumpSettings['exclude-tables'])) {
          continue;
        }
        $this->getViewStructureView($view);
      }
    }
  }

  /**
   * Exports all the triggers found in database.
   */
  private function exportTriggers() {
    // Exporting triggers one by one.
    foreach ($this->triggers as $trigger) {
      $this->getTriggerStructure($trigger);
    }
  }

  /**
   * Exports all the procedures found in database.
   */
  private function exportProcedures() {
    // Exporting triggers one by one.
    foreach ($this->procedures as $procedure) {
      $this->getProcedureStructure($procedure);
    }
  }

  /**
   * Exports all the events found in database.
   */
  private function exportEvents() {
    // Exporting triggers one by one.
    foreach ($this->events as $event) {
      $this->getEventStructure($event);
    }
  }

  /**
   * Table structure extractor.
   *
   * @todo move specific mysql code to typeAdapter
   *
   * @param string $tableName
   *   Name of table to export.
   *
   * @throws \Exception
   */
  private function getTableStructure($tableName) {
    if (!$this->dumpSettings['no-create-info']) {
      $ret = '';
      if (!$this->dumpSettings['skip-comments']) {
        $ret = "--" . PHP_EOL .
          "-- Table structure for table `$tableName`" . PHP_EOL .
          "--" . PHP_EOL . PHP_EOL;
      }
      $stmt = $this->typeAdapter->show_create_table($tableName);
      foreach ($this->dbHandler->query($stmt) as $r) {
        $this->compressManager->write($ret);
        if ($this->dumpSettings['add-drop-table']) {
          $this->compressManager->write(
            $this->typeAdapter->drop_table($tableName)
          );
        }
        $this->compressManager->write(
          $this->typeAdapter->create_table($r)
        );
        break;
      }
    }
    $this->tableColumnTypes[$tableName] = $this->getTableColumnTypes($tableName);
  }

  /**
   * Store column types to create data dumps and for Stand-In tables.
   *
   * @param string $tableName
   *   Name of table to export.
   *
   * @return array
   *   Type column types detailed.
   */
  private function getTableColumnTypes($tableName) {
    $columnTypes = [];
    $columns     = $this->dbHandler->query(
      $this->typeAdapter->show_columns($tableName)
    );
    $columns->setFetchMode(PDO::FETCH_ASSOC);

    foreach ($columns as $key => $col) {
      $types                      = $this->typeAdapter->parseColumnType($col);
      $columnTypes[$col['Field']] = [
        'is_numeric' => $types['is_numeric'],
        'is_blob'    => $types['is_blob'],
        'type'       => $types['type'],
        'type_sql'   => $col['Type'],
        'is_virtual' => $types['is_virtual'],
      ];
    }

    return $columnTypes;
  }

  /**
   * View structure extractor, create table (avoids cyclic references).
   *
   * @todo move mysql specific code to typeAdapter.
   *
   * @param string $viewName
   *   Name of view to export.
   *
   * @throws \Exception
   */
  private function getViewStructureTable($viewName) {
    if (!$this->dumpSettings['skip-comments']) {
      $ret = "--" . PHP_EOL .
        "-- Stand-In structure for view `${viewName}`" . PHP_EOL .
        "--" . PHP_EOL . PHP_EOL;
      $this->compressManager->write($ret);
    }
    $stmt = $this->typeAdapter->show_create_view($viewName);

    // create views as tables, to resolve dependencies
    foreach ($this->dbHandler->query($stmt) as $r) {
      if ($this->dumpSettings['add-drop-table']) {
        $this->compressManager->write(
          $this->typeAdapter->drop_view($viewName)
        );
      }

      $this->compressManager->write(
        $this->createStandInTable($viewName)
      );
      break;
    }
  }

  /**
   * Write a create table statement for the table Stand-In.
   *
   * Show create table would return a create algorithm when used on a view.
   *
   * @param string $viewName
   *   Name of view to export.
   *
   * @return string
   *   Create statement.
   */
  public function createStandInTable($viewName) {
    $ret = [];
    foreach ($this->tableColumnTypes[$viewName] as $k => $v) {
      $ret[] = "`${k}` ${v['type_sql']}";
    }
    $ret = implode(PHP_EOL . ",", $ret);

    $ret = "CREATE TABLE IF NOT EXISTS `$viewName` (" .
      PHP_EOL . $ret . PHP_EOL . ");" . PHP_EOL;

    return $ret;
  }

  /**
   * View structure extractor, create view.
   *
   * @todo move mysql specific code to typeAdapter.
   *
   * @param string $viewName
   *   Name of view to export.
   *
   * @throws \Exception
   */
  private function getViewStructureView($viewName) {
    if (!$this->dumpSettings['skip-comments']) {
      $ret = "--" . PHP_EOL .
        "-- View structure for view `${viewName}`" . PHP_EOL .
        "--" . PHP_EOL . PHP_EOL;
      $this->compressManager->write($ret);
    }
    $stmt = $this->typeAdapter->show_create_view($viewName);

    // Create views, to resolve dependencies, replacing tables with views.
    foreach ($this->dbHandler->query($stmt) as $r) {
      // Because we must replace table with view, we should delete it.
      // @TODO: Keep this where it is? -g
      $this->compressManager->write(
        $this->typeAdapter->drop_view($viewName)
      );
      $this->compressManager->write(
        $this->typeAdapter->create_view($r)
      );
      break;

    }
  }

  /**
   * Trigger structure extractor.
   *
   * @param string $triggerName
   *   Name of trigger to export.
   *
   * @throws \Exception
   */
  private function getTriggerStructure($triggerName) {
    $stmt = $this->typeAdapter->show_create_trigger($triggerName);
    foreach ($this->dbHandler->query($stmt) as $r) {
      if ($this->dumpSettings['add-drop-trigger']) {
        $this->compressManager->write(
          $this->typeAdapter->add_drop_trigger($triggerName)
        );
      }
      $this->compressManager->write(
        $this->typeAdapter->create_trigger($r)
      );
    }
  }

  /**
   * Procedure structure extractor.
   *
   * @param string $procedureName
   *   Name of procedure to export.
   *
   * @throws \Exception
   */
  private function getProcedureStructure($procedureName) {
    if (!$this->dumpSettings['skip-comments']) {
      $ret = "--" . PHP_EOL .
        "-- Dumping routines for database '" . $this->dbName . "'" . PHP_EOL .
        "--" . PHP_EOL . PHP_EOL;
      $this->compressManager->write($ret);
    }
    $stmt = $this->typeAdapter->show_create_procedure($procedureName);
    foreach ($this->dbHandler->query($stmt) as $r) {
      $this->compressManager->write(
        $this->typeAdapter->create_procedure($r)
      );
    }
  }

  /**
   * Event structure extractor.
   *
   * @param string $eventName
   *   Name of event to export.
   *
   * @throws \Exception
   */
  private function getEventStructure($eventName) {
    if (!$this->dumpSettings['skip-comments']) {
      $ret = "--" . PHP_EOL .
        "-- Dumping events for database '" . $this->dbName . "'" . PHP_EOL .
        "--" . PHP_EOL . PHP_EOL;
      $this->compressManager->write($ret);
    }
    $stmt = $this->typeAdapter->show_create_event($eventName);
    foreach ($this->dbHandler->query($stmt) as $r) {
      $this->compressManager->write(
        $this->typeAdapter->create_event($r)
      );
    }
  }

  /**
   * Prepare values for output.
   *
   * @param string $tableName
   *   Name of table which contains rows.
   * @param array $row
   *   Associative array of column names and values to be quoted.
   *
   * @return array
   *   Values.
   */
  private function prepareColumnValues($tableName, $row) {
    $ret = '';
    $columnTypes = $this->tableColumnTypes[$tableName];
    foreach ($row as $colName => $colValue) {
      $colValue = $this->hookTransformColumnValue($tableName, $colName, $colValue, $row);
      $ret .= $this->escape($colValue, $columnTypes[$colName]) . ',';
    }
    $ret = rtrim($ret, ',');

    return $ret;
  }

  /**
   * Escape values with quotes when needed.
   *
   * @param string $colValue
   *   Column value.
   * @param string $colType
   *   Column type.
   *
   * @return string
   *   Escaped string.
   */
  private function escape($colValue, $colType) {
    if (is_null($colValue)) {
      return "NULL";
    }
    elseif ($this->dumpSettings['hex-blob'] && $colType['is_blob']) {
      if ($colType['type'] == 'bit' || !empty($colValue)) {
        return "0x${colValue}";
      }
      else {
        return "''";
      }
    }
    elseif ($colType['is_numeric']) {
      return $colValue;
    }

    return $this->dbHandler->quote($colValue);
  }

  /**
   * Set a callable that will will be used to transform column values.
   *
   * @param callable $callable
   *   Function transform col value.
   */
  public function setTransformColumnValueHook(callable $callable) {
    $this->transformColumnValueCallable = $callable;
  }

  /**
   * Give extending classes an opportunity to transform column values.
   *
   * @param string $tableName
   *   Name of table which contains rows.
   * @param string $colName
   *   Name of the column in question.
   * @param string $colValue
   *   Value of the column in question.
   *
   * @return string
   *   Processed string.
   */
  protected function hookTransformColumnValue($tableName, $colName, $colValue, $row) {
    if (!$this->transformColumnValueCallable) {
      return $colValue;
    }

    return call_user_func_array($this->transformColumnValueCallable, [
      $tableName,
      $colName,
      $colValue,
      $row,
    ]);
  }

  /**
   * Table rows extractor.
   *
   * @param string $tableName
   *   Name of table to export.
   *
   * @throws \Exception
   */
  private function listValues($tableName) {
    $this->prepareListValues($tableName);

    $onlyOnce = TRUE;
    $lineSize = 0;

    // colStmt is used to form a query to obtain row values.
    $colStmt = $this->getColumnStmt($tableName);
    // colNames is used to get the name of the columns when using
    // complete-insert.
    $colNames = $this->dumpSettings['complete-insert'] ? $this->getColumnNames($tableName) : [];
    $colNames = implode(", ", $colNames);

    $stmt = "SELECT " . implode(",", $colStmt) . " FROM `$tableName`";

    if ($this->dumpSettings['where']) {
      $stmt .= " WHERE {$this->dumpSettings['where']}";
    }
    if (isset($this->dumpSettings['keep-data'][$tableName])) {
      $stmt .= " WHERE {$this->dumpSettings['keep-data'][$tableName]['col']} IN ({$this->dumpSettings['keep-data'][$tableName]['rows']})";
    }
    $resultSet = $this->dbHandler->query($stmt);
    $resultSet->setFetchMode(PDO::FETCH_ASSOC);

    $ignore = $this->dumpSettings['insert-ignore'] ? '  IGNORE' : '';

    foreach ($resultSet as $row) {
      $vals = $this->prepareColumnValues($tableName, $row);
      if ($onlyOnce || !$this->dumpSettings['extended-insert']) {
        if ($this->dumpSettings['complete-insert']) {
          $lineSize += $this->compressManager->write(
            "INSERT$ignore INTO `$tableName` (" .
            $colNames .
            ") VALUES (" . $vals . ")"
          );
        }
        else {
          $lineSize += $this->compressManager->write(
            "INSERT$ignore INTO `$tableName` VALUES (" . $vals . ")"
          );
        }
        $onlyOnce = FALSE;
      }
      else {
        $lineSize += $this->compressManager->write(",(" . $vals . ")");
      }
      if (($lineSize > $this->dumpSettings['net_buffer_length']) ||
        !$this->dumpSettings['extended-insert']) {
        $onlyOnce = TRUE;
        $lineSize = $this->compressManager->write(";" . PHP_EOL);
      }
    }
    $resultSet->closeCursor();

    if (!$onlyOnce) {
      $this->compressManager->write(";" . PHP_EOL);
    }

    $this->endListValues($tableName);
  }

  /**
   * Table rows extractor, append information prior to dump.
   *
   * @param string $tableName
   *   Name of table to export.
   *
   * @throws \Exception
   */
  public function prepareListValues($tableName) {
    if (!$this->dumpSettings['skip-comments']) {
      $this->compressManager->write(
        "--" . PHP_EOL .
        "-- Dumping data for table `$tableName`" . PHP_EOL .
        "--" . PHP_EOL . PHP_EOL
      );
    }

    if ($this->dumpSettings['single-transaction']) {
      $this->dbHandler->exec($this->typeAdapter->setup_transaction());
      $this->dbHandler->exec($this->typeAdapter->start_transaction());
    }

    if ($this->dumpSettings['lock-tables']) {
      $this->typeAdapter->lock_table($tableName);
    }

    if ($this->dumpSettings['add-locks']) {
      $this->compressManager->write(
        $this->typeAdapter->start_add_lock_table($tableName)
      );
    }

    if ($this->dumpSettings['disable-keys']) {
      $this->compressManager->write(
        $this->typeAdapter->start_add_disable_keys($tableName)
      );
    }

    // Disable autocommit for faster reload.
    if ($this->dumpSettings['no-autocommit']) {
      $this->compressManager->write(
        $this->typeAdapter->start_disable_autocommit()
      );
    }
  }

  /**
   * Table rows extractor, close locks and commits after dump.
   *
   * @param string $tableName
   *   Name of table to export.
   *
   * @throws \Exception
   */
  public function endListValues($tableName) {
    if ($this->dumpSettings['disable-keys']) {
      $this->compressManager->write(
        $this->typeAdapter->end_add_disable_keys($tableName)
      );
    }

    if ($this->dumpSettings['add-locks']) {
      $this->compressManager->write(
        $this->typeAdapter->end_add_lock_table($tableName)
      );
    }

    if ($this->dumpSettings['single-transaction']) {
      $this->dbHandler->exec($this->typeAdapter->commit_transaction());
    }

    if ($this->dumpSettings['lock-tables']) {
      $this->typeAdapter->unlock_table($tableName);
    }

    // Commit to enable autocommit.
    if ($this->dumpSettings['no-autocommit']) {
      $this->compressManager->write(
        $this->typeAdapter->end_disable_autocommit()
      );
    }

    $this->compressManager->write(PHP_EOL);
  }

  /**
   * Build SQL List of all columns on current table.
   *
   * Will be used for selecting.
   *
   * @param string $tableName
   *   Name of table to get columns.
   *
   * @return array
   *   SQL sentence with columns for select.
   */
  public function getColumnStmt($tableName) {
    $colStmt = [];
    foreach ($this->tableColumnTypes[$tableName] as $colName => $colType) {
      if ($colType['type'] == 'bit' && $this->dumpSettings['hex-blob']) {
        $colStmt[] = "LPAD(HEX(`${colName}`),2,'0') AS `${colName}`";
      }
      elseif ($colType['is_blob'] && $this->dumpSettings['hex-blob']) {
        $colStmt[] = "HEX(`${colName}`) AS `${colName}`";
      }
      elseif ($colType['is_virtual']) {
        $this->dumpSettings['complete-insert'] = TRUE;
        continue;
      }
      else {
        $colStmt[] = "`${colName}`";
      }
    }

    return $colStmt;
  }

  /**
   * Build SQL List of all columns on current table.
   *
   * Will be used for inserting.
   *
   * @param string $tableName
   *   Name of table to get columns.
   *
   * @return array
   *   Columns for sql sentence for insert.
   */
  public function getColumnNames($tableName) {
    $colNames = [];
    foreach ($this->tableColumnTypes[$tableName] as $colName => $colType) {
      if ($colType['is_virtual']) {
        $this->dumpSettings['complete-insert'] = TRUE;
        continue;
      }
      else {
        $colNames[] = "`${colName}`";
      }
    }
    return $colNames;
  }

}

/**
 * Enum with all available compression methods.
 */
abstract class CompressMethod {

  public static $enums = [
    "None",
  ];

  /**
   * Checks if compression method is valid.
   *
   * @param string $c
   *   Compression method.
   *
   * @return bool
   *   Whether compression method is valid.
   */
  public static function isValid($c) {
    return in_array($c, self::$enums, TRUE);
  }

}

/**
 * Class CompressManagerFactory.
 *
 * @package Ifsnop\Mysqldump
 */
abstract class CompressManagerFactory {

  /**
   * Creation function for this class.
   *
   * @param string $c
   *   Compression method.
   *
   * @return CompressNone
   *   Type of compression.
   *
   * @throws \Exception
   */
  public static function create($c) {
    $c = ucfirst(strtolower($c));
    if (!CompressMethod::isValid($c)) {
      throw new Exception("Compression method ($c) is not defined yet");
    }

    $method = __NAMESPACE__ . "\\" . "Compress" . $c;

    return new $method();
  }

}

/**
 * Class CompressNone
 *
 * @package Ifsnop\Mysqldump
 */
class CompressNone extends CompressManagerFactory {

  private $fileHandler;

  /**
   * Opens file to write to.
   *
   * @param string $filename
   *   Filename to write to.
   *
   * @return bool
   *   True if file was able to be opened.
   *
   * @throws \Exception
   */
  public function open($filename) {
    $this->fileHandler = fopen($filename, "wb");
    if (FALSE === $this->fileHandler) {
      throw new Exception("Output file is not writable");
    }

    return TRUE;
  }

  /**
   * Write bytes to file.
   *
   * @param string $str
   *   String to write to file.
   *
   * @return int
   *   Returns bytes written.
   *
   * @throws \Exception
   */
  public function write($str) {
    if (FALSE === ($bytesWritten = fwrite($this->fileHandler, $str))) {
      throw new Exception("Writting to file failed! Probably, there is no more free space left?");
    }
    return $bytesWritten;
  }

  /**
   * Close file.
   *
   * @return bool
   *   Whether file was successfully closed.
   */
  public function close() {
    return fclose($this->fileHandler);
  }

}

/**
 * Enum with all available TypeAdapter implementations.
 */
abstract class TypeAdapter {

  public static $enums = [
    'Mysql',
  ];

  /**
   * Checks if TypeAdapter is valid.
   *
   * @param string $c
   *   TypeAdapter.
   *
   * @return bool
   *   Whether TypeAdapter is valid.
   */
  public static function isValid($c) {
    return in_array($c, self::$enums, TRUE);
  }
}

/**
 * TypeAdapter Factory.
 */
abstract class TypeAdapterFactory {

  protected $dbHandler;

  protected $dumpSettings = [];

  /**
   * Creation function for default TypeAdapterFactory.
   *
   * @param string $c
   *   Type of database factory to create (Mysql).
   * @param string $dbHandler
   *   Handler for db connection.
   * @param array $dumpSettings
   *   Dump settings.
   *
   * @return mixed
   *   Returns result of handler.
   *
   * @throws \Exception
   */
  public static function create($c, $dbHandler = NULL, array $dumpSettings = []) {
    $c = ucfirst(strtolower($c));
    if (!TypeAdapter::isValid($c)) {
      throw new Exception("Database type support for ($c) not yet available");
    }
    $method = __NAMESPACE__ . "\\" . "TypeAdapter" . $c;
    return new $method($dbHandler, $dumpSettings);
  }

  /**
   * TypeAdapterFactory constructor.
   *
   * @param string $dbHandler
   *   Database connection handler.
   * @param array $dumpSettings
   *   Dump settings.
   */
  public function __construct($dbHandler = NULL, array $dumpSettings = []) {
    $this->dbHandler    = $dbHandler;
    $this->dumpSettings = $dumpSettings;
  }

}

/**
 * Class TypeAdapterMysql contains MySQL functions.
 *
 * @package Ifsnop\Mysqldump
 */
class TypeAdapterMysql extends TypeAdapterFactory {

  const DEFINER_RE = 'DEFINER=`(?:[^`]|``)*`@`(?:[^`]|``)*`';


  /**
   * Numerical Mysql types.
   */
  public $mysqlTypes = [
    'numerical' => [
      'bit',
      'tinyint',
      'smallint',
      'mediumint',
      'int',
      'integer',
      'bigint',
      'real',
      'double',
      'float',
      'decimal',
      'numeric',
    ],
    'blob'      => [
      'tinyblob',
      'blob',
      'mediumblob',
      'longblob',
      'binary',
      'varbinary',
      'bit',
      'geometry', /* http://bugs.mysql.com/bug.php?id=43544 */
      'point',
      'linestring',
      'polygon',
      'multipoint',
      'multilinestring',
      'multipolygon',
      'geometrycollection',
    ],
  ];

  public function databases() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args         = func_get_args();
    $databaseName = $args[0];

    $resultSet    = $this->dbHandler->query("SHOW VARIABLES LIKE 'character_set_database';");
    $characterSet = $resultSet->fetchColumn(1);
    $resultSet->closeCursor();

    $resultSet   = $this->dbHandler->query("SHOW VARIABLES LIKE 'collation_database';");
    $collationDb = $resultSet->fetchColumn(1);
    $resultSet->closeCursor();
    $ret = "";

    $ret .= "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `${databaseName}`" .
      " /*!40100 DEFAULT CHARACTER SET ${characterSet} " .
      " COLLATE ${collationDb} */;" . PHP_EOL . PHP_EOL .
      "USE `${databaseName}`;" . PHP_EOL . PHP_EOL;

    return $ret;
  }

  public function show_create_table($tableName) {
    return "SHOW CREATE TABLE `$tableName`";
  }

  public function show_create_view($viewName) {
    return "SHOW CREATE VIEW `$viewName`";
  }

  public function show_create_trigger($triggerName) {
    return "SHOW CREATE TRIGGER `$triggerName`";
  }

  public function show_create_procedure($procedureName) {
    return "SHOW CREATE PROCEDURE `$procedureName`";
  }

  public function show_create_event($eventName) {
    return "SHOW CREATE EVENT `$eventName`";
  }

  public function create_table($row) {
    if (!isset($row['Create Table'])) {
      throw new Exception("Error getting table code, unknown output");
    }

    $createTable = $row['Create Table'];
    if ($this->dumpSettings['reset-auto-increment']) {
      $match       = "/AUTO_INCREMENT=\d+/s";
      $replace     = "";
      $createTable = preg_replace($match, $replace, $createTable);
    }

    $ret = "/*!40101 SET @saved_cs_client     = @@character_set_client */;" . PHP_EOL .
      "/*!40101 SET character_set_client = " . $this->dumpSettings['default-character-set'] . " */;" . PHP_EOL .
      $createTable . ";" . PHP_EOL .
      "/*!40101 SET character_set_client = @saved_cs_client */;" . PHP_EOL .
      PHP_EOL;
    return $ret;
  }

  public function create_view($row) {
    $ret = "";
    if (!isset($row['Create View'])) {
      throw new Exception("Error getting view structure, unknown output");
    }

    $viewStmt = $row['Create View'];

    $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50013 \2 */' . PHP_EOL;

    if ($viewStmtReplaced = preg_replace(
      '/^(CREATE(?:\s+ALGORITHM=(?:UNDEFINED|MERGE|TEMPTABLE))?)\s+('
      . self::DEFINER_RE . '(?:\s+SQL SECURITY DEFINER|INVOKER)?)?\s+(VIEW .+)$/',
      '/*!50001 \1 */' . PHP_EOL . $definerStr . '/*!50001 \3 */',
      $viewStmt,
      1
    )) {
      $viewStmt = $viewStmtReplaced;
    };

    $ret .= $viewStmt . ';' . PHP_EOL . PHP_EOL;
    return $ret;
  }

  public function create_trigger($row) {
    $ret = "";
    if (!isset($row['SQL Original Statement'])) {
      throw new Exception("Error getting trigger code, unknown output");
    }

    $triggerStmt = $row['SQL Original Statement'];
    $definerStr  = $this->dumpSettings['skip-definer'] ? '' : '/*!50017 \2*/ ';
    if ($triggerStmtReplaced = preg_replace(
      '/^(CREATE)\s+(' . self::DEFINER_RE . ')?\s+(TRIGGER\s.*)$/s',
      '/*!50003 \1*/ ' . $definerStr . '/*!50003 \3 */',
      $triggerStmt,
      1
    )) {
      $triggerStmt = $triggerStmtReplaced;
    }

    $ret .= "DELIMITER ;;" . PHP_EOL .
      $triggerStmt . ";;" . PHP_EOL .
      "DELIMITER ;" . PHP_EOL . PHP_EOL;
    return $ret;
  }

  public function create_procedure($row) {
    $ret = "";
    if (!isset($row['Create Procedure'])) {
      throw new Exception("Error getting procedure code, unknown output. " .
        "Please check 'https://bugs.mysql.com/bug.php?id=14564'");
    }
    $procedureStmt = $row['Create Procedure'];

    $ret .= "/*!50003 DROP PROCEDURE IF EXISTS `" .
      $row['Procedure'] . "` */;" . PHP_EOL .
      "/*!40101 SET @saved_cs_client     = @@character_set_client */;" . PHP_EOL .
      "/*!40101 SET character_set_client = " . $this->dumpSettings['default-character-set'] . " */;" . PHP_EOL .
      "DELIMITER ;;" . PHP_EOL .
      $procedureStmt . " ;;" . PHP_EOL .
      "DELIMITER ;" . PHP_EOL .
      "/*!40101 SET character_set_client = @saved_cs_client */;" . PHP_EOL . PHP_EOL;

    return $ret;
  }

  public function create_event($row) {
    $ret = "";
    if (!isset($row['Create Event'])) {
      throw new Exception("Error getting event code, unknown output. " .
        "Please check 'http://stackoverflow.com/questions/10853826/mysql-5-5-create-event-gives-syntax-error'");
    }
    $eventName  = $row['Event'];
    $eventStmt  = $row['Create Event'];
    $sqlMode    = $row['sql_mode'];
    $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50117 \2*/ ';

    if ($eventStmtReplaced = preg_replace(
      '/^(CREATE)\s+(' . self::DEFINER_RE . ')?\s+(EVENT .*)$/',
      '/*!50106 \1*/ ' . $definerStr . '/*!50106 \3 */',
      $eventStmt,
      1
    )) {
      $eventStmt = $eventStmtReplaced;
    }

    $ret .= "/*!50106 SET @save_time_zone= @@TIME_ZONE */ ;" . PHP_EOL .
      "/*!50106 DROP EVENT IF EXISTS `" . $eventName . "` */;" . PHP_EOL .
      "DELIMITER ;;" . PHP_EOL .
      "/*!50003 SET @saved_cs_client      = @@character_set_client */ ;;" . PHP_EOL .
      "/*!50003 SET @saved_cs_results     = @@character_set_results */ ;;" . PHP_EOL .
      "/*!50003 SET @saved_col_connection = @@collation_connection */ ;;" . PHP_EOL .
      "/*!50003 SET character_set_client  = utf8 */ ;;" . PHP_EOL .
      "/*!50003 SET character_set_results = utf8 */ ;;" . PHP_EOL .
      "/*!50003 SET collation_connection  = utf8_general_ci */ ;;" . PHP_EOL .
      "/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;" . PHP_EOL .
      "/*!50003 SET sql_mode              = '" . $sqlMode . "' */ ;;" . PHP_EOL .
      "/*!50003 SET @saved_time_zone      = @@time_zone */ ;;" . PHP_EOL .
      "/*!50003 SET time_zone             = 'SYSTEM' */ ;;" . PHP_EOL .
      $eventStmt . " ;;" . PHP_EOL .
      "/*!50003 SET time_zone             = @saved_time_zone */ ;;" . PHP_EOL .
      "/*!50003 SET sql_mode              = @saved_sql_mode */ ;;" . PHP_EOL .
      "/*!50003 SET character_set_client  = @saved_cs_client */ ;;" . PHP_EOL .
      "/*!50003 SET character_set_results = @saved_cs_results */ ;;" . PHP_EOL .
      "/*!50003 SET collation_connection  = @saved_col_connection */ ;;" . PHP_EOL .
      "DELIMITER ;" . PHP_EOL .
      "/*!50106 SET TIME_ZONE= @save_time_zone */ ;" . PHP_EOL . PHP_EOL;

    return $ret;
  }

  public function show_tables() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "SELECT TABLE_NAME AS tbl_name " .
      "FROM INFORMATION_SCHEMA.TABLES " .
      "WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='${args[0]}'";
  }

  public function show_views() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "SELECT TABLE_NAME AS tbl_name " .
      "FROM INFORMATION_SCHEMA.TABLES " .
      "WHERE TABLE_TYPE='VIEW' AND TABLE_SCHEMA='${args[0]}'";
  }

  public function show_triggers() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "SHOW TRIGGERS FROM `${args[0]}`;";
  }

  public function show_columns() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "SHOW COLUMNS FROM `${args[0]}`;";
  }

  public function show_procedures() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "SELECT SPECIFIC_NAME AS procedure_name " .
      "FROM INFORMATION_SCHEMA.ROUTINES " .
      "WHERE ROUTINE_TYPE='PROCEDURE' AND ROUTINE_SCHEMA='${args[0]}'";
  }

  /**
   * Get query string to ask for names of events from current database.
   *
   * @param string Name of database
   *
   * @return string
   */
  public function show_events() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "SELECT EVENT_NAME AS event_name " .
      "FROM INFORMATION_SCHEMA.EVENTS " .
      "WHERE EVENT_SCHEMA='${args[0]}'";
  }

  public function setup_transaction() {
    return "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ";
  }

  public function start_transaction() {
    return "START TRANSACTION";
  }

  public function commit_transaction() {
    return "COMMIT";
  }

  public function lock_table() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return $this->dbHandler->exec("LOCK TABLES `${args[0]}` READ LOCAL");
  }

  public function unlock_table() {
    return $this->dbHandler->exec("UNLOCK TABLES");
  }

  public function start_add_lock_table() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "LOCK TABLES `${args[0]}` WRITE;" . PHP_EOL;
  }

  public function end_add_lock_table() {
    return "UNLOCK TABLES;" . PHP_EOL;
  }

  public function start_add_disable_keys() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "/*!40000 ALTER TABLE `${args[0]}` DISABLE KEYS */;" .
      PHP_EOL;
  }

  public function end_add_disable_keys() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "/*!40000 ALTER TABLE `${args[0]}` ENABLE KEYS */;" .
      PHP_EOL;
  }

  public function start_disable_autocommit() {
    return "SET autocommit=0;" . PHP_EOL;
  }

  public function end_disable_autocommit() {
    return "COMMIT;" . PHP_EOL;
  }

  public function add_drop_database() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "/*!40000 DROP DATABASE IF EXISTS `${args[0]}`*/;" .
      PHP_EOL . PHP_EOL;
  }

  public function add_drop_trigger() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "DROP TRIGGER IF EXISTS `${args[0]}`;" . PHP_EOL;
  }

  public function drop_table() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "DROP TABLE IF EXISTS `${args[0]}`;" . PHP_EOL;
  }

  public function drop_view() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "DROP TABLE IF EXISTS `${args[0]}`;" . PHP_EOL .
      "/*!50001 DROP VIEW IF EXISTS `${args[0]}`*/;" . PHP_EOL;
  }

  public function getDatabaseHeader() {
    $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
    $args = func_get_args();
    return "--" . PHP_EOL .
      "-- Current Database: `${args[0]}`" . PHP_EOL .
      "--" . PHP_EOL . PHP_EOL;
  }

  /**
   * Decode column metadata and fill info structure.
   *
   * Type, is_numeric and is_blob will always be available.
   *
   * @param array $colType
   *    Array returned from "SHOW COLUMNS FROM tableName".
   *
   * @return string
   *   Column type creation code.
   */
  public function parseColumnType($colType) {
    $colInfo  = [];
    $colParts = explode(" ", $colType['Type']);

    if ($fparen = strpos($colParts[0], "(")) {
      $colInfo['type']       = substr($colParts[0], 0, $fparen);
      $colInfo['length']     = str_replace(")", "", substr($colParts[0], $fparen + 1));
      $colInfo['attributes'] = isset($colParts[1]) ? $colParts[1] : NULL;
    }
    else {
      $colInfo['type'] = $colParts[0];
    }
    $colInfo['is_numeric'] = in_array($colInfo['type'], $this->mysqlTypes['numerical']);
    $colInfo['is_blob']    = in_array($colInfo['type'], $this->mysqlTypes['blob']);
    // for virtual columns that are of type 'Extra', column type
    // could by "STORED GENERATED" or "VIRTUAL GENERATED"
    // MySQL reference: https://dev.mysql.com/doc/refman/5.7/en/create-table-generated-columns.html
    $colInfo['is_virtual'] = strpos($colType['Extra'], "VIRTUAL GENERATED") !== FALSE || strpos($colType['Extra'], "STORED GENERATED") !== FALSE;

    return $colInfo;
  }

  public function backup_parameters() {
    $ret = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;" . PHP_EOL .
      "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;" . PHP_EOL .
      "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;" . PHP_EOL .
      "/*!40101 SET NAMES " . $this->dumpSettings['default-character-set'] . " */;" . PHP_EOL;

    if (FALSE === $this->dumpSettings['skip-tz-utc']) {
      $ret .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;" . PHP_EOL .
        "/*!40103 SET TIME_ZONE='+00:00' */;" . PHP_EOL;
    }

    $ret .= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;" . PHP_EOL .
      "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;" . PHP_EOL .
      "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;" . PHP_EOL .
      "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;" . PHP_EOL . PHP_EOL;

    return $ret;
  }

  /**
   * Restores parameters.
   *
   * @return string
   */
  public function restore_parameters() {
    $ret = '';

    if (FALSE === $this->dumpSettings['skip-tz-utc']) {
      $ret .= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;" . PHP_EOL;
    }

    $ret .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;" . PHP_EOL .
      "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;" . PHP_EOL .
      "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;" . PHP_EOL .
      "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;" . PHP_EOL .
      "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;" . PHP_EOL .
      "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;" . PHP_EOL .
      "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;" . PHP_EOL . PHP_EOL;

    return $ret;
  }

  /**
   * Check number of parameters passed to function, useful when inheriting.
   *
   * Raise exception if unexpected.
   *
   * @param integer $num_args
   *   Number of arguments passed.
   * @param integer $expected_num_args
   *   Number of args function requires.
   * @param string  $method_name
   *   Method being called.
   *
   * @throws \Exception
   */
  private function check_parameters($num_args, $expected_num_args, $method_name) {
    if ($num_args != $expected_num_args) {
      throw new Exception("Unexpected parameter passed to $method_name");
    }
  }
}
