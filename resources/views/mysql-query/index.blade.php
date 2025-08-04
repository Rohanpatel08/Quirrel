<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MySQL Query Executor - Laravel</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/sql/sql.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/dracula.min.css">
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">🗄️ MySQL Query Executor</h1>
            <p class="text-gray-600 mb-2">Execute MySQL queries with individual database isolation</p>
            <p style="color: red" class="mb-3" id="error-message"><strong>Note:</strong> Refreshing the page will reset the
                database.</p>

            <!-- Status Indicators -->
            <div class="flex space-x-4">
                {{-- <div class="flex items-center">
                    <div class="w-3 h-3 rounded-full {{ $judge0Connected ? 'bg-green-500' : 'bg-red-500' }} mr-2"></div>
                    <span class="text-sm">Judge0 API: {{ $judge0Connected ? 'Connected' : 'Disconnected' }}</span>
                </div> --}}
                <div class="flex items-center">
                    <div class="w-3 h-3 rounded-full {{ !empty($schema) ? 'bg-green-500' : 'bg-red-500' }} mr-2"></div>
                    <span class="text-sm">Template DB: {{ !empty($schema) ? 'Ready' : 'Not Ready' }}</span>
                </div>
            </div>
        </div>

        <!-- Database Schema -->
        @if (!empty($schema))
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-xl font-bold text-gray-800 mb-4">📊 Database Schema</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    @foreach ($schema as $tableName => $tableInfo)
                        <div style="flex: 1" class="border border-gray-200 rounded-lg p-4">
                            <h3 class="font-semibold text-gray-700 mb-2">{{ $tableName }}</h3>
                            <div class="space-y-1">
                                @foreach ($tableInfo['columns'] as $column)
                                    <div class="text-sm text-gray-600">
                                        <span class="font-mono">{{ $column->Field }}</span>
                                        <span class="text-gray-400 text-sm break-words">
                                            {{ $column->Type }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Example Queries -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">🔍 Example Queries</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="example-query border border-gray-200 rounded-lg p-4 cursor-pointer hover:bg-gray-50"
                    onclick="setQuery('SELECT * FROM users;')">
                    <h3 class="font-semibold text-gray-700 mb-2">📋 Show All Users</h3>
                    <code class="text-sm text-blue-600">SELECT * FROM users;</code>
                </div>

                <div class="example-query border border-gray-200 rounded-lg p-4 cursor-pointer hover:bg-gray-50"
                    onclick="setQuery('SELECT u.name, o.total_amount, o.status\\nFROM users u\\nJOIN orders o ON u.id = o.user_id\\nORDER BY o.order_date DESC;')">
                    <h3 class="font-semibold text-gray-700 mb-2">🛍️ User Orders</h3>
                    <code class="text-sm text-blue-600">SELECT u.name, o.total_amount, o.status FROM users u JOIN orders
                        o...</code>
                </div>

                <div class="example-query border border-gray-200 rounded-lg p-4 cursor-pointer hover:bg-gray-50"
                    onclick="setQuery('SELECT p.name, p.category, SUM(oi.quantity) as total_sold\\nFROM products p\\nJOIN order_items oi ON p.id = oi.product_id\\nGROUP BY p.id, p.name, p.category\\nORDER BY total_sold DESC;')">
                    <h3 class="font-semibold text-gray-700 mb-2">🔥 Best Selling Products</h3>
                    <code class="text-sm text-blue-600">SELECT p.name, p.category, SUM(oi.quantity) as total_sold FROM
                        products p...</code>
                </div>

                <div class="example-query border border-gray-200 rounded-lg p-4 cursor-pointer hover:bg-gray-50"
                    onclick="setQuery('SELECT status, COUNT(*) as order_count, SUM(total_amount) as total_value\\nFROM orders\\nGROUP BY status\\nORDER BY order_count DESC;')">
                    <h3 class="font-semibold text-gray-700 mb-2">📈 Order Status Summary</h3>
                    <code class="text-sm text-blue-600">SELECT status, COUNT(*) as order_count, SUM(total_amount) as
                        total_value FROM orders...</code>
                </div>

                <div class="example-query border border-gray-200 rounded-lg p-4 cursor-pointer hover:bg-gray-50"
                    onclick="setQuery('-- Insert new user\\nINSERT INTO users (name, email, password)\\nVALUES (\\'John Test\\', \\'john.test@example.com\\', \\'$2y$10$test\\');\\n\\n-- Show the new user\\nSELECT * FROM users WHERE email = \\'john.test@example.com\\';')">
                    <h3 class="font-semibold text-gray-700 mb-2">➕ Insert New User</h3>
                    <code class="text-sm text-blue-600">INSERT INTO users (name, email, password) VALUES... SELECT *
                        FROM users WHERE...</code>
                </div>
            </div>
        </div>

        <!-- Query Editor and Results -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Query Editor -->
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                <div class="bg-gray-800 text-white p-4">
                    <h3 class="text-lg font-semibold">SQL Query Editor</h3>
                </div>
                <div class="p-4">
                    <textarea id="sqlEditor"
                        placeholder="-- Write your MySQL query here -- Each execution creates a fresh database copy
                        SELECT * FROM students LIMIT 5;"></textarea>
                </div>
                <div class="bg-gray-50 p-4 border-t">
                    <div class="flex items-center space-x-4 mb-4">
                        <label class="flex items-center">
                            <input type="radio" name="execution_method" value="direct" checked class="mr-2" hidden>
                            <span hidden>Direct Execution</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="execution_method" value="judge0" class="mr-2"
                                {{ !$judge0Connected ? 'disabled' : '' }} hidden>
                            <span hidden>Judge0 API</span>
                        </label>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="executeQuery()"
                            class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition-colors">
                            ▶️ Execute Query
                        </button>
                        <button onclick="clearEditor()"
                            class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 transition-colors">
                            🗑️ Clear
                        </button>
                    </div>
                </div>
            </div>

            <!-- Results Panel -->
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                <div class="bg-gray-800 text-white p-4">
                    <h3 class="text-lg font-semibold">Query Results</h3>
                </div>
                <div class="p-4">
                    <div id="loading" class="hidden text-center py-8">
                        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                        <p class="mt-2 text-gray-600">Executing query...</p>
                    </div>
                    <pre id="results" class="bg-gray-100 p-4 rounded text-sm overflow-auto max-h-96">Ready to execute queries...

🔹 Each query runs in an isolated database
🔹 Changes don't affect other executions  
🔹 Fresh data for every run
🔹 Automatic cleanup after execution</pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize CodeMirror
        const editor = CodeMirror.fromTextArea(document.getElementById('sqlEditor'), {
            mode: 'text/x-mysql',
            theme: 'dracula',
            lineNumbers: true,
            autoCloseBrackets: true,
            matchBrackets: true,
            indentWithTabs: true,
            smartIndent: true,
            lineWrapping: true,
            height: '300px'
        });

        editor.setSize(null, '300px');

        // Set CSRF token for AJAX requests
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        function setQuery(query) {
            editor.setValue(query);
        }

        function clearEditor() {
            editor.setValue('');
            document.getElementById('results').textContent = 'Ready to execute queries...';
        }

        async function executeQuery() {
            const query = editor.getValue().trim();

            if (!query) {
                alert('Please enter a SQL query');
                return;
            }

            const executionMethod = document.querySelector('input[name="execution_method"]:checked').value;
            const loading = document.getElementById('loading');
            const results = document.getElementById('results');

            // Show loading
            loading.classList.remove('hidden');
            results.textContent = 'Executing query...';

            try {
                const response = await fetch('/mysql-query/execute', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        query: query,
                        execution_method: executionMethod
                    })
                });

                const data = await response.json();

                if (data.success) {
                    let output = data.output || 'Query executed successfully (no output)';

                    if (data.execution_time) {
                        output +=
                            `\n\n--- Execution Info ---\nExecution Time: ${data.execution_time}\nMethod: ${data.method}`;
                        if (data.database) {
                            output += `\nDatabase: ${data.database}`;
                        }
                    }

                    results.textContent = output;
                } else {
                    results.textContent = `❌ Error: ${data.error || 'Unknown error occurred'}`;
                }

            } catch (error) {
                console.error('Error:', error);
                results.textContent = `❌ Network Error: ${error.message}`;
            } finally {
                loading.classList.add('hidden');
            }
        }

        // Allow Ctrl+Enter to execute query
        editor.setOption('extraKeys', {
            'Ctrl-Enter': executeQuery
        });
    </script>
</body>

</html>
