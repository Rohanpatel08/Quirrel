<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE DATABASE IF NOT EXISTS template_db');

        // Switch to template database
        config(['database.connections.template.database' => 'template_db']);

        Schema::connection('template')->create('students', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 100)->unique();
            $table->integer('age');
            $table->char('grade', 1);
            $table->date('enrollment_date');
            $table->timestamps();
        });

        // Create courses table
        Schema::connection('template')->create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('course_name', 100);
            $table->string('course_code', 10)->unique();
            $table->integer('credits');
            $table->string('instructor', 100);
            $table->timestamps();
        });

        // Create enrollments table
        Schema::connection('template')->create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('course_id')->constrained('courses');
            $table->date('enrollment_date');
            $table->char('grade', 1)->nullable();
            $table->timestamps();
        });

        // Insert sample data
        $this->insertSampleData();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP DATABASE IF EXISTS template_db');
    }

    private function insertSampleData()
    {
        // Insert students
        DB::connection('template')->table('students')->insert([
            ['name' => 'Alice Johnson', 'email' => 'alice@email.com', 'age' => 20, 'grade' => 'A', 'enrollment_date' => '2023-09-01'],
            ['name' => 'Bob Smith', 'email' => 'bob@email.com', 'age' => 19, 'grade' => 'B', 'enrollment_date' => '2023-09-01'],
            ['name' => 'Charlie Brown', 'email' => 'charlie@email.com', 'age' => 21, 'grade' => 'B', 'enrollment_date' => '2023-09-02'],
            ['name' => 'Diana Prince', 'email' => 'diana@email.com', 'age' => 18, 'grade' => 'A', 'enrollment_date' => '2023-09-03'],
            ['name' => 'Eve Wilson', 'email' => 'eve@email.com', 'age' => 22, 'grade' => 'C', 'enrollment_date' => '2023-09-01'],
        ]);

        // Insert courses
        DB::connection('template')->table('courses')->insert([
            ['course_name' => 'Database Systems', 'course_code' => 'CS301', 'credits' => 3, 'instructor' => 'Dr. Anderson'],
            ['course_name' => 'Web Development', 'course_code' => 'CS201', 'credits' => 4, 'instructor' => 'Prof. Johnson'],
            ['course_name' => 'Data Structures', 'course_code' => 'CS102', 'credits' => 3, 'instructor' => 'Dr. Smith'],
            ['course_name' => 'Machine Learning', 'course_code' => 'CS401', 'credits' => 4, 'instructor' => 'Prof. Davis'],
        ]);

        // Insert enrollments
        DB::connection('template')->table('enrollments')->insert([
            ['student_id' => 1, 'course_id' => 1, 'enrollment_date' => '2023-09-05', 'grade' => 'A'],
            ['student_id' => 1, 'course_id' => 2, 'enrollment_date' => '2023-09-05', 'grade' => 'B'],
            ['student_id' => 2, 'course_id' => 1, 'enrollment_date' => '2023-09-06', 'grade' => 'B'],
            ['student_id' => 2, 'course_id' => 3, 'enrollment_date' => '2023-09-06', 'grade' => 'A'],
            ['student_id' => 3, 'course_id' => 2, 'enrollment_date' => '2023-09-07', 'grade' => 'C'],
            ['student_id' => 3, 'course_id' => 4, 'enrollment_date' => '2023-09-07', 'grade' => 'B'],
            ['student_id' => 4, 'course_id' => 1, 'enrollment_date' => '2023-09-08', 'grade' => 'A'],
            ['student_id' => 4, 'course_id' => 4, 'enrollment_date' => '2023-09-08', 'grade' => 'A'],
            ['student_id' => 5, 'course_id' => 3, 'enrollment_date' => '2023-09-09', 'grade' => 'C'],
        ]);
    }
};

