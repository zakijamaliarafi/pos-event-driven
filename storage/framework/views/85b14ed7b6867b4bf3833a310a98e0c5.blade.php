---
name: echo-development
description: "Develops real-time broadcasting with Laravel Echo. Activates when setting up broadcasting (Reverb, Pusher, Ably); creating ShouldBroadcast events; defining broadcast channels (public, private, presence, encrypted); authorizing channels; configuring Echo; listening for events; implementing client events (whisper); setting up model broadcasting; broadcasting notifications; or when the user mentions broadcasting, Echo, WebSockets, real-time events, Reverb, or presence channels."
license: MIT
metadata:
  author: laravel
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Broadcasting & Echo

## When to Apply

Activate this skill when:

- Installing or configuring Laravel broadcasting (Reverb, Pusher, Ably)
- Creating events that implement ___SINGLE_BACKTICK___ShouldBroadcast___SINGLE_BACKTICK___
- Defining broadcast channels and authorization
- Setting up Laravel Echo on the client side
- Listening for broadcast events, notifications, or model events
- Implementing client-to-client events (whisper)
- Working with presence channels for user awareness

## Documentation

Use ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ for detailed broadcasting patterns and documentation.

## Basic Usage

### Installing Broadcasting

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('install:broadcasting') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Use flags for specific drivers: ___SINGLE_BACKTICK___--reverb___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___--pusher___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___--ably___SINGLE_BACKTICK___. This creates ___SINGLE_BACKTICK___config/broadcasting.php___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___routes/channels.php___SINGLE_BACKTICK___.

### Creating a Broadcast Event

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('make:event OrderShipped') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

___BOOST_SNIPPET_0___

Dispatch the event:

___BOOST_SNIPPET_1___

### Authorizing Channels

Define authorization in ___SINGLE_BACKTICK___routes/channels.php___SINGLE_BACKTICK___:

___BOOST_SNIPPET_2___

Create a channel class for complex authorization:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('make:channel OrderChannel') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

List all registered channels:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('channel:list') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

### Client-Side Setup

Install Echo and Pusher JS:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
npm install --save-dev laravel-echo pusher-js
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

___BOOST_SNIPPET_3___

### Listening for Events

___BOOST_SNIPPET_4___

### Running Required Processes

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('queue:work') }}    # Required for ShouldBroadcast events
{{ $assist->artisanCommand('reverb:start') }}  # Required for Reverb driver
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## What's Possible

Use ___SINGLE_BACKTICK___search-docs___SINGLE_BACKTICK___ to find detailed code examples and configuration for each of these:

### Channel Types

- Public (___SINGLE_BACKTICK___new Channel___SINGLE_BACKTICK___) — no auth, anyone can subscribe. Use for app-wide announcements, public feeds, or status pages.
- Private (___SINGLE_BACKTICK___new PrivateChannel___SINGLE_BACKTICK___) — requires authorization. Use for user-specific data like orders, messages, or account updates.
- Presence (___SINGLE_BACKTICK___new PresenceChannel___SINGLE_BACKTICK___) — authorized + tracks who's online. Use for chat rooms, collaborative editing, "who's viewing this" features, or typing indicators.
- EncryptedPrivate — end-to-end encryption, Pusher/Reverb only. Use when payload must be hidden from the broadcast server (e.g., sensitive financial data or private messages).
- Drivers: ___SINGLE_BACKTICK___reverb___SINGLE_BACKTICK___ (self-hosted WebSocket server), ___SINGLE_BACKTICK___pusher___SINGLE_BACKTICK___ (managed service), ___SINGLE_BACKTICK___ably___SINGLE_BACKTICK___ (managed service), ___SINGLE_BACKTICK___log___SINGLE_BACKTICK___ (writes to Laravel log, use for debugging), ___SINGLE_BACKTICK___null___SINGLE_BACKTICK___ (no-op, use for testing)

### Event Customization

- ___SINGLE_BACKTICK___broadcastAs()___SINGLE_BACKTICK___ — custom event name (client must use dot prefix: ___SINGLE_BACKTICK___.listen('.custom.name')___SINGLE_BACKTICK___). Use when you want stable API names decoupled from PHP class names, or shorter event names for the frontend.
- ___SINGLE_BACKTICK___broadcastWith()___SINGLE_BACKTICK___ — control exact payload. Use to avoid leaking sensitive model attributes, slim down large payloads, or add computed data not on the model.
- ___SINGLE_BACKTICK___broadcastWhen()___SINGLE_BACKTICK___ — conditional broadcasting. Use to skip broadcasting when changes are trivial (e.g., only broadcast order updates above a threshold, or skip unchanged fields).
- ___SINGLE_BACKTICK___broadcastQueue()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___$queue___SINGLE_BACKTICK___ — route to specific queue. Use to isolate real-time broadcasts from slow background jobs so they're processed faster.
- ___SINGLE_BACKTICK___$connection___SINGLE_BACKTICK___ — set queue connection per event. Use when broadcasts should go through a faster queue backend like Redis while other jobs use the database driver.

### Broadcasting Interfaces

- ___SINGLE_BACKTICK___ShouldBroadcast___SINGLE_BACKTICK___ — queue the broadcast (default). Use for most events to avoid blocking the HTTP response.
- ___SINGLE_BACKTICK___ShouldBroadcastNow___SINGLE_BACKTICK___ — broadcast synchronously, skip queue. Use during development or for time-critical events where queue latency is unacceptable.
- ___SINGLE_BACKTICK___ShouldDispatchAfterCommit___SINGLE_BACKTICK___ — wait for DB transaction commit. Use when the event references newly created records that listeners need to query (prevents race conditions).
- ___SINGLE_BACKTICK___ShouldRescue___SINGLE_BACKTICK___ — auto-catch broadcast exceptions. Use to prevent broadcast failures (e.g., WebSocket server down) from disrupting the user's HTTP request.
- ___SINGLE_BACKTICK___InteractsWithSockets___SINGLE_BACKTICK___ — required for ___SINGLE_BACKTICK___toOthers()___SINGLE_BACKTICK___. Use on any event where you want to exclude the sender (optimistic UI updates).
- ___SINGLE_BACKTICK___InteractsWithBroadcasting___SINGLE_BACKTICK___ — override driver per event via ___SINGLE_BACKTICK___broadcastVia()___SINGLE_BACKTICK___. Use in multi-driver setups (e.g., some events via Reverb, others via Pusher).

### Broadcasting Helpers

- ___SINGLE_BACKTICK___broadcast(new Event)->toOthers()___SINGLE_BACKTICK___ — exclude current user's socket. Use when the client already updates optimistically from the API response to avoid duplicate updates.
- ___SINGLE_BACKTICK___broadcast(new Event)->via('pusher')___SINGLE_BACKTICK___ — override connection. Use to route specific events through a different broadcast driver than the default.
- ___SINGLE_BACKTICK___Broadcast::on()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Broadcast::private()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Broadcast::presence()___SINGLE_BACKTICK___ — anonymous broadcasting without event classes. Chain ___SINGLE_BACKTICK___.as('name')->with($data)->send()___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___.sendNow()___SINGLE_BACKTICK___. Use for simple one-off broadcasts where creating a full event class is overkill (e.g., quick status updates, simple notifications).

### Channel Authorization

- Closure-based in ___SINGLE_BACKTICK___routes/channels.php___SINGLE_BACKTICK___ — use for simple authorization logic (e.g., checking ownership).
- Model binding: ___SINGLE_BACKTICK___Broadcast::channel('orders.{order}', fn (User $user, Order $order) => ...)___SINGLE_BACKTICK___ — use when authorization depends on the model instance (auto-resolves from route parameter).
- Channel classes via ___SINGLE_BACKTICK___{{ $assist->artisanCommand('make:channel') }}___SINGLE_BACKTICK___ — use for complex authorization logic that benefits from dependency injection or reusable logic across channels.
- Multiple guards: ___SINGLE_BACKTICK___['guards' => ['web', 'admin']]___SINGLE_BACKTICK___ — use when the channel should be accessible by users authenticated via different guards (e.g., both regular users and admins).

### Model Broadcasting

- ___SINGLE_BACKTICK___BroadcastsEvents___SINGLE_BACKTICK___ trait auto-broadcasts created/updated/deleted/trashed/restored. Use to automatically keep clients in sync with Eloquent model changes without writing individual events.
- Channel convention: ___SINGLE_BACKTICK___App.Models.Post.{id}___SINGLE_BACKTICK___ — clients subscribe to model-specific channels.
- ___SINGLE_BACKTICK___broadcastAs($event)___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___broadcastWith($event)___SINGLE_BACKTICK___ for per-action customization. Use to send different payloads for create vs update, or suppress certain event types.
- ___SINGLE_BACKTICK___newBroadcastableEvent($event)___SINGLE_BACKTICK___ for event instance customization (e.g., ___SINGLE_BACKTICK___->dontBroadcastToCurrentUser()___SINGLE_BACKTICK___). Use when you need to modify the underlying event object before it's dispatched.

### Client-Side Features

- Client events: ___SINGLE_BACKTICK___whisper()___SINGLE_BACKTICK___ / ___SINGLE_BACKTICK___listenForWhisper()___SINGLE_BACKTICK___ — peer-to-peer without server roundtrip (private/presence channels only). Use for typing indicators, cursor positions, or any ephemeral state that doesn't need server persistence.
- Presence channels: ___SINGLE_BACKTICK___Echo.join()___SINGLE_BACKTICK___ with ___SINGLE_BACKTICK___here()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___joining()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___leaving()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___error()___SINGLE_BACKTICK___ callbacks. Use for showing online users, "X is viewing this document" features, or live participant counts.
- Notification broadcasting: ___SINGLE_BACKTICK___.notification()___SINGLE_BACKTICK___ on user's private channel. Use to show real-time notifications (toast, badge counts) pushed from Laravel's notification system.
- Connection management: ___SINGLE_BACKTICK___Echo.connectionStatus()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Echo.leaveAllChannels()___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___Echo.disconnect()___SINGLE_BACKTICK___. Use to show connection indicators, clean up on logout, or handle offline/reconnect scenarios.
- Custom namespace: ___SINGLE_BACKTICK___new Echo({ namespace: 'App.Other.Namespace' })___SINGLE_BACKTICK___. Use when your events live outside the default ___SINGLE_BACKTICK___App\Events___SINGLE_BACKTICK___ namespace.

## Common Pitfalls

- Queue worker must be running for ___SINGLE_BACKTICK___ShouldBroadcast___SINGLE_BACKTICK___ events. Use ___SINGLE_BACKTICK___ShouldBroadcastNow___SINGLE_BACKTICK___ during development.
- ___SINGLE_BACKTICK___BROADCAST_CONNECTION___SINGLE_BACKTICK___ not ___SINGLE_BACKTICK___BROADCAST_DRIVER___SINGLE_BACKTICK___: Laravel 11+ renamed this env key.
- ___SINGLE_BACKTICK___toOthers()___SINGLE_BACKTICK___ requires ___SINGLE_BACKTICK___InteractsWithSockets___SINGLE_BACKTICK___ trait AND ___SINGLE_BACKTICK___X-Socket-ID___SINGLE_BACKTICK___ header. Echo auto-adds this to global Axios. For ___SINGLE_BACKTICK___fetch___SINGLE_BACKTICK___, manually send ___SINGLE_BACKTICK___Echo.socketId()___SINGLE_BACKTICK___.
- CORS: When frontend/backend are on different origins, add ___SINGLE_BACKTICK___broadcasting/auth___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___config/cors.php___SINGLE_BACKTICK___ paths and set ___SINGLE_BACKTICK___supports_credentials___SINGLE_BACKTICK___ to ___SINGLE_BACKTICK___true___SINGLE_BACKTICK___.
- Missing ___SINGLE_BACKTICK___VITE____SINGLE_BACKTICK___ prefix: Client-side env vars must start with ___SINGLE_BACKTICK___VITE____SINGLE_BACKTICK___.
- ___SINGLE_BACKTICK___channels.php___SINGLE_BACKTICK___ not loaded: Verify it's included in ___SINGLE_BACKTICK___withRouting()___SINGLE_BACKTICK___ in ___SINGLE_BACKTICK___bootstrap/app.php___SINGLE_BACKTICK___.
- Reverb is long-running: Code changes require ___SINGLE_BACKTICK___{{ $assist->artisanCommand('reverb:restart') }}___SINGLE_BACKTICK___.
- Presence channel auth must return an array of user data (___SINGLE_BACKTICK___['id' => $user->id, 'name' => $user->name]___SINGLE_BACKTICK___), not ___SINGLE_BACKTICK___true___SINGLE_BACKTICK___. Returning ___SINGLE_BACKTICK___true___SINGLE_BACKTICK___ silently fails.
- Dot prefix rule: When using ___SINGLE_BACKTICK___broadcastAs()___SINGLE_BACKTICK___, client must prefix with ___SINGLE_BACKTICK___.___SINGLE_BACKTICK___ (e.g., ___SINGLE_BACKTICK___.listen('.custom.name')___SINGLE_BACKTICK___). Without the dot, Echo looks for ___SINGLE_BACKTICK___App\Events\custom.name___SINGLE_BACKTICK___ which silently fails.
- Reverb host separation: ___SINGLE_BACKTICK___REVERB_SERVER_HOST___SINGLE_BACKTICK___/___SINGLE_BACKTICK___REVERB_SERVER_PORT___SINGLE_BACKTICK___ (internal bind) vs ___SINGLE_BACKTICK___REVERB_HOST___SINGLE_BACKTICK___/___SINGLE_BACKTICK___REVERB_PORT___SINGLE_BACKTICK___ (public address) vs ___SINGLE_BACKTICK___VITE_REVERB_HOST___SINGLE_BACKTICK___/___SINGLE_BACKTICK___VITE_REVERB_PORT___SINGLE_BACKTICK___ (client JS).
- Sanctum SPA auth: Ensure ___SINGLE_BACKTICK___/broadcasting/auth___SINGLE_BACKTICK___ uses ___SINGLE_BACKTICK___auth:sanctum___SINGLE_BACKTICK___ middleware and CSRF tokens are sent with ___SINGLE_BACKTICK___withCredentials: true___SINGLE_BACKTICK___.
