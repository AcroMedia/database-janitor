<?php

namespace DatabaseJanitor;

require __DIR__ . '/../vendor/autoload.php';

use Ifsnop\Mysqldump\Mysqldump;

/**
 * Class DatabaseJanitor.
 *
 * @package DatabaseJanitor
 */
class DatabaseJanitor {

  private $password;

  private $host;

  private $user;

  private $database;

  private $dumpOptions;

  private $connection;

  /**
   * DatabaseJanitor constructor.
   */
  public function __construct($database, $user, $host, $password, $dumpOptions) {
    $this->database    = $database;
    $this->user        = $user;
    $this->host        = $host;
    $this->password    = $password;
    $this->dumpOptions = $dumpOptions;
    try {
      $this->connection = new \PDO('mysql:host=' . $this->host . ';dbname=' . $this->database, $this->user, $this->password, [
        \PDO::ATTR_PERSISTENT => TRUE,
      ]);
    }
    catch (\Exception $e) {
      echo $e;
    }
  }

  /**
   * Basic dumping.
   *
   * @return bool|string
   *   FALSE if dump encountered an error, otherwise return location of dump.
   */
  public function dump($host = FALSE, $output = FALSE) {
    if (!$output) {
      $output = 'php://stdout';
    }

    if ($host) {
      $this->database = $host->database;
      $this->user     = $host->user;
      $this->host     = $host->host;
      $this->password = $host->password;
    }

    $dumpSettings = [
      'add-locks'      => FALSE,
      'exclude-tables' => $this->dumpOptions['excluded_tables'] ?? [],
    ];
    try {
      $dump = new Mysqldump('mysql:host=' . $this->host . ';dbname=' . $this->database, $this->user, $this->password, $dumpSettings);
      $dump->setTransformColumnValueHook(function ($table_name, $col_name, $col_value) {
        return $this->sanitize($table_name, $col_name, $col_value, $this->dumpOptions);
      });
      $dump->start($output);
    }
    catch (\Exception $e) {
      echo 'mysqldump - php error: ' . $e->getMessage();
      return FALSE;
    }
    return $output;
  }

  /**
   * Replace values in specific table col with random value.
   *
   * @param string $table_name
   *   The current table's name.
   * @param string $col_name
   *   The current column name.
   * @param string $col_value
   *   The current value in the column.
   * @param array  $options
   *   Full configuration of tables to sanitize.
   *
   * @return string
   *   New col value.
   */
  public function sanitize($table_name, $col_name, $col_value, array $options) {
    if (isset($options['sanitize_tables'])) {
      foreach ($options['sanitize_tables'] as $table => $val) {
        if ($table == $table_name) {
          foreach ($options['sanitize_tables'][$table] as $col) {
            if ($col == $col_name) {
              // Generate value based on the type of the actual value.
              // Helps avoid breakage with incorrect types in cols.
              switch (gettype($col_value)) {
                case 'integer':
                case 'double':
                  return random_int(1000000, 9999999);

                  break;
                case 'string':
                  return (string) random_int(1000000, 9999999) . '-janitor';

                  break;

                default:
                  return $col_value;
              }
            }
          }
        }
      }
    }

    return $col_value;
  }

  /**
   * Removes every other row from specified table.
   *
   * @return array|bool
   *   FALSE if something goes wrong, otherwise array of removed items.
   */
  public function trim() {
    $ignore = [];
    if ($this->dumpOptions['trim_tables']) {
      foreach ($this->dumpOptions['trim_tables'] as $table) {
        // Skip table if not found.
        if (!$this->connection->query('SELECT 1 FROM ' . $table . ' LIMIT 1;')) {
          continue;
        }
        // Rename table and copy is over.
        $this->connection->exec('ALTER TABLE ' . $table . ' RENAME TO original_' . $table);
        $ignore[] = 'original_' . $table;
        // This makes assumptions about the primary key, should be configurable.
        $primary_key = $this->get_primary_key($table);
        if ($primary_key) {
          $keep = [];
          if (isset($this->dumpOptions['keep_rows'][$table])) {
            $keep = implode(',', $this->dumpOptions['keep_rows'][$table]);
          }
          $all = $this->connection->query('SELECT ' . $primary_key . ' FROM ' . $table)
            ->fetchAll();
          foreach ($all as $key => $row) {
            // Delete every other row.
            if ($key % 4 == 0) {
              $keep[] = $row[$primary_key];
            }
          }
          $keep = implode(',', $keep);
          $this->connection->exec('CREATE TABLE ' . $table . ' SELECT * FROM original_' . $table . 'WHERE ' . $key . ' IN (' . $keep . ')');
        }
      }
    }
    return $ignore;
  }

  /**
   * Completely scrub a table (aka truncate).
   *
   * @return array|bool
   *   Scrubbed tables with original_ appended for cleanup, false on error.
   */
  public function scrub() {
    $ignore = [];
    foreach ($this->dumpOptions['scrub_tables'] as $table) {
      $keep_rows = '';
      if (isset($this->dumpOptions['keep_rows'][$table])) {
        $keep_rows = implode(',', $this->dumpOptions['keep_rows'][$table]);
      }
      // Skip table if not found.
      if (!$this->connection->query('SELECT 1 FROM ' . $table . ' LIMIT 1')) {
        continue;
      }
      // Rename table and copy is over.
      $this->connection->exec('ALTER TABLE ' . $table . ' RENAME TO original_' . $table);
      $ignore[] = 'original_' . $table;
      $this->connection->exec('CREATE TABLE ' . $table . ' LIKE original_' . $table);
      if ($keep_rows) {
        $primary_key = $this->get_primary_key($table);
        if ($primary_key) {
          $this->connection->exec('INSERT INTO ' . $table . ' SELECT * FROM original_' . $table . ' WHERE ' . $primary_key . ' IN (' . $keep_rows . ')');
        }
      }
    }
    return $ignore;
  }

  /**
   * Post-run to rename the original tables back.
   *
   * @param array $tables
   *   Tables to rename, in the form original_X.
   *
   * @return bool
   *   False if error occurred, true otherwise.
   */
  public
  function cleanup(array $tables) {
    foreach ($tables as $table) {
      // Bit of a funky replace, but make sure we DO NOT alter the original
      // table name.
      $table = explode('_', $table);
      unset($table[0]);
      $table = implode('_', $table);

      $this->connection->exec('DROP TABLE ' . $table);
      $this->connection->exec('ALTER TABLE original_' . $table . ' RENAME TO ' . $table);
    }
    return TRUE;
  }

  private function get_primary_key($table) {
    $primary_key = $this->connection->query("SHOW KEYS FROM original_" . $table . " WHERE Key_name = 'PRIMARY'")
    ->fetch()['Column_name'];

      return $primary_key;
    }

}
