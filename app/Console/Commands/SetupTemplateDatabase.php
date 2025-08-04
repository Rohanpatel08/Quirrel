<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MySQLExecutionService;
use Illuminate\Support\Facades\Log;

class SetupTemplateDatabase extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mysql:setup-template {--reset : Reset the existing template database}';

    /**
     * The console command description.
     */
    protected $description = 'Set up the MySQL template database with sample data';

    /**
     * Execute the console command.
     */
    public function handle(MySQLExecutionService $mysqlService): int
    {
        $this->info('Setting up MySQL template database...');

        try {
            if ($this->option('reset')) {
                $this->warn('Resetting template database...');

                if (!$this->confirm('This will delete all existing data in the template database. Continue?')) {
                    $this->info('Operation cancelled.');
                    return Command::SUCCESS;
                }

                $result = $mysqlService->resetTemplateDatabase();
                if ($result) {
                    $this->info('✅ Template database reset successfully!');
                } else {
                    $this->error('❌ Failed to reset template database.');
                    return Command::FAILURE;
                }
            } else {
                // Just get the schema - this will create the database and tables if they don't exist
                $schema = $mysqlService->getTemplateSchema();

                if (empty($schema)) {
                    $this->error('❌ Failed to set up template database.');
                    return Command::FAILURE;
                }

                $this->info('✅ Template database set up successfully!');
            }

            // Display database statistics
            $this->displayDatabaseInfo($mysqlService);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            Log::error('Template database setup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Display database information
     */
    private function displayDatabaseInfo(MySQLExecutionService $mysqlService): void
    {
        $this->info("\n" . str_repeat('=', 50));
        $this->info('DATABASE INFORMATION');
        $this->info(str_repeat('=', 50));

        try {
            $stats = $mysqlService->getDatabaseStats();
            $schema = $mysqlService->getTemplateSchema();

            if (!empty($stats)) {
                $this->info("Database: {$stats['database_name']}");
                $this->info("Total Tables: {$stats['total_tables']}");
                $this->info("Total Rows: {$stats['total_rows']}");
                $this->info("Database Size: {$stats['database_size']} MB");
            }

            if (!empty($schema)) {
                $this->info("\nTABLES:");
                $this->info(str_repeat('-', 30));

                foreach ($schema as $tableName => $tableInfo) {
                    $columnCount = count($tableInfo['columns']);
                    $rowCount = $tableInfo['row_count'] ?? 0;

                    $this->info("📋 {$tableName}:");
                    $this->info("   Columns: {$columnCount}");
                    $this->info("   Rows: {$rowCount}");

                    // Show column names
                    $columns = collect($tableInfo['columns'])->pluck('Field')->toArray();
                    $this->info("   Fields: " . implode(', ', $columns));
                    $this->info("");
                }
            }

            $this->info("Sample queries you can try:");
            $this->info("• SELECT * FROM users;");
            $this->info("• SELECT * FROM products WHERE price > 100;");
            $this->info("• SELECT u.name, p.name as product, o.quantity 
  FROM users u 
  JOIN orders o ON u.id = o.user_id 
  JOIN products p ON o.product_id = p.id;");
        } catch (\Exception $e) {
            $this->warn("Could not retrieve database information: " . $e->getMessage());
        }
    }
}
