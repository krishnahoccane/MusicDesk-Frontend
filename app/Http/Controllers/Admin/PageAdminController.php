<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageAdminController extends Controller
{
    public function index(): View
    {
        foreach (config('site.pages') as $slug => $title) {
            Page::firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => "# $title\n\nWrite this page in /admin/pages."]);
        }

        return view('admin.pages', ['pages' => Page::orderBy('id')->get()]);
    }

    public function edit(Page $page): View
    {
        return view('admin.page-edit', ['page' => $page]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $page->update($request->validate(['title' => 'required|string|max:120', 'body' => 'nullable|string|max:100000']) + ['published' => $request->boolean('published')]);

        return back()->with('toast', "{$page->title} saved");
    }
}
