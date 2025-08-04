<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Exception;

class SeedTemplateDatabase extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:seed-template {--fresh : Drop and recreate the database} {--reset : Reset data only} {--force : Force operation without confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Seed the template database with sample e-commerce data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->info('🚀 Starting template database seeding...');

            $fresh = $this->option('fresh');
            $reset = $this->option('reset');
            $force = $this->option('force');

            // Confirmation for destructive operations
            if (($fresh || $reset) && !$force) {
                if (!$this->confirm('This will modify/reset existing data. Continue?')) {
                    $this->warn('Operation cancelled.');
                    return self::FAILURE;
                }
            }

            if ($fresh) {
                $this->handleFreshDatabase();
            } elseif ($reset) {
                $this->handleResetData();
            } else {
                $this->handleRegularSeed();
            }

            $this->info('✅ Template database seeding completed successfully!');
            return self::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Error seeding template database: ' . $e->getMessage());
            Log::error('Template database seeding failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function handleFreshDatabase(): void
    {
        $this->info('🗑️  Dropping and recreating database...');
        
        $config = config('database.connections.template');
        $databaseName = $config['database'];
        
        $pdo = $this->createPDOConnection($config);
        
        // Drop and recreate database
        $pdo->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
        $pdo->exec("CREATE DATABASE `{$databaseName}`");
        $pdo->exec("USE `{$databaseName}`");
        
        $this->info('📊 Creating tables...');
        $this->createTables($pdo);
        
        $this->info('📝 Inserting sample data...');
        $this->insertSampleData($pdo);
        
        $this->info('🔄 Clearing cache...');
        $this->clearRelatedCache();
    }

    private function handleResetData(): void
    {
        $this->info('🔄 Resetting data only...');
        
        // Use Laravel's DB facade for this
        DB::connection('template')->statement('SET FOREIGN_KEY_CHECKS = 0');
        
        $tables = ['order_items', 'orders', 'products', 'users'];
        
        foreach ($tables as $table) {
            $this->info("📋 Truncating {$table}...");
            DB::connection('template')->statement("TRUNCATE TABLE `{$table}`");
        }
        
        DB::connection('template')->statement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->info('📝 Inserting fresh data...');
        $this->insertSampleDataLaravel();
        
        $this->info('🔄 Clearing cache...');
        $this->clearRelatedCache();
    }

    private function handleRegularSeed(): void
    {
        $this->info('🔍 Checking database state...');
        
        $config = config('database.connections.template');
        $databaseName = $config['database'];
        
        // Check if database exists
        $pdo = $this->createPDOConnection($config, false);
        
        try {
            $result = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$databaseName}'");
            $dbExists = $result->fetchColumn() !== false;
        } catch (Exception $e) {
            $dbExists = false;
        }
        
        if (!$dbExists) {
            $this->info('📊 Database does not exist. Creating...');
            $pdo->exec("CREATE DATABASE `{$databaseName}`");
        }
        
        $pdo->exec("USE `{$databaseName}`");
        
        $this->info('📊 Ensuring tables exist...');
        $this->createTables($pdo);
        
        // Check if data exists
        try {
            $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            if ($userCount > 0) {
                $this->warn("⚠️  Data already exists ({$userCount} users found).");
                
                if (!$this->confirm('Overwrite existing data?')) {
                    $this->info('Keeping existing data.');
                    return;
                }
                
                $this->handleResetData();
                return;
            }
        } catch (Exception $e) {
            // Tables might not exist, continue with seeding
        }
        
        $this->info('📝 Inserting sample data...');
        $this->insertSampleData($pdo);
    }

    private function createPDOConnection(array $config, bool $selectDatabase = true): PDO
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
        
        if ($selectDatabase && isset($config['database'])) {
            $dsn .= ";dbname={$config['database']}";
        }
        
        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    private function createTables(PDO $pdo): void
    {
        $tables = [
            'users' => "
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    email_verified_at TIMESTAMP NULL,
                    password VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            
            'products' => "
                CREATE TABLE IF NOT EXISTS products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    price DECIMAL(10, 2) NOT NULL,
                    stock_quantity INT DEFAULT 0,
                    category VARCHAR(100),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_category (category),
                    INDEX idx_price (price)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            
            'orders' => "
                CREATE TABLE IF NOT EXISTS orders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    total_amount DECIMAL(10, 2) NOT NULL,
                    status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
                    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    shipped_date TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_status (status),
                    INDEX idx_order_date (order_date),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            
            'order_items' => "
                CREATE TABLE IF NOT EXISTS order_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    order_id INT NOT NULL,
                    product_id INT NOT NULL,
                    quantity INT NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_order_id (order_id),
                    INDEX idx_product_id (product_id),
                    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            "
        ];

        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $tableName => $sql) {
            $pdo->exec($sql);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function insertSampleData(PDO $pdo): void
    {
        $pdo->beginTransaction();
        
        try {
            $steps = [
                'users' => [$this, 'insertUsers'],
                'products' => [$this, 'insertProducts'], 
                'orders' => [$this, 'insertOrders'],
                'order_items' => [$this, 'insertOrderItems']
            ];
            
            $bar = $this->output->createProgressBar(count($steps));
            $bar->start();
            
            foreach ($steps as $table => $method) {
                call_user_func($method, $pdo);
                $bar->advance();
            }
            
            $pdo->commit();
            $bar->finish();
            $this->newLine();
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
    }

    private function insertSampleDataLaravel(): void
    {
        $this->insertUsersLaravel();
        $this->insertProductsLaravel();
        $this->insertOrdersLaravel();
        $this->insertOrderItemsLaravel();
    }

    private function insertUsers(PDO $pdo): void
    {
        $users = [
            ['John Doe', 'john@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'],
            ['Jane Smith', 'jane@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'],
            ['Bob Johnson', 'bob@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'],
            ['Alice Brown', 'alice@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'],
            ['Charlie Wilson', 'charlie@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        
        foreach ($users as $user) {
            $stmt->execute($user);
        }
    }

    private function insertUsersLaravel(): void
    {
        $users = [
            ['name' => 'John Doe', 'email' => 'john@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Jane Smith', 'email' => 'jane@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bob Johnson', 'email' => 'bob@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Alice Brown', 'email' => 'alice@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Charlie Wilson', 'email' => 'charlie@example.com', 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'created_at' => now(), 'updated_at' => now()],
        ];
        
        DB::connection('template')->table('users')->insert($users);
    }

    private function insertProducts(PDO $pdo): void
    {
        $products = [
            ['Laptop', 'High-performance laptop for work and gaming', 999.99, 50, 'Electronics'],
            ['Smartphone', 'Latest model smartphone with advanced features', 699.99, 100, 'Electronics'],
            ['Headphones', 'Noise-cancelling wireless headphones', 199.99, 75, 'Electronics'],
            ['Coffee Maker', 'Programmable drip coffee maker', 89.99, 30, 'Appliances'],
            ['Desk Chair', 'Ergonomic office chair with lumbar support', 299.99, 20, 'Furniture'],
            ['Monitor', '27-inch 4K monitor', 449.99, 40, 'Electronics'],
            ['Keyboard', 'Mechanical gaming keyboard', 129.99, 60, 'Electronics'],
            ['Mouse', 'Wireless optical mouse', 39.99, 80, 'Electronics'],
            ['Desk Lamp', 'LED desk lamp with adjustable brightness', 59.99, 25, 'Furniture'],
            ['Water Bottle', 'Stainless steel insulated water bottle', 24.99, 150, 'Accessories']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO products (name, description, price, stock_quantity, category) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($products as $product) {
            $stmt->execute($product);
        }
    }

    private function insertProductsLaravel(): void
    {
        $products = [
            ['name' => 'Laptop', 'description' => 'High-performance laptop for work and gaming', 'price' => 999.99, 'stock_quantity' => 50, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Smartphone', 'description' => 'Latest model smartphone with advanced features', 'price' => 699.99, 'stock_quantity' => 100, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Headphones', 'description' => 'Noise-cancelling wireless headphones', 'price' => 199.99, 'stock_quantity' => 75, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Coffee Maker', 'description' => 'Programmable drip coffee maker', 'price' => 89.99, 'stock_quantity' => 30, 'category' => 'Appliances', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Desk Chair', 'description' => 'Ergonomic office chair with lumbar support', 'price' => 299.99, 'stock_quantity' => 20, 'category' => 'Furniture', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Monitor', 'description' => '27-inch 4K monitor', 'price' => 449.99, 'stock_quantity' => 40, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Keyboard', 'description' => 'Mechanical gaming keyboard', 'price' => 129.99, 'stock_quantity' => 60, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Mouse', 'description' => 'Wireless optical mouse', 'price' => 39.99, 'stock_quantity' => 80, 'category' => 'Electronics', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Desk Lamp', 'description' => 'LED desk lamp with adjustable brightness', 'price' => 59.99, 'stock_quantity' => 25, 'category' => 'Furniture', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Water Bottle', 'description' => 'Stainless steel insulated water bottle', 'price' => 24.99, 'stock_quantity' => 150, 'category' => 'Accessories', 'created_at' => now(), 'updated_at' => now()],
        ];
        
        DB::connection('template')->table('products')->insert($products);
    }

    private function insertOrders(PDO $pdo): void
    {
        $orders = [
            [1, 1299.98, 'delivered', '2024-01-15 10:30:00'],
            [2, 699.99, 'shipped', '2024-01-20 14:15:00'],
            [3, 459.97, 'processing', '2024-01-25 09:45:00'],
            [1, 89.99, 'pending', '2024-01-28 16:20:00'],
            [4, 824.97, 'delivered', '2024-02-01 11:10:00'],
            [5, 199.99, 'shipped', '2024-02-05 13:30:00'],
            [2, 349.98, 'processing', '2024-02-08 08:45:00'],
            [3, 64.98, 'delivered', '2024-02-10 15:20:00']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_amount, status, order_date) VALUES (?, ?, ?, ?)");
        
        foreach ($orders as $order) {
            $stmt->execute($order);
        }
    }

    private function insertOrdersLaravel(): void
    {
        $orders = [
            ['user_id' => 1, 'total_amount' => 1299.98, 'status' => 'delivered', 'order_date' => '2024-01-15 10:30:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'total_amount' => 699.99, 'status' => 'shipped', 'order_date' => '2024-01-20 14:15:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 3, 'total_amount' => 459.97, 'status' => 'processing', 'order_date' => '2024-01-25 09:45:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 1, 'total_amount' => 89.99, 'status' => 'pending', 'order_date' => '2024-01-28 16:20:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 4, 'total_amount' => 824.97, 'status' => 'delivered', 'order_date' => '2024-02-01 11:10:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 5, 'total_amount' => 199.99, 'status' => 'shipped', 'order_date' => '2024-02-05 13:30:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'total_amount' => 349.98, 'status' => 'processing', 'order_date' => '2024-02-08 08:45:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 3, 'total_amount' => 64.98, 'status' => 'delivered', 'order_date' => '2024-02-10 15:20:00', 'created_at' => now(), 'updated_at' => now()],
        ];
        
        DB::connection('template')->table('orders')->insert($orders);
    }

    private function insertOrderItems(PDO $pdo): void
    {
        $orderItems = [
            [1, 1, 1, 999.99], [1, 7, 1, 129.99], [1, 8, 1, 39.99], [1, 9, 1, 59.99],
            [2, 2, 1, 699.99], [3, 3, 1, 199.99], [3, 6, 1, 449.99], [4, 4, 1, 89.99],
            [5, 5, 1, 299.99], [5, 1, 1, 999.99], [6, 3, 1, 199.99], [7, 7, 1, 129.99],
            [7, 8, 1, 39.99], [7, 10, 8, 199.92], [8, 10, 2, 49.98], [8, 9, 1, 59.99]
        ];
        
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        
        foreach ($orderItems as $item) {
            $stmt->execute($item);
        }
    }

    private function insertOrderItemsLaravel(): void
    {
        $orderItems = [
            ['order_id' => 1, 'product_id' => 1, 'quantity' => 1, 'price' => 999.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 1, 'product_id' => 7, 'quantity' => 1, 'price' => 129.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 1, 'product_id' => 8, 'quantity' => 1, 'price' => 39.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 1, 'product_id' => 9, 'quantity' => 1, 'price' => 59.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 2, 'product_id' => 2, 'quantity' => 1, 'price' => 699.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 3, 'product_id' => 3, 'quantity' => 1, 'price' => 199.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 3, 'product_id' => 6, 'quantity' => 1, 'price' => 449.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 4, 'product_id' => 4, 'quantity' => 1, 'price' => 89.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 5, 'product_id' => 5, 'quantity' => 1, 'price' => 299.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 5, 'product_id' => 1, 'quantity' => 1, 'price' => 999.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 6, 'product_id' => 3, 'quantity' => 1, 'price' => 199.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 7, 'product_id' => 7, 'quantity' => 1, 'price' => 129.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 7, 'product_id' => 8, 'quantity' => 1, 'price' => 39.99, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 7, 'product_id' => 10, 'quantity' => 8, 'price' => 199.92, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 8, 'product_id' => 10, 'quantity' => 2, 'price' => 49.98, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => 8, 'product_id' => 9, 'quantity' => 1, 'price' => 59.99, 'created_at' => now(), 'updated_at' => now()],
        ];
        
        DB::connection('template')->table('order_items')->insert($orderItems);
    }

    private function clearRelatedCache(): void
    {
        $templateDatabase = config('database.connections.template.database');
        $cacheKeys = [
            "template_schema_full_{$templateDatabase}",
            "template_schema_{$templateDatabase}"
        ];
        
        foreach ($cacheKeys as $key) {
            cache()->forget($key);
        }
    }
}