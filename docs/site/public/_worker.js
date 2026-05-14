// Cloudflare Pages "advanced mode" worker. Lives at the deployment
// root so the Pages platform invokes it for every request, bypassing
// the default static-asset handler. Static delivery is restored by
// calling `env.ASSETS.fetch(request)` on the fall-through path.
//
// Why not _redirects? Cloudflare Pages' _redirects file does NOT
// support hostname-matched rules (see their unsupported-features
// table). The only way to bounce just the canonical *.pages.dev
// hostname to the custom domain — while leaving the custom-domain
// requests untouched — is a worker.

const CANONICAL_HOST = "periscope.thamibn.com";

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // Bounce *.pages.dev → canonical custom domain, preserving path + query.
    if (url.hostname !== CANONICAL_HOST && url.hostname.endsWith(".pages.dev")) {
      url.hostname = CANONICAL_HOST;
      return Response.redirect(url.toString(), 301);
    }

    // Custom-domain requests fall through to the static-asset handler
    // that Cloudflare Pages provides via env.ASSETS.
    return env.ASSETS.fetch(request);
  },
};
