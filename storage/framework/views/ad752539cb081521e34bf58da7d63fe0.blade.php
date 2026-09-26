---
name: livewire-development
description: "Use for any task or question involving Livewire. Activate if user mentions Livewire, wire: directives, or Livewire-specific concepts like wire:model, wire:click, wire:sort, or islands, invoke this skill. Covers building new components, debugging reactivity issues, real-time form validation, drag-and-drop, loading states, migrating from Livewire 3 to 4, converting component formats (SFC/MFC/class-based), and performance optimization. Do not use for non-Livewire reactive UI (React, Vue, Alpine-only, Inertia.js) or standard Laravel forms without Livewire."
license: MIT
metadata:
  author: laravel
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Livewire Development

## Documentation

Use ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ for detailed Livewire 4 patterns and documentation.

## Basic Usage

### Creating Components

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
# Single-file component (SFC - default in v4)
# Creates: resources/views/components/⚡create-post.blade.php
{{ $assist->artisanCommand('make:livewire create-post') }}

# Page component (SFC - Full Page in v4)
# Creates: resources/views/pages/⚡create-post.blade.php
{{ $assist->artisanCommand('make:livewire pages::create-post') }}

# Multi-file component (MFC)
# Creates: resources/views/components/⚡create-post/create-post.php
#          resources/views/components/⚡create-post/create-post.blade.php
{{ $assist->artisanCommand('make:livewire create-post --mfc') }}

# Class-based component (v3 style)
# Creates: app/Livewire/CreatePost.php AND resources/views/livewire/create-post.blade.php
{{ $assist->artisanCommand('make:livewire create-post --class') }}

# With namespace
{{ $assist->artisanCommand('make:livewire Posts/CreatePost') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

### Converting Between Formats

Use ___SINGLE_BACKTICK___{{ $assist->artisanCommand('livewire:convert create-post') }}___SINGLE_BACKTICK___ to convert between single-file, multi-file, and class-based formats.

### Choosing a Component Format

> **Always follow the project's existing conventions first.** Before creating any component, inspect the project's existing Livewire components to determine the established format (SFC, MFC, or class-based) and directory structure. Check ___SINGLE_BACKTICK___{{ $assist->appPath('Livewire/') }}___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___resources/views/components/___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___resources/views/livewire/___SINGLE_BACKTICK___ for existing components. If the project already uses a consistent format, **use that same format** — even if it differs from the Livewire v4 defaults below. Only fall back to the v4 defaults (SFC in ___SINGLE_BACKTICK___resources/views/components/___SINGLE_BACKTICK___) when no existing convention is established.

Also check ___SINGLE_BACKTICK___config/livewire.php___SINGLE_BACKTICK___ for ___SINGLE_BACKTICK___make_command.type___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___make_command.emoji___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___component_locations___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___component_namespaces___SINGLE_BACKTICK___ overrides, which change the default format and where files are stored.

### Component Format Reference

| Format | Flag | Class Path | View Path |
|--------|------|------------|-----------|
| Single-file (SFC) | default | — | ___SINGLE_BACKTICK___resources/views/components/⚡create-post.blade.php___SINGLE_BACKTICK___ (PHP + Blade in one file) |
| Full Page SFC | ___SINGLE_BACKTICK___pages::name___SINGLE_BACKTICK___ | — | ___SINGLE_BACKTICK___resources/views/pages/⚡create-post.blade.php___SINGLE_BACKTICK___ |
| Multi-file (MFC) | ___SINGLE_BACKTICK___--mfc___SINGLE_BACKTICK___ | ___SINGLE_BACKTICK___resources/views/components/⚡create-post/create-post.php___SINGLE_BACKTICK___ | ___SINGLE_BACKTICK___resources/views/components/⚡create-post/create-post.blade.php___SINGLE_BACKTICK___ |
| Class-based | ___SINGLE_BACKTICK___--class___SINGLE_BACKTICK___ | ___SINGLE_BACKTICK___{{ $assist->appPath('Livewire/CreatePost.php') }}___SINGLE_BACKTICK___ | ___SINGLE_BACKTICK___resources/views/livewire/create-post.blade.php___SINGLE_BACKTICK___ |
| View-based | default (Blade-only) | — | ___SINGLE_BACKTICK___resources/views/components/⚡create-post.blade.php___SINGLE_BACKTICK___ (Blade-only with functional state) |

> **Important:** The ⚡ prefix shown above is the **default** behavior in Livewire v4 — it is **configurable**. Check ___SINGLE_BACKTICK___config/livewire.php___SINGLE_BACKTICK___ for the ___SINGLE_BACKTICK___make_command.emoji___SINGLE_BACKTICK___ setting. When ___SINGLE_BACKTICK___true___SINGLE_BACKTICK___ (default), always include the ⚡ prefix in filenames you create. When ___SINGLE_BACKTICK___false___SINGLE_BACKTICK___, omit the ⚡ prefix from all paths above.

Namespaced components map to subdirectories: ___SINGLE_BACKTICK___make:livewire Posts/CreatePost___SINGLE_BACKTICK___ creates ___SINGLE_BACKTICK___resources/views/components/posts/⚡create-post.blade.php___SINGLE_BACKTICK___ (single-file by default). Use ___SINGLE_BACKTICK___make:livewire Posts/CreatePost --mfc___SINGLE_BACKTICK___ for multi-file output at ___SINGLE_BACKTICK___resources/views/components/posts/⚡create-post/create-post.php___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___resources/views/components/posts/⚡create-post/create-post.blade.php___SINGLE_BACKTICK___.

### Single-File Component Example

___BOOST_SNIPPET_0___

## Livewire 4 Specifics

### Key Changes From Livewire 3

These things changed in Livewire 4, but may not have been updated in this application. Verify this application's setup to ensure you follow existing conventions.

- Use ___SINGLE_BACKTICK___Route::livewire()___SINGLE_BACKTICK___ for full-page components (e.g., ___SINGLE_BACKTICK___Route::livewire('/posts/create', CreatePost::class)___SINGLE_BACKTICK___); config keys renamed: ___SINGLE_BACKTICK___layout___SINGLE_BACKTICK___ → ___SINGLE_BACKTICK___component_layout___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___lazy_placeholder___SINGLE_BACKTICK___ → ___SINGLE_BACKTICK___component_placeholder___SINGLE_BACKTICK___.
- ___SINGLE_BACKTICK___wire:model___SINGLE_BACKTICK___ now ignores child events by default (use ___SINGLE_BACKTICK___wire:model.deep___SINGLE_BACKTICK___ for old behavior); ___SINGLE_BACKTICK___wire:scroll___SINGLE_BACKTICK___ renamed to ___SINGLE_BACKTICK___wire:navigate:scroll___SINGLE_BACKTICK___.
- Component tags must be properly closed; ___SINGLE_BACKTICK___wire:transition___SINGLE_BACKTICK___ now uses View Transitions API (modifiers removed).
- JavaScript: ___SINGLE_BACKTICK___$wire.$js('name', fn)___SINGLE_BACKTICK___ → ___SINGLE_BACKTICK___$wire.$js.name = fn___SINGLE_BACKTICK___; ___SINGLE_BACKTICK___commit___SINGLE_BACKTICK___/___SINGLE_BACKTICK___request___SINGLE_BACKTICK___ hooks → ___SINGLE_BACKTICK___interceptMessage()___SINGLE_BACKTICK___/___SINGLE_BACKTICK___interceptRequest()___SINGLE_BACKTICK___.

### New Features

- Component formats: single-file (SFC), multi-file (MFC), view-based components.
- Islands (___SINGLE_BACKTICK___@island___SINGLE_BACKTICK___) for isolated updates; async actions (___SINGLE_BACKTICK___wire:click.async___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___#[Async]___SINGLE_BACKTICK___) for parallel execution.
- Deferred/bundled loading: ___SINGLE_BACKTICK___defer___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___lazy.bundle___SINGLE_BACKTICK___ for optimized component loading.

| Feature | Usage | Purpose |
|---------|-------|---------|
| Islands | ___SINGLE_BACKTICK___@island(name: 'stats')___SINGLE_BACKTICK___ | Isolated update regions |
| Async | ___SINGLE_BACKTICK___wire:click.async___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___#[Async]___SINGLE_BACKTICK___ | Non-blocking actions |
| Deferred | ___SINGLE_BACKTICK___defer___SINGLE_BACKTICK___ attribute | Load after page render |
| Bundled | ___SINGLE_BACKTICK___lazy.bundle___SINGLE_BACKTICK___ | Load multiple together |

### New Directives

- ___SINGLE_BACKTICK___wire:sort___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___wire:intersect___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___wire:ref___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___.renderless___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___.preserve-scroll___SINGLE_BACKTICK___ are available for use.
- ___SINGLE_BACKTICK___data-loading___SINGLE_BACKTICK___ attribute automatically added to elements triggering network requests.

| Directive | Purpose |
|-----------|---------|
| ___SINGLE_BACKTICK___wire:sort___SINGLE_BACKTICK___ | Drag-and-drop sorting |
| ___SINGLE_BACKTICK___wire:intersect___SINGLE_BACKTICK___ | Viewport intersection detection |
| ___SINGLE_BACKTICK___wire:ref___SINGLE_BACKTICK___ | Element references for JS |
| ___SINGLE_BACKTICK___.renderless___SINGLE_BACKTICK___ | Component without rendering |
| ___SINGLE_BACKTICK___.preserve-scroll___SINGLE_BACKTICK___ | Preserve scroll position |

## Best Practices

- Always use ___SINGLE_BACKTICK___wire:key___SINGLE_BACKTICK___ in loops
- Use ___SINGLE_BACKTICK___wire:loading___SINGLE_BACKTICK___ for loading states
- Use ___SINGLE_BACKTICK___wire:model.live___SINGLE_BACKTICK___ for instant updates (default is debounced)
- Validate and authorize in actions (treat like HTTP requests)

## Configuration

- ___SINGLE_BACKTICK___smart_wire_keys___SINGLE_BACKTICK___ defaults to ___SINGLE_BACKTICK___true___SINGLE_BACKTICK___; new configs: ___SINGLE_BACKTICK___component_locations___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___component_namespaces___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___make_command___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___csp_safe___SINGLE_BACKTICK___.

## Alpine & JavaScript

- ___SINGLE_BACKTICK___wire:transition___SINGLE_BACKTICK___ uses browser View Transitions API; ___SINGLE_BACKTICK___$errors___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___$intercept___SINGLE_BACKTICK___ magic properties available.
- Non-blocking ___SINGLE_BACKTICK___wire:poll___SINGLE_BACKTICK___ and parallel ___SINGLE_BACKTICK___wire:model.live___SINGLE_BACKTICK___ updates improve performance.

For interceptors and hooks, see [reference/javascript-hooks.md](reference/javascript-hooks.md).

## Testing

___BOOST_SNIPPET_1___

## Verification

1. Browser console: Check for JS errors
2. Network tab: Verify Livewire requests return 200
3. Ensure ___SINGLE_BACKTICK___wire:key___SINGLE_BACKTICK___ on all ___SINGLE_BACKTICK___@foreach___SINGLE_BACKTICK___ loops

## Common Pitfalls

- Missing ___SINGLE_BACKTICK___wire:key___SINGLE_BACKTICK___ in loops → unexpected re-rendering
- Expecting ___SINGLE_BACKTICK___wire:model___SINGLE_BACKTICK___ real-time → use ___SINGLE_BACKTICK___wire:model.live___SINGLE_BACKTICK___
- Unclosed component tags → syntax errors in v4
- Using deprecated config keys or JS hooks
- Including Alpine.js separately (already bundled in Livewire 4)
