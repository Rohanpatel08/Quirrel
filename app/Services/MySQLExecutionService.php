<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Cache;

class MySQLExecutionService
{
    private $templateDatabase;
    private $executionHost;
    private $executionUsername;
    private $executionPassword;

    public function __construct()
    {
        $this->templateDatabase = config('database.connections.template.database');
        $this->executionHost = config('database.connections.execution.host');
        $this->executionUsername = config('database.connections.execution.username');
        $this->executionPassword = config('database.connections.execution.password');
    }

    public function executeWithIsolation(string $sqlQuery): array
    {
        $executionDatabase = $this->generateExecutionDatabase();

        try {
            // Create execution database
            $this->createExecutionDatabase($executionDatabase);

            // Copy template data
            $this->copyTemplateData($executionDatabase);

            // Execute query
            $result = $this->executeQuery($sqlQuery, $executionDatabase);

            return [
                'success' => true,
                'output' => $result,
                'database' => $executionDatabase,
            ];
        } catch (Exception $e) {
            Log::error('MySQL Execution Error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'output' => '',
                'database' => $executionDatabase,
            ];
        } finally {
            // Cleanup
            $this->cleanupExecutionDatabase($executionDatabase);
        }
    }

    private function generateExecutionDatabase(): string
    {
        return 'exec_' . time() . '_' . uniqid();
    }

    private function createExecutionDatabase(string $database): void
    {
        DB::statement("CREATE DATABASE `{$database}`");
        Log::info("Created execution database: {$database}");
    }

    private function copyTemplateData(string $executionDatabase): void
    {
        try {
            // Get template database structure
            $tables = DB::connection('template')->select('SHOW TABLES');
            $templateDbName = $this->templateDatabase;

            // Step 1: Create tables without foreign keys
            foreach ($tables as $table) {
                $tableName = $table->{"Tables_in_{$templateDbName}"};
                $this->createTableStructure($tableName, $executionDatabase);
            }

            // Step 2: Copy all data
            foreach ($tables as $table) {
                $tableName = $table->{"Tables_in_{$templateDbName}"};
                $this->copyTableData($tableName, $executionDatabase);
            }

            // Step 3: Add foreign key constraints
            foreach ($tables as $table) {
                $tableName = $table->{"Tables_in_{$templateDbName}"};
                $this->addForeignKeyConstraints($tableName, $executionDatabase);
            }

            Log::info("Copied template data to execution database: {$executionDatabase}");
        } catch (Exception $e) {
            Log::error("Error copying template data: " . $e->getMessage());
            throw $e;
        }
    }

    private function createTableStructure(string $tableName, string $executionDatabase): void
    {
        // Get table structure
        $createTableResult = DB::connection('template')->select("SHOW CREATE TABLE `{$tableName}`");
        $createTableSQL = $createTableResult[0]->{'Create Table'};

        // Remove foreign key constraints from CREATE TABLE statement
        $createTableSQL = $this->removeForeignKeyConstraints($createTableSQL);

        // Create table in execution database
        DB::statement("USE `{$executionDatabase}`");
        DB::statement($createTableSQL);
    }

    private function copyTableData(string $tableName, string $executionDatabase): void
    {
        // Copy data using INSERT SELECT
        DB::statement("
            INSERT INTO `{$executionDatabase}`.`{$tableName}` 
            SELECT * FROM `{$this->templateDatabase}`.`{$tableName}`
        ");
    }

    private function addForeignKeyConstraints(string $tableName, string $executionDatabase): void
    {
        try {
            // Get foreign key constraints from information_schema
            $constraints = DB::connection('template')->select("
                SELECT 
                    kcu.CONSTRAINT_NAME,
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE,
                    rc.UPDATE_RULE
                FROM information_schema.KEY_COLUMN_USAGE kcu
                JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME 
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? 
                AND kcu.TABLE_NAME = ? 
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ", [$this->templateDatabase, $tableName]);

            foreach ($constraints as $constraint) {
                $alterSql = "ALTER TABLE `{$executionDatabase}`.`{$tableName}` 
                           ADD CONSTRAINT `{$constraint->CONSTRAINT_NAME}` 
                           FOREIGN KEY (`{$constraint->COLUMN_NAME}`) 
                           REFERENCES `{$constraint->REFERENCED_TABLE_NAME}` (`{$constraint->REFERENCED_COLUMN_NAME}`)";

                if ($constraint->DELETE_RULE !== 'RESTRICT') {
                    $alterSql .= " ON DELETE {$constraint->DELETE_RULE}";
                }
                if ($constraint->UPDATE_RULE !== 'RESTRICT') {
                    $alterSql .= " ON UPDATE {$constraint->UPDATE_RULE}";
                }

                DB::statement($alterSql);
            }
        } catch (Exception $e) {
            Log::warning("Failed to add foreign key constraints for table {$tableName}: " . $e->getMessage());
        }
    }

    private function removeForeignKeyConstraints(string $createTableSQL): string
    {
        // Remove CONSTRAINT lines containing FOREIGN KEY
        $lines = explode("\n", $createTableSQL);
        $filteredLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Skip lines that contain FOREIGN KEY constraints
            if (strpos($trimmedLine, 'CONSTRAINT') !== false && strpos($trimmedLine, 'FOREIGN KEY') !== false) {
                continue;
            }

            // Also remove standalone FOREIGN KEY lines
            if (strpos($trimmedLine, 'FOREIGN KEY') !== false) {
                continue;
            }

            $filteredLines[] = $line;
        }

        $result = implode("\n", $filteredLines);

        // Clean up any trailing commas before closing parenthesis
        $result = preg_replace('/,(\s*\n\s*\))/', '$1', $result);

        return $result;
    }

    private function executeQuery(string $sqlQuery, string $database): string
    {
        // Set connection to execution database
        config(["database.connections.execution.database" => $database]);
        DB::purge('execution');

        // Execute query and capture output
        $queries = $this->splitQueries($sqlQuery);
        $output = '';

        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query)) continue;

            try {
                if (stripos($query, 'SELECT') === 0 || stripos($query, 'SHOW') === 0 || stripos($query, 'DESCRIBE') === 0) {
                    // For SELECT queries, format as table
                    $results = DB::connection('execution')->select($query);
                    $output .= $this->formatSelectResults($results, $query) . "\n\n";
                } else {
                    // For other queries, show affected rows
                    $affected = DB::connection('execution')->statement($query);
                    $output .= "Query executed successfully.\n";
                    if (is_numeric($affected)) {
                        $output .= "Affected rows: {$affected}\n";
                    }
                    $output .= "\n";
                }
            } catch (Exception $e) {
                $output .= "Error executing query: {$query}\n";
                $output .= "Error: " . $e->getMessage() . "\n\n";
            }
        }

        return $output;
    }

    private function formatSelectResults(array $results, string $query): string
    {
        if (empty($results)) {
            return "Query: {$query}\nNo results found.";
        }

        $output = "Query: {$query}\n";
        $output .= str_repeat('-', 50) . "\n";

        // Convert objects to arrays for easier handling
        $data = array_map(function ($item) {
            return (array) $item;
        }, $results);

        // Get column names
        $columns = array_keys($data[0]);

        // Calculate column widths
        $widths = [];
        foreach ($columns as $column) {
            $widths[$column] = max(strlen($column), 10);
            foreach ($data as $row) {
                $widths[$column] = max($widths[$column], strlen((string)$row[$column]));
            }
        }

        // Header
        $header = '| ';
        foreach ($columns as $column) {
            $header .= str_pad($column, $widths[$column]) . ' | ';
        }
        $output .= $header . "\n";

        // Separator
        $separator = '|-';
        foreach ($columns as $column) {
            $separator .= str_repeat('-', $widths[$column]) . '-|-';
        }
        $output .= $separator . "\n";

        // Data rows
        foreach ($data as $row) {
            $rowOutput = '| ';
            foreach ($columns as $column) {
                $rowOutput .= str_pad((string)$row[$column], $widths[$column]) . ' | ';
            }
            $output .= $rowOutput . "\n";
        }

        $output .= "\nTotal rows: " . count($results);

        return $output;
    }

    private function splitQueries(string $sql): array
    {
        // Simple query splitting (you might want to use a more sophisticated parser)
        $queries = array_filter(
            array_map('trim', explode(';', $sql)),
            function ($query) {
                return !empty($query);
            }
        );

        return $queries;
    }

    private function cleanupExecutionDatabase(string $database): void
    {
        try {
            DB::statement("DROP DATABASE IF EXISTS `{$database}`");
            Log::info("Cleaned up execution database: {$database}");
        } catch (Exception $e) {
            Log::error("Failed to cleanup execution database {$database}: " . $e->getMessage());
        }
    }

    public function getTemplateSchema(): array
    {
        try {
            $tables = DB::connection('template')->select('SHOW TABLES');
            $templateDbName = $this->templateDatabase;
            $schema = [];

            foreach ($tables as $table) {
                $tableName = $table->{"Tables_in_{$templateDbName}"};

                // Get columns
                $columns = DB::connection('template')->select("DESCRIBE `{$tableName}`");

                // Get sample data
                $sampleData = DB::connection('template')->select("SELECT * FROM `{$tableName}` LIMIT 3");

                $schema[$tableName] = [
                    'columns' => $columns,
                    'sample_data' => $sampleData,
                ];
            }

            return $schema;
        } catch (Exception $e) {
            Log::error('Error getting template schema: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear schema cache to force fresh retrieval
     */
    public function clearSchemaCache(): void
    {
        $cacheKey = "template_schema_full_" . $this->templateDatabase;
        Cache::forget($cacheKey);

        // Also clear any other related cache keys
        Cache::forget("template_schema_" . $this->templateDatabase);

        Log::info("Schema cache cleared");
    }
}
