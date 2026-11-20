<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\View\View;

class PageController extends Controller
{
    public function legal(string $slug): View
    {
        $page = Page::query()->where('slug', $slug)->where('published', true)->firstOrFail();

        return view('marketing.page', ['page' => $page]);
    }

    public function contact(): View
    {
        return view('marketing.contact');
    }
}
