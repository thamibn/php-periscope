// Cloudflare Pages middleware — runs at the edge before any static asset
// is served. Bounces direct hits on the canonical *.pages.dev hostname
// to the configured custom domain. Requests for periscope.thamibn.com
// fall through to the static-site handler unchanged.
//
// Cloudflare's _redirects file does not support hostname-matched rules
// (confirmed in their docs), so a Pages Function is the canonical way
// to do this kind of cross-host bounce.

const CANONICAL = "periscope.thamibn.com";

export const onRequest = async ({ request, next }) => {
  const url = new URL(request.url);
  if (url.hostname !== CANONICAL && url.hostname.endsWith(".pages.dev")) {
    url.hostname = CANONICAL;
    return Response.redirect(url.toString(), 301);
  }
  return next();
};
