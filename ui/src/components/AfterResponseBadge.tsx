/**
 * Shown next to any event whose payload has `after_response: true`.
 *
 * The Laravel adapter sets that flag for every event fired from inside
 * an `Application::terminating()` callback — i.e. from
 * `dispatch_after_http_response_sent`, `dispatchAfterResponse`, or any
 * shutdown / terminate hook the app registers. Without this badge those
 * rows look identical to in-request work and confuse the eye.
 */
export function AfterResponseBadge() {
  return (
    <span
      class="pill ring-1 ring-inset bg-purple/10 text-purple ring-purple/30 normal-case shrink-0"
      title="Fired during Application::terminating() — after the HTTP response was sent."
    >
      after response
    </span>
  );
}
