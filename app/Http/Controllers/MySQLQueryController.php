<?php

namespace App\Http\Controllers;

use App\Services\MySQLExecutionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use App\Services\Judge0Service;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Exception;

class MySQLQueryController extends Controller
{
    private $judge0Service;
    private $mysqlService;

    public function __construct(Judge0Service $judge0Service, MySQLExecutionService $mysqlService)
    {
        $this->judge0Service = $judge0Service;
        $this->mysqlService = $mysqlService;
    }

    public function index(): View
    {
        try {
            // First, try to get the schema
            $schema = $this->mysqlService->getTemplateSchema();
            // $getDBName = $this->mysqlService->getDatabaseName();

            // If schema is empty, the database might not exist
            if (empty($schema)) {
                // $this->createTemplateDatabaseIfNotExists();
                Artisan::call('db:seed-template', ['--force' => true]);

                // Try to get schema again after database creation
                $schema = $this->mysqlService->getTemplateSchema();
            } else {
                // $this->resetTemplateDatabaseData();
                Artisan::call('db:seed-template', ['--reset' => true, '--force' => true]);
                // Clear cached schema to force fresh retrieval
                $this->mysqlService->clearSchemaCache();
                // Get fresh schema after reset
                $schema = $this->mysqlService->getTemplateSchema();
            }
            // $judge0Connected = $this->judge0Service->checkConnection();

            return view('mysql-query.index')->with([
                'schema' => $schema,
                'judge0Connected' => 'true',
            ]);
        } catch (Exception $e) {
            // Log the error for debugging
            Log::error('Database initialization failed: ' . $e->getMessage());

            // Return view with empty schema and error message
            $schema = [];
            $judge0Connected = false;

            return view('mysql-query.index', compact('schema', 'judge0Connected'))
                ->with('error', 'Database initialization failed. Please check your configuration.');
        }
    }

    public function executeQuery(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|max:10000',
            'execution_method' => 'required|in:judge0,direct',
        ]);

        $query = $request->input('query');
        $executionMethod = $request->input('execution_method');

        if ($executionMethod === 'judge0') {
            return $this->executeWithJudge0($query);
        } else {
            return $this->executeDirectly($query);
        }
    }

    private function executeWithJudge0(string $query): JsonResponse
    {
        $result = $this->judge0Service->executeQuery($query);

        return response()->json([
            'success' => $result['success'],
            'output' => $result['stdout'] ?: $result['stderr'],
            'error' => $result['success'] ? null : ($result['error'] ?? 'Execution failed'),
            'execution_time' => $result['execution_time'] ?? null,
            'memory_usage' => $result['memory_usage'] ?? null,
            'method' => 'judge0',
        ]);
    }

    private function executeDirectly(string $query): JsonResponse
    {
        $startTime = microtime(true);
        $result = $this->mysqlService->executeWithIsolation($query);
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'success' => $result['success'],
            'output' => $result['output'] ?? '',
            'error' => $result['success'] ? null : ($result['error'] ?? 'Execution failed'),
            'execution_time' => $executionTime . 'ms',
            'database' => $result['database'] ?? null,
            'method' => 'direct',
        ]);
    }

    public function getSchema(): JsonResponse
    {
        $schema = $this->mysqlService->getTemplateSchema();

        return response()->json([
            'success' => true,
            'schema' => $schema,
        ]);
    }

    public function checkStatus(): JsonResponse
    {
        $judge0Connected = $this->judge0Service->checkConnection();
        $schema = $this->mysqlService->getTemplateSchema();

        return response()->json([
            'judge0_connected' => $judge0Connected,
            'template_database_ready' => !empty($schema),
            'available_tables' => array_keys($schema),
        ]);
    }

    public function cleanupDatabases(): JsonResponse
    {
        $result = $this->mysqlService->cleanupAllExecutionDatabases();

        return response()->json($result);
    }
    // private function createTemplateDatabaseIfNotExists(): void
    // {
    //     $config = config('database.connections.template');
    //     $databaseName = $config['database'];
    //     $pdo = new PDO(
    //         "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4",
    //         $config['username'],
    //         $config['password'],
    //         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    //     );
    //     $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}`");
    //     // Switch to the created database
    //     $pdo->exec("USE `{$databaseName}`");
    //     // Seed the database with tables and data
    //     $this->seedTemplateDatabase($pdo);
    // }
    // private function seedTemplateDatabase(PDO $pdo): void
    // {
    //     // Create users table
    //     $pdo->exec("
    //         CREATE TABLE IF NOT EXISTS users (
    //             id INT AUTO_INCREMENT PRIMARY KEY,
    //             name VARCHAR(255) NOT NULL,
    //             email VARCHAR(255) UNIQUE NOT NULL,
    //             email_verified_at TIMESTAMP NULL,
    //             password VARCHAR(255) NOT NULL,
    //             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    //             updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    //         )
    //     ");
    //     // Create products table
    //     $pdo->exec("
    //         CREATE TABLE IF NOT EXISTS products (
    //             id INT AUTO_INCREMENT PRIMARY KEY,
    //             name VARCHAR(255) NOT NULL,
    //             description TEXT,
    //             price DECIMAL(10, 2) NOT NULL,
    //             stock_quantity INT DEFAULT 0,
    //             category VARCHAR(100),
    //             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    //             updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    //         )
    //     ");
    //     // Create orders table
    //     $pdo->exec("
    //         CREATE TABLE IF NOT EXISTS orders (
    //             id INT AUTO_INCREMENT PRIMARY KEY,
    //             user_id INT NOT NULL,
    //             total_amount DECIMAL(10, 2) NOT NULL,
    //             status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    //             order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    //             shipped_date TIMESTAMP NULL,
    //             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    //             updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    //             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    //         )
    //     ");

    //     // Create order_items table for order-product relationship
    //     $pdo->exec("
    //         CREATE TABLE IF NOT EXISTS order_items (
    //             id INT AUTO_INCREMENT PRIMARY KEY,
    //             order_id INT NOT NULL,
    //             product_id INT NOT NULL,
    //             quantity INT NOT NULL,
    //             price DECIMAL(10, 2) NOT NULL,
    //             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    //             updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    //             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    //             FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    //         )
    //     ");

    //     // Check if data already exists
    //     $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    //     $userCount = $stmt->fetchColumn();

    //     if ($userCount == 0) {
    //         // Insert sample users
    //         $pdo->exec("
    //              INSERT INTO users (name, email, password) VALUES
    //              ('John Doe', 'john@example.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    //              ('Jane Smith', 'jane@example.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    //              ('Bob Johnson', 'bob@example.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    //              ('Alice Brown', 'alice@example.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    //              ('Charlie Wilson', 'charlie@example.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
    //          ");

    //         // Insert sample products
    //         $pdo->exec("
    //              INSERT INTO products (name, description, price, stock_quantity, category) VALUES
    //              ('Laptop', 'High-performance laptop for work and gaming', 999.99, 50, 'Electronics'),
    //              ('Smartphone', 'Latest model smartphone with advanced features', 699.99, 100, 'Electronics'),
    //              ('Headphones', 'Noise-cancelling wireless headphones', 199.99, 75, 'Electronics'),
    //              ('Coffee Maker', 'Programmable drip coffee maker', 89.99, 30, 'Appliances'),
    //              ('Desk Chair', 'Ergonomic office chair with lumbar support', 299.99, 20, 'Furniture'),
    //              ('Monitor', '27-inch 4K monitor', 449.99, 40, 'Electronics'),
    //              ('Keyboard', 'Mechanical gaming keyboard', 129.99, 60, 'Electronics'),
    //              ('Mouse', 'Wireless optical mouse', 39.99, 80, 'Electronics'),
    //              ('Desk Lamp', 'LED desk lamp with adjustable brightness', 59.99, 25, 'Furniture'),
    //              ('Water Bottle', 'Stainless steel insulated water bottle', 24.99, 150, 'Accessories')
    //          ");

    //         // Insert sample orders
    //         $pdo->exec("
    //              INSERT INTO orders (user_id, total_amount, status, order_date) VALUES
    //              (1, 1299.98, 'delivered', '2024-01-15 10:30:00'),
    //              (2, 699.99, 'shipped', '2024-01-20 14:15:00'),
    //              (3, 459.97, 'processing', '2024-01-25 09:45:00'),
    //              (1, 89.99, 'pending', '2024-01-28 16:20:00'),
    //              (4, 824.97, 'delivered', '2024-02-01 11:10:00'),
    //              (5, 199.99, 'shipped', '2024-02-05 13:30:00'),
    //              (2, 349.98, 'processing', '2024-02-08 08:45:00'),
    //              (3, 64.98, 'delivered', '2024-02-10 15:20:00')
    //          ");

    //         // Insert sample order items
    //         $pdo->exec("
    //              INSERT INTO order_items (order_id, product_id, quantity, price) VALUES
    //              (1, 1, 1, 999.99),
    //              (1, 7, 1, 129.99),
    //              (1, 8, 1, 39.99),
    //              (1, 9, 1, 59.99),
    //              (2, 2, 1, 699.99),
    //              (3, 3, 1, 199.99),
    //              (3, 6, 1, 449.99),
    //              (4, 4, 1, 89.99),
    //              (5, 5, 1, 299.99),
    //              (5, 1, 1, 999.99),
    //              (6, 3, 1, 199.99),
    //              (7, 7, 1, 129.99),
    //              (7, 8, 1, 39.99),
    //              (7, 10, 8, 199.92),
    //              (8, 10, 2, 49.98),
    //              (8, 9, 1, 59.99)
    //          ");
    //     }

    //     Log::info("Template database seeded successfully");
    // }

    // private function resetTemplateDatabaseData(): void
    // {
    //     try {
    //         // Disable foreign key checks
    //         DB::connection('template')->statement('SET FOREIGN_KEY_CHECKS = 0');

    //         // Get all tables
    //         $tables = DB::connection('template')->select('SHOW TABLES');
    //         $templateDbName = config('database.connections.template.database');

    //         // Truncate all tables
    //         foreach ($tables as $table) {
    //             $tableName = $table->{"Tables_in_{$templateDbName}"};
    //             DB::connection('template')->statement("TRUNCATE TABLE `{$tableName}`");
    //         }

    //         // Re-enable foreign key checks
    //         DB::connection('template')->statement('SET FOREIGN_KEY_CHECKS = 1');

    //         // Re-seed with fresh data
    //         $this->reseedTemplateDatabase();

    //         Log::info("Template database data reset to fresh state");
    //     } catch (Exception $e) {
    //         // Make sure to re-enable foreign key checks
    //         DB::connection('template')->statement('SET FOREIGN_KEY_CHECKS = 1');
    //         Log::error("Failed to reset template database data: " . $e->getMessage());
    //         throw $e;
    //     }
    // }

    /**
     * Re-seed template database with fresh data
     */
    //  private function reseedTemplateDatabase(): void
    //  {
    //      try {
    //          // Insert sample users
    //          DB::connection('template')->table('users')->insert([
    //              ['name' => 'John Doe', 'email' => 'john@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Jane Smith', 'email' => 'jane@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Bob Johnson', 'email' => 'bob@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Alice Brown', 'email' => 'alice@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Charlie Wilson', 'email' => 'charlie@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
    //          ]);

    //          // Insert sample products
    //          DB::connection('template')->table('products')->insert([
    //              ['name' => 'Laptop', 'description' => 'High-performance laptop for work and gaming', 'price' => 999.99, 'stock_quantity' => 50, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Smartphone', 'description' => 'Latest model smartphone with advanced features', 'price' => 699.99, 'stock_quantity' => 100, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Headphones', 'description' => 'Noise-cancelling wireless headphones', 'price' => 199.99, 'stock_quantity' => 75, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Coffee Maker', 'description' => 'Programmable drip coffee maker', 'price' => 89.99, 'stock_quantity' => 30, 'category' => 'Appliances', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Desk Chair', 'description' => 'Ergonomic office chair with lumbar support', 'price' => 299.99, 'stock_quantity' => 20, 'category' => 'Furniture', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Monitor', 'description' => '27-inch 4K monitor', 'price' => 449.99, 'stock_quantity' => 40, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Keyboard', 'description' => 'Mechanical gaming keyboard', 'price' => 129.99, 'stock_quantity' => 60, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Mouse', 'description' => 'Wireless optical mouse', 'price' => 39.99, 'stock_quantity' => 80, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Desk Lamp', 'description' => 'LED desk lamp with adjustable brightness', 'price' => 59.99, 'stock_quantity' => 25, 'category' => 'Furniture', 'created_at' => now(), 'updated_at' => now()],
    //              ['name' => 'Water Bottle', 'description' => 'Stainless steel insulated water bottle', 'price' => 24.99, 'stock_quantity' => 150, 'category' => 'Accessories', 'created_at' => now(), 'updated_at' => now()],
    //          ]);

    //          // Insert sample orders
    //          DB::connection('template')->table('orders')->insert([
    //              ['user_id' => 1, 'total_amount' => 1299.98, 'status' => 'delivered', 'order_date' => '2024-01-15 10:30:00', 'created_at' => now(), 'updated_at' => now()],
    //              ['user_id' => 2, 'total_amount' => 699.99, 'status' => 'shipped', 'order_date' => '2024-01-20 14:15:00', 'created_at' => now(), 'updated_at' => now()],
    //              ['user_id' => 3, 'total_amount' => 459.97, 'status' => 'processing', 'order_date' => '2024-01-25 09:45:00', 'created_at' => now(), 'updated_at' => now()],
    //              ['user_id' => 1, 'total_amount' => 89.99, 'status' => 'pending', 'order_date' => '2024-01-28 16:20:00', 'created_at' => now(), 'updated_at' => now()],
    //              ['user_id' => 4, 'total_amount' => 824.97, 'status' => 'delivered', 'order_date' => '2024-02-01 11:10:00', 'created_at' => now(), 'updated_at' => now()],
    //              ['user_id' => 5, 'total_amount' => 199.99, 'status' => 'shipped', 'order_date' => '2024-02-05 13:30:00', 'created_at' => now(), 'updated_at' => now()],
    //              ['user_id' => 2, 'total_amount' => 349.98, 'status' => 'processing', 'order_date' => '2024-02-08 08:45:00', 'created_at' => now(), 'updated_at' => now()],
    //              ['user_id' => 3, 'total_amount' => 64.98, 'status' => 'delivered', 'order_date' => '2024-02-10 15:20:00', 'created_at' => now(), 'updated_at' => now()],
    //          ]);

    //          // Insert sample order items
    //          DB::connection('template')->table('order_items')->insert([
    //              ['order_id' => 1, 'product_id' => 1, 'quantity' => 1, 'price' => 999.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 1, 'product_id' => 7, 'quantity' => 1, 'price' => 129.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 1, 'product_id' => 8, 'quantity' => 1, 'price' => 39.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 1, 'product_id' => 9, 'quantity' => 1, 'price' => 59.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 2, 'product_id' => 2, 'quantity' => 1, 'price' => 699.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 3, 'product_id' => 3, 'quantity' => 1, 'price' => 199.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 3, 'product_id' => 6, 'quantity' => 1, 'price' => 449.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 4, 'product_id' => 4, 'quantity' => 1, 'price' => 89.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 5, 'product_id' => 5, 'quantity' => 1, 'price' => 299.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 5, 'product_id' => 1, 'quantity' => 1, 'price' => 999.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 6, 'product_id' => 3, 'quantity' => 1, 'price' => 199.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 7, 'product_id' => 7, 'quantity' => 1, 'price' => 129.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 7, 'product_id' => 8, 'quantity' => 1, 'price' => 39.99, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 7, 'product_id' => 10, 'quantity' => 8, 'price' => 199.92, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 8, 'product_id' => 10, 'quantity' => 2, 'price' => 49.98, 'created_at' => now(), 'updated_at' => now()],
    //              ['order_id' => 8, 'product_id' => 9, 'quantity' => 1, 'price' => 59.99, 'created_at' => now(), 'updated_at' => now()],
    //          ]);

    //          Log::info("Template database re-seeded with fresh data");
    //      } catch (Exception $e) {
    //          Log::error("Failed to re-seed template database: " . $e->getMessage());
    //          throw $e;
    //      }
    //  }

}
