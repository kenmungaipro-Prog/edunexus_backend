<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Book;

class BookSeeder extends Seeder
{
    public function run(): void
    {
        $books = [
            ['title' => 'Physics Fundamentals',       'author' => 'H.C. Verma',       'category' => 'Science',      'total_copies' => 8,  'isbn' => '978-8177091878'],
            ['title' => 'Wings of Fire',              'author' => 'A.P.J. Abdul Kalam','category' => 'Biography',    'total_copies' => 12, 'isbn' => '978-8173711466'],
            ['title' => 'Mathematics - Class XII',    'author' => 'R.D. Sharma',       'category' => 'Mathematics',  'total_copies' => 6,  'isbn' => '978-8193663646'],
            ['title' => 'To Kill a Mockingbird',      'author' => 'Harper Lee',        'category' => 'Literature',   'total_copies' => 4],
            ['title' => 'The Alchemist',              'author' => 'Paulo Coelho',      'category' => 'Fiction',      'total_copies' => 7],
            ['title' => 'Organic Chemistry',          'author' => 'O.P. Tandon',       'category' => 'Science',      'total_copies' => 5,  'isbn' => '978-9350940648'],
            ['title' => 'Animal Farm',                'author' => 'George Orwell',     'category' => 'Literature',   'total_copies' => 6],
            ['title' => 'A Brief History of Time',    'author' => 'Stephen Hawking',   'category' => 'Science',      'total_copies' => 3],
            ['title' => 'Python Programming',         'author' => 'John Zelle',        'category' => 'Technology',   'total_copies' => 5],
            ['title' => 'Indian Polity',              'author' => 'M. Laxmikanth',     'category' => 'Social Studies','total_copies' => 8],
        ];

        foreach ($books as $i => $b) {
            $bookId = 'B-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            Book::updateOrCreate(
                ['book_id' => $bookId],
                array_merge(['available_copies' => $b['total_copies']], $b, ['book_id' => $bookId])
            );
        }
    }
}

